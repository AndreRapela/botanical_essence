<?php

declare(strict_types=1);

/** Recover disputes in durable, closed time windows without skipping concurrent creations. */
final class DisputeBackfillService
{
    private readonly Closure $persistDispute;
    private readonly Closure $clock;

    public function __construct(
        private readonly PDO $pdo,
        private readonly StripeGateway $stripe,
        callable $persistDispute,
        ?callable $clock = null
    ) {
        $this->persistDispute = Closure::fromCallable($persistDispute);
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
    }

    public function run(int $maxPages = 3): int
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Dispute recovery cannot run inside another transaction.');
        }
        $lockName = $this->lockName();
        if ($lockName !== null && !$this->acquireLock($lockName)) return 0;
        try {
            $synced = 0;
            for ($page = 0; $page < max(1, min($maxPages, 3)); $page++) {
                $cursor = $this->loadWindow();
                $response = $this->stripe->listDisputes(
                    (int) $cursor['created_gte'], $cursor['starting_after'], (int) $cursor['scan_created_lte']
                );
                $result = $this->persistPage($response, $cursor);
                $synced += $result['synced'];
                if (!$result['hasMore']) break;
            }
            return $synced;
        } finally {
            if ($lockName !== null) $this->releaseLock($lockName);
        }
    }

    private function loadWindow(): array
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $cursor = $this->pdo->query(
                'SELECT created_gte, starting_after, scan_created_lte FROM payment_dispute_sync_state WHERE id = 1' . $lock
            )->fetch(PDO::FETCH_ASSOC);
            if (!$cursor) throw new RuntimeException('The durable dispute recovery cursor is missing.');
            if ($cursor['scan_created_lte'] === null) {
                $upper = ($this->clock)();
                if (!is_int($upper) || $upper < (int) $cursor['created_gte'] || $upper > 4_294_967_295) {
                    throw new RuntimeException('The dispute recovery clock cannot define a valid window.');
                }
                // An unanchored legacy cursor cannot prove what time interval it scanned.
                // Replay its interval from the head, rather than trusting a partial cursor.
                $save = $this->pdo->prepare(
                    'UPDATE payment_dispute_sync_state SET scan_created_lte = :upper, starting_after = NULL WHERE id = 1'
                );
                $save->execute([':upper' => $upper]);
                $cursor['scan_created_lte'] = $upper;
                $cursor['starting_after'] = null;
            }
            $this->pdo->commit();
            return $cursor;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** A failed row replays its page; a stale worker may never overwrite a newer cursor. */
    private function persistPage(array $response, array $cursor): array
    {
        $data = $response['data'] ?? null;
        $hasMore = $response['has_more'] ?? null;
        $lower = (int) $cursor['created_gte'];
        $upper = $cursor['scan_created_lte'] ?? null;
        if ($upper === null || (int) $upper < $lower || $lower < 0
            || !is_array($data) || !array_is_list($data) || count($data) > 100 || !is_bool($hasMore)
            || ($hasMore && $data === [])) {
            throw new RuntimeException('Stripe returned an invalid dispute page; the recovery cursor was retained.');
        }
        $ids = [];
        foreach ($data as $dispute) {
            $id = is_array($dispute) ? ($dispute['id'] ?? null) : null;
            $created = is_array($dispute) ? ($dispute['created'] ?? null) : null;
            if (!is_string($id) || strlen($id) > 128 || preg_match('/^du_[A-Za-z0-9]+$/D', $id) !== 1
                || isset($ids[$id]) || !is_int($created) || $created < $lower || $created > (int) $upper) {
                throw new RuntimeException('Stripe returned a dispute outside the recovery window or with an invalid ID.');
            }
            $ids[$id] = true;
        }
        $lastId = $data === [] ? null : $data[array_key_last($data)]['id'];
        if ($hasMore && $lastId === $cursor['starting_after']) {
            throw new RuntimeException('Stripe dispute pagination did not advance; the recovery cursor was retained.');
        }

        foreach ($data as $dispute) {
            $this->pdo->beginTransaction();
            try {
                ($this->persistDispute)($dispute);
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $exception;
            }
        }

        // Always resume from the BEGINNING of this window, not from its completion
        // time. New disputes created during a slow multi-batch scan belong to the next
        // window. The overlap also tolerates short provider visibility/clock delays.
        $next = $hasMore
            ? ['created_gte' => $lower, 'starting_after' => $lastId, 'scan_created_lte' => (int) $upper]
            : ['created_gte' => max($lower, (int) $upper - 300), 'starting_after' => null, 'scan_created_lte' => null];
        $previousCursor = $cursor['starting_after'] === null ? 'starting_after IS NULL' : 'starting_after = :oldId';
        $save = $this->pdo->prepare(
            'UPDATE payment_dispute_sync_state
             SET created_gte = :nextLower, starting_after = :nextId, scan_created_lte = :nextUpper
             WHERE id = 1 AND created_gte = :oldLower AND scan_created_lte = :oldUpper
               AND ' . $previousCursor
        );
        $parameters = [
            ':nextLower' => $next['created_gte'], ':nextId' => $next['starting_after'], ':nextUpper' => $next['scan_created_lte'],
            ':oldLower' => $lower, ':oldUpper' => (int) $upper,
        ];
        if ($cursor['starting_after'] !== null) $parameters[':oldId'] = $cursor['starting_after'];
        $save->execute($parameters);
        if ($save->rowCount() !== 1) {
            throw new RuntimeException('The dispute recovery cursor changed; stale progress was not saved.');
        }
        return ['synced' => count($data), 'cursor' => $next, 'hasMore' => $hasMore];
    }

    private function lockName(): ?string
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return null;
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '') throw new RuntimeException('Dispute recovery requires a selected database.');
        return 'botanical-disputes-' . substr(hash('sha256', $database), 0, 40);
    }

    private function acquireLock(string $name): bool
    {
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $lock->execute([':name' => $name]);
        $result = $lock->fetchColumn();
        if ($result === null || $result === false) throw new RuntimeException('Could not acquire the dispute recovery lock.');
        return (int) $result === 1;
    }

    private function releaseLock(string $name): void
    {
        try {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute([':name' => $name]);
            if ((int) $release->fetchColumn() !== 1) error_log('The dispute recovery lock was not released explicitly.');
        } catch (Throwable) {
            // The worker also disconnects after each batch, releasing connection-owned locks.
            error_log('Could not explicitly release the dispute recovery lock.');
        }
    }
}
