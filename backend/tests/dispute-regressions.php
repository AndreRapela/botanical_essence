<?php

declare(strict_types=1);

function disputeRecoveryFixture(int $lower = 0, ?string $after = null, ?int $upper = null): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE payment_dispute_sync_state (id INTEGER PRIMARY KEY, created_gte INTEGER NOT NULL, starting_after TEXT NULL, scan_created_lte INTEGER NULL)');
    $pdo->exec('CREATE TABLE recorded_disputes (id TEXT PRIMARY KEY)');
    $pdo->prepare('INSERT INTO payment_dispute_sync_state VALUES (1, :lower, :after, :upper)')
        ->execute([':lower' => $lower, ':after' => $after, ':upper' => $upper]);
    return $pdo;
}

function disputeRecoveryCursor(PDO $pdo): array
{
    $row = $pdo->query('SELECT created_gte, starting_after, scan_created_lte FROM payment_dispute_sync_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    return ['created_gte' => (int) $row['created_gte'], 'starting_after' => $row['starting_after'],
        'scan_created_lte' => $row['scan_created_lte'] === null ? null : (int) $row['scan_created_lte']];
}

function recordRecoveryFixture(PDO $pdo, array $dispute): void
{
    $pdo->prepare('INSERT INTO recorded_disputes (id) VALUES (:id) ON CONFLICT(id) DO NOTHING')->execute([':id' => $dispute['id']]);
}

function disputeRecoveryGateway(callable $page): StripeGateway
{
    return new StripeGateway(static function (string $url) use ($page): array {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return ['body' => json_encode($page($query), JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK'];
    });
}

function expectDisputeRecoveryFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        assertTrueValue(str_contains($exception->getMessage(), $message));
        return;
    }
    throw new RuntimeException('Dispute recovery should have failed closed.');
}

