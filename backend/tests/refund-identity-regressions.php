<?php

declare(strict_types=1);

/** Real webhook and admin application paths; Stripe transport is not needed. */
final class PartialRefundIdentityHarness
{
    use PaymentWebhookActions;
    use PartialRefundActions;

    private readonly object $operations;

    public function __construct(private readonly PDO $pdo)
    {
        $this->operations = new class {
            public function dispatchOutbox(int $limit): void {}
        };
    }

    public function applyAdminResult(array $refund): array
    {
        return $this->applyPartialRefundResult($this->pdo->query('SELECT * FROM orders WHERE id = 1')->fetch(), $refund, 42);
    }

    private function refundAttemptRow(int $orderId, int $attempt): ?array
    {
        $find = $this->pdo->prepare('SELECT * FROM payment_refund_attempts WHERE order_id = :id AND attempt = :attempt');
        $find->execute([':id' => $orderId, ':attempt' => $attempt]);
        return $find->fetch() ?: null;
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}

function refundIdentityDatabase(string $status = 'pending', ?string $resolution = null, bool $bound = true): ?PDO
{
    $connection = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
    if (!mysqlIntegrationTestsCanMutateDatabase($connection)) {
        fwrite(STDOUT, "SKIP  Refund identity regression requires an explicitly disposable MySQL database.\n");
        return null;
    }
    $pdo = new PDO(
        'mysql:host=' . $connection[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $connection[1] . ';charset=utf8mb4',
        $connection[2], (string) getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("CREATE TEMPORARY TABLE orders (
        id BIGINT PRIMARY KEY, order_number VARCHAR(32), status VARCHAR(32), payment_status VARCHAR(32),
        payment_provider VARCHAR(32), payment_intent_id VARCHAR(128), test_payment BOOLEAN,
        payment_refund_attempt INT, payment_refund_id VARCHAR(128) NULL, payment_refund_outcome VARCHAR(32),
        payment_refunded_cents BIGINT, payment_refund_error_code VARCHAR(100) NULL,
        payment_refund_error_message VARCHAR(500) NULL, payment_refund_error_http_status INT NULL,
        total DECIMAL(10,2), currency CHAR(3)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TEMPORARY TABLE payment_refund_attempts (
        order_id BIGINT, attempt INT, attempt_kind VARCHAR(16), stripe_refund_id VARCHAR(128) NULL,
        amount_cents BIGINT, currency CHAR(3), previous_payment_status VARCHAR(32), previous_order_status VARCHAR(32),
        withdrawal_request_id BIGINT NULL, status VARCHAR(16), reason VARCHAR(500),
        provider_error_code VARCHAR(100) NULL, provider_error_message VARCHAR(500) NULL,
        PRIMARY KEY (order_id, attempt), UNIQUE KEY uq_refund_id (stripe_refund_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TEMPORARY TABLE payment_notification_outbox (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT, notification_type VARCHAR(60), reference_id VARCHAR(128),
        UNIQUE KEY uq_notification (order_id, notification_type, reference_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TEMPORARY TABLE admin_audit_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, admin_user_id BIGINT NULL, action_name VARCHAR(100),
        target_type VARCHAR(32), target_id BIGINT, previous_value VARCHAR(1000), new_value VARCHAR(1000)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TEMPORARY TABLE payment_webhook_events (
        event_id VARCHAR(128) PRIMARY KEY, event_type VARCHAR(100)
    ) ENGINE=InnoDB");
    $counted = $status === 'succeeded' || ($status === 'manual' && $resolution === 'manual_refunded');
    $paymentStatus = $counted ? 'partially_refunded' : (in_array($status, ['pending', 'unknown'], true) ? 'refund_pending' : 'paid');
    $pdo->prepare("INSERT INTO orders VALUES (1, 'REFUND-IDENTITY-1', 'confirmed', :paymentStatus,
        'stripe', 'pi_identity1', 1, 1, :refundId, :outcome, :counted, NULL, NULL, NULL, 100.00, 'EUR')")
        ->execute([':paymentStatus' => $paymentStatus, ':refundId' => $bound ? 're_bound1' : null,
            ':outcome' => $resolution ?? $status, ':counted' => $counted ? 2500 : 0]);
    $pdo->prepare("INSERT INTO payment_refund_attempts VALUES (1, 1, 'partial', :refundId,
        2500, 'EUR', 'paid', 'confirmed', NULL, :status, 'identity regression', :resolution, NULL)")
        ->execute([':refundId' => $bound ? 're_bound1' : null, ':status' => $status, ':resolution' => $resolution]);
    return $pdo;
}

function refundIdentityRow(string $id = 're_bound1', string $status = 'pending'): array
{
    return ['id' => $id, 'object' => 'refund', 'status' => $status, 'payment_intent' => 'pi_identity1',
        'amount' => 2500, 'currency' => 'eur', 'livemode' => false,
        'metadata' => ['order_number' => 'REFUND-IDENTITY-1', 'refund_attempt' => '1']];
}

function sendRefundIdentityWebhook(PartialRefundIdentityHarness $harness, array $refund, string $eventId): array
{
    $payload = json_encode(['id' => $eventId, 'type' => 'refund.updated', 'livemode' => false,
        'data' => ['object' => $refund]], JSON_THROW_ON_ERROR);
    $now = time();
    $signature = hash_hmac('sha256', $now . '.' . $payload, 'whsec_identity_fixture');
    return $harness->handleWebhook($payload, "t={$now},v1={$signature}");
}

function refundIdentitySnapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach (['orders', 'payment_refund_attempts', 'payment_notification_outbox', 'admin_audit_log', 'payment_webhook_events'] as $table) {
        $snapshot[$table] = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
    }
    return $snapshot;
}

function expectRefundIdentityConflict(callable $operation): void
{
    try {
        $operation();
    } catch (HttpException $exception) {
        assertSameValue(409, $exception->status);
        assertSameValue('stripe_refund_attempt_mismatch', $exception->errorCode);
        return;
    }
    throw new RuntimeException('A different Stripe refund must never replace the identity of a bound attempt.');
}

return [
    'partial refund webhook identity is immutable in every pending and terminal state' => static function (): void {
        withEnvironment(['APP_ENV' => 'staging', 'STRIPE_WEBHOOK_SECRET' => 'whsec_identity_fixture'], static function (): void {
            foreach ([['pending', null], ['unknown', null], ['failed', 'declined'], ['succeeded', null],
                ['manual', 'manual_refunded'], ['manual', 'manual_not_refunded']] as [$status, $resolution]) {
                $pdo = refundIdentityDatabase($status, $resolution);
                if ($pdo === null) return;
                $harness = new PartialRefundIdentityHarness($pdo);
                $before = refundIdentitySnapshot($pdo);
                foreach (['pending', 'requires_action', 'failed', 'canceled', 'succeeded', 'unrecognized'] as $providerStatus) {
                    $eventId = 'evt_conflict' . $providerStatus;
                    // Reusing the same event must fail again, not acknowledge a rolled-back conflict as a duplicate.
                    for ($replay = 0; $replay < 2; $replay++) {
                        expectRefundIdentityConflict(static fn () => sendRefundIdentityWebhook($harness, refundIdentityRow('re_other1', $providerStatus), $eventId));
                        assertSameValue($before, refundIdentitySnapshot($pdo));
                        assertSameValue(false, $pdo->inTransaction());
                    }
                }
            }
        });
    },
    'partial refund admin result cannot replace a stored provider identity or count its amount' => static function (): void {
        withEnvironment(['APP_ENV' => 'staging'], static function (): void {
            $pdo = refundIdentityDatabase();
            if ($pdo === null) return;
            $harness = new PartialRefundIdentityHarness($pdo);
            $before = refundIdentitySnapshot($pdo);
            expectRefundIdentityConflict(static fn () => $harness->applyAdminResult(refundIdentityRow('re_other1', 'succeeded')));
            assertSameValue($before, refundIdentitySnapshot($pdo));
            assertSameValue(false, $pdo->inTransaction());
        });
    },
    'partial refund binds its first provider identity and remains idempotent for that identity only' => static function (): void {
        withEnvironment(['APP_ENV' => 'staging', 'STRIPE_WEBHOOK_SECRET' => 'whsec_identity_fixture'], static function (): void {
            $pdo = refundIdentityDatabase('unknown', null, false);
            if ($pdo === null) return;
            $harness = new PartialRefundIdentityHarness($pdo);
            sendRefundIdentityWebhook($harness, refundIdentityRow(), 'evt_first');
            assertSameValue('re_bound1', $pdo->query('SELECT stripe_refund_id FROM payment_refund_attempts')->fetchColumn());
            $before = refundIdentitySnapshot($pdo);
            expectRefundIdentityConflict(static fn () => sendRefundIdentityWebhook($harness, refundIdentityRow('re_other1', 'succeeded'), 'evt_conflict'));
            assertSameValue($before, refundIdentitySnapshot($pdo));
            sendRefundIdentityWebhook($harness, refundIdentityRow('re_bound1', 'succeeded'), 'evt_succeeded');
            $afterSuccess = refundIdentitySnapshot($pdo);
            assertSameValue(2500, (int) $pdo->query('SELECT payment_refunded_cents FROM orders')->fetchColumn());
            assertSameValue('succeeded', $pdo->query('SELECT status FROM payment_refund_attempts')->fetchColumn());
            assertSameValue(['received' => true, 'duplicate' => true], sendRefundIdentityWebhook($harness, refundIdentityRow('re_bound1', 'succeeded'), 'evt_succeeded'));
            assertSameValue($afterSuccess, refundIdentitySnapshot($pdo));
            sendRefundIdentityWebhook($harness, refundIdentityRow('re_bound1', 'succeeded'), 'evt_succeeded_again');
            sendRefundIdentityWebhook($harness, refundIdentityRow('re_bound1', 'pending'), 'evt_late_pending');
            assertSameValue(2500, (int) $pdo->query('SELECT payment_refunded_cents FROM orders')->fetchColumn());
            assertSameValue('succeeded', $pdo->query('SELECT status FROM payment_refund_attempts')->fetchColumn());
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
        });
    },
];