function disputeRecoveryMySQL(): ?PDO
{
    $connection = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
    if (!mysqlIntegrationTestsCanMutateDatabase($connection)) {
        fwrite(STDOUT, "SKIP  Dispute recovery MySQL regression requires an explicitly disposable database.\n");
        return null;
    }
    return new PDO(
        'mysql:host=' . $connection[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $connection[1] . ';charset=utf8mb4',
        $connection[2], (string) getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

return [
    'long dispute scans retain their starting watermark and discover cases created during pagination' => static function (): void {
        $pdo = disputeRecoveryFixture();
        $now = 2000;
        $disputes = [['id' => 'du_boundary', 'created' => 2000], ['id' => 'du_head', 'created' => 1900], ['id' => 'du_old', 'created' => 500]];
        $requests = [];
        $gateway = disputeRecoveryGateway(static function (array $query) use (&$requests, &$disputes): array {
            $requests[] = $query;
            $rows = array_values(array_filter($disputes, static fn (array $row): bool =>
                $row['created'] >= (int) $query['created']['gte'] && $row['created'] <= (int) $query['created']['lte']));
            if (isset($query['starting_after'])) {
                $position = array_search($query['starting_after'], array_column($rows, 'id'), true);
                assertTrueValue($position !== false);
                $rows = array_slice($rows, $position + 1);
            }
            return ['data' => array_slice($rows, 0, 2), 'has_more' => count($rows) > 2];
        });
        $service = new DisputeBackfillService($pdo, $gateway, static fn (array $row) => recordRecoveryFixture($pdo, $row), static function () use (&$now): int { return $now; });
        withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_dispute_fixture'], static function () use ($pdo, $service, &$now, &$disputes, &$requests): void {
            assertSameValue(2, $service->run(1));
            assertSameValue(['created_gte' => 0, 'starting_after' => 'du_head', 'scan_created_lte' => 2000], disputeRecoveryCursor($pdo));
            $now = 5000; // A slow worker resumes long after the five-minute overlap.
            array_unshift($disputes, ['id' => 'du_during', 'created' => 3000]);
            assertSameValue(1, $service->run(1));
            assertSameValue(['created_gte' => 1700, 'starting_after' => null, 'scan_created_lte' => null], disputeRecoveryCursor($pdo));
            assertSameValue('2000', $requests[1]['created']['lte']);
            assertSameValue(3, $service->run());
            assertSameValue(4, (int) $pdo->query('SELECT COUNT(*) FROM recorded_disputes')->fetchColumn());
            assertSameValue('5000', $requests[2]['created']['lte']);
            assertSameValue('1700', $requests[2]['created']['gte']);
            assertSameValue(100, (int) $requests[2]['limit']);
        });
    },
    'failed dispute persistence retains its anchored page and retries every case idempotently' => static function (): void {
        $pdo = disputeRecoveryFixture(123, 'du_previous', 2000);
        $fail = true;
        $seen = [];
        $gateway = disputeRecoveryGateway(static fn (): array => ['data' => [['id' => 'du_first', 'created' => 1900], ['id' => 'du_second', 'created' => 1500]], 'has_more' => true]);
        $service = new DisputeBackfillService($pdo, $gateway, static function (array $row) use ($pdo, &$fail, &$seen): void {
            $seen[] = $row['id'];
            recordRecoveryFixture($pdo, $row);
            if ($fail && $row['id'] === 'du_second') throw new RuntimeException('Injected dispute persistence failure.');
        });
        withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_dispute_fixture'], static function () use ($pdo, $service, &$fail, &$seen): void {
            $before = disputeRecoveryCursor($pdo);
            expectDisputeRecoveryFailure($service->run(...), 'Injected dispute persistence failure.');
            assertSameValue($before, disputeRecoveryCursor($pdo));
            assertSameValue(false, $pdo->inTransaction());
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM recorded_disputes')->fetchColumn());
            $fail = false;
            assertSameValue(2, $service->run(1));
            assertSameValue(['du_first', 'du_second', 'du_first', 'du_second'], $seen);
            assertSameValue(['created_gte' => 123, 'starting_after' => 'du_second', 'scan_created_lte' => 2000], disputeRecoveryCursor($pdo));
        });
    },
    'provider failure preserves the durable dispute window across a worker restart' => static function (): void {
        $pdo = disputeRecoveryFixture();
        $fail = true;
        $requests = [];
        $gateway = new StripeGateway(static function (string $url) use (&$fail, &$requests): array {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $requests[] = $query;
            return $fail ? ['body' => false, 'statusLine' => '']
                : ['body' => '{"data":[],"has_more":false}', 'statusLine' => 'HTTP/1.1 200 OK'];
        });
        withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_dispute_fixture'], static function () use ($pdo, $gateway, &$fail, &$requests): void {
            $service = new DisputeBackfillService($pdo, $gateway, static fn (array $row) => recordRecoveryFixture($pdo, $row), static fn (): int => 2000);
            expectDisputeRecoveryFailure($service->run(...), 'payment provider');
            assertSameValue(2000, disputeRecoveryCursor($pdo)['scan_created_lte']);
            $fail = false;
            $restarted = new DisputeBackfillService($pdo, $gateway, static fn (array $row) => recordRecoveryFixture($pdo, $row), static fn (): int => 9000);
            assertSameValue(0, $restarted->run());
            assertSameValue($requests[0], $requests[1]);
            assertSameValue(1700, disputeRecoveryCursor($pdo)['created_gte']);
        });
    },
    'unanchored legacy dispute cursors replay from the head instead of skipping new cases' => static function (): void {
        $pdo = disputeRecoveryFixture(100, 'du_legacy');
        $gateway = disputeRecoveryGateway(static function (array $query): array {
            assertSameValue(false, isset($query['starting_after']));
            assertSameValue('100', $query['created']['gte']);
            return ['data' => [], 'has_more' => false];
        });
        withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_dispute_fixture'], static function () use ($pdo, $gateway): void {
            assertSameValue(0, (new DisputeBackfillService($pdo, $gateway, static fn () => null, static fn (): int => 1000))->run());
            assertSameValue(700, disputeRecoveryCursor($pdo)['created_gte']);
        });
    },
    'invalid dispute pages never advance recovery or silently drop a case' => static function (): void {
        foreach ([
            ['data' => [], 'has_more' => true], ['data' => [], 'has_more' => 'false'], ['has_more' => false],
            ['data' => [['id' => 'du_a', 'created' => 3001]], 'has_more' => false],
            ['data' => [['id' => 'du_a', 'created' => 99]], 'has_more' => false],
            ['data' => [['id' => 'du_a', 'created' => '1000']], 'has_more' => false],
            ['data' => [['id' => 'bad-id', 'created' => 1000]], 'has_more' => false],
            ['data' => [['id' => 'du_a', 'created' => 1000], ['id' => 'du_a', 'created' => 1000]], 'has_more' => true],
            ['data' => [['id' => 'du_previous', 'created' => 1000]], 'has_more' => true],
        ] as $page) {
            $pdo = disputeRecoveryFixture(100, 'du_previous', 3000);
            $before = disputeRecoveryCursor($pdo);
            $service = new DisputeBackfillService($pdo, disputeRecoveryGateway(static fn (): array => $page), static fn (array $row) => recordRecoveryFixture($pdo, $row));
            withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_dispute_fixture'], static function () use ($pdo, $before, $service): void {
                expectDisputeRecoveryFailure($service->run(...), 'Stripe');
                assertSameValue($before, disputeRecoveryCursor($pdo));
                assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM recorded_disputes')->fetchColumn());
            });
        }
    },
    'stale dispute workers cannot regress a cursor saved by another batch' => static function (): void {
        $pdo = disputeRecoveryFixture(100, 'du_newer', 3000);
        $service = new DisputeBackfillService($pdo, new StripeGateway(), static fn (array $row) => recordRecoveryFixture($pdo, $row));
        $before = disputeRecoveryCursor($pdo);
        $persist = new ReflectionMethod(DisputeBackfillService::class, 'persistPage');
        expectDisputeRecoveryFailure(static fn () => $persist->invoke($service,
            ['data' => [['id' => 'du_old', 'created' => 1000]], 'has_more' => true],
            ['created_gte' => 100, 'starting_after' => 'du_older', 'scan_created_lte' => 3000]), 'stale progress');
        assertSameValue($before, disputeRecoveryCursor($pdo));
    },
    'dispute recovery fails closed when its durable state is missing or its clock goes backwards' => static function (): void {
        $pdo = disputeRecoveryFixture(2000);
        $calls = 0;
        $gateway = disputeRecoveryGateway(static function () use (&$calls): array { $calls++; return ['data' => [], 'has_more' => false]; });
        $service = new DisputeBackfillService($pdo, $gateway, static fn () => null, static fn (): int => 1000);
        expectDisputeRecoveryFailure($service->run(...), 'clock');
        $pdo->exec('DELETE FROM payment_dispute_sync_state');
        expectDisputeRecoveryFailure($service->run(...), 'cursor is missing');
        assertSameValue(0, $calls);
        assertSameValue(false, $pdo->inTransaction());
    },
    'dispute schema recovers old gaps once and does not reset an ongoing anchored scan' => static function (): void {
        $pdo = disputeRecoveryMySQL();
        if ($pdo === null) return;
        assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
            AND table_name = 'payment_dispute_sync_state' AND column_name = 'scan_created_lte'")->fetchColumn());
        assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = '2026-10-07-dispute-backfill-window-v21'")->fetchColumn());
        // Simulate a crash after adding the column but before recording the migration.
        // Both tables are connection-local shadows; persistent state remains untouched.
        $pdo->exec('CREATE TEMPORARY TABLE schema_migrations (version VARCHAR(120) PRIMARY KEY)');
        $pdo->exec('CREATE TEMPORARY TABLE payment_dispute_sync_state (id INT PRIMARY KEY, created_gte INT NOT NULL, starting_after VARCHAR(128) NULL, scan_created_lte INT NULL)');
        $pdo->exec("INSERT INTO payment_dispute_sync_state VALUES (1, 500, 'du_legacy', 2000)");
        DisputeBackfillSchema::migrate($pdo);
        assertSameValue(['created_gte' => 0, 'starting_after' => null, 'scan_created_lte' => null], disputeRecoveryCursor($pdo));
        $pdo->exec("UPDATE payment_dispute_sync_state SET created_gte = 900, starting_after = 'du_resume', scan_created_lte = 2000");
        $before = disputeRecoveryCursor($pdo);
        DisputeBackfillSchema::migrate($pdo);
        assertSameValue($before, disputeRecoveryCursor($pdo));
    },
    'MySQL dispute recovery excludes concurrent workers and releases its lock after provider failure' => static function (): void {
        $holder = disputeRecoveryMySQL();
        if ($holder === null) return;
        $contender = disputeRecoveryMySQL();
        $contender->exec('CREATE TEMPORARY TABLE payment_dispute_sync_state (id INT PRIMARY KEY, created_gte INT NOT NULL, starting_after VARCHAR(128) NULL, scan_created_lte INT NULL)');
        $contender->exec('INSERT INTO payment_dispute_sync_state VALUES (1, 0, NULL, NULL)');
        $name = 'botanical-disputes-' . substr(hash('sha256', (string) $holder->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
        $lock = $holder->prepare('SELECT GET_LOCK(:name, 0)');
        $lock->execute([':name' => $name]);
        assertSameValue(1, (int) $lock->fetchColumn());
        $calls = 0;
        $gateway = disputeRecoveryGateway(static function () use (&$calls): array { $calls++; return ['data' => [], 'has_more' => false]; });
        $service = new DisputeBackfillService($contender, $gateway, static fn () => null, static fn (): int => 2000);
        try {
            assertSameValue(0, $service->run());
            assertSameValue(0, $calls);
            assertSameValue(null, disputeRecoveryCursor($contender)['scan_created_lte']);
        } finally {
            $holder->prepare('SELECT RELEASE_LOCK(:name)')->execute([':name' => $name]);
        }
        withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_dispute_fixture'], static function () use ($holder, $contender, $name, $service): void {
            assertSameValue(0, $service->run());
            assertSameValue(1700, disputeRecoveryCursor($contender)['created_gte']);
            $broken = new DisputeBackfillService($contender, new StripeGateway(static fn (): array => ['body' => false, 'statusLine' => '']), static fn () => null, static fn (): int => 3000);
            expectDisputeRecoveryFailure($broken->run(...), 'payment provider');
            $lock = $holder->prepare('SELECT GET_LOCK(:name, 0)');
            $lock->execute([':name' => $name]);
            try { assertSameValue(1, (int) $lock->fetchColumn()); }
            finally { $holder->prepare('SELECT RELEASE_LOCK(:name)')->execute([':name' => $name]); }
        });
    },
];
