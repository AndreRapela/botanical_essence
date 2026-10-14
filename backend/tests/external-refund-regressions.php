<?php

declare(strict_types=1);

/** All ledger application, webhook signatures and external-review transactions are real. */
final class ExternalRefundIntegrationHarness
{
    use PaymentWebhookActions;
    use RefundAdminActions { verifyProviderRefundLedger as private verifyRealProviderRefundLedger; }
    use RefundAdminResultActions;
    use PartialRefundActions;
    public readonly ExternalRefundService $externalRefunds;
    private readonly LatePaymentRecoveryService $latePaymentRecovery;
    private readonly object $operations;
    public ?Closure $afterPreflight = null;

    public function __construct(private readonly PDO $pdo, private readonly StripeGateway $stripe)
    {
        $this->externalRefunds = new ExternalRefundService($pdo, $stripe, new RateLimiter($pdo), new PaymentOrderLock($pdo), $this->apply(...));
        $this->latePaymentRecovery = new LatePaymentRecoveryService($pdo);
        $this->operations = new class { public function dispatchOutbox(int $limit): void {} };
    }

    public function apply(array $refund): void { $this->applyRefundEvent($refund); }
    public function startPartial(): array { return $this->startPartialRefundAttempt($this->order(), 1000, 'Test provider preflight', 42); }
    public function startFull(?int $withdrawalId = null): array { return $this->startAdminRefundAttempt($this->order(), 42, $withdrawalId); }
    public function fullResult(array $refund): array { return $this->applyAdminRefundResult($this->order(), $refund, 42); }
    public function resolvePartial(string $resolution, ?array $selectedOrder = null): array
    {
        $order = $selectedOrder ?? $this->order();
        return $this->resolvePartialRefundAttempt($order, 42, $resolution, 'Provider independently verified this manual decision', manualTargetForOrder($this->pdo, $order));
    }
    public function resolveFull(string $resolution): array
    {
        $order = $this->order();
        return $this->resolveAdminRefund($order, 42, $resolution, 'Provider independently verified this manual decision', manualTargetForOrder($this->pdo, $order));
    }
    public function rejectFull(int $attempt): void { $this->recordDefinitiveRefundFailure(1, 'pi_external1', $attempt, null, 'amount_too_large', 'Provider rejected the exact amount', 400, 42); }
    public function order(): array { return $this->pdo->query('SELECT * FROM orders WHERE id = 1')->fetch(); }
    protected function verifyProviderRefundLedger(array $order): void
    {
        $this->verifyRealProviderRefundLedger($order);
        if ($this->afterPreflight !== null) ($this->afterPreflight)();
    }
    private function orderForAdmin(string $number): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM orders WHERE order_number = :number');
        $query->execute([':number' => $number]);
        return $query->fetch() ?: null;
    }
    private function cents(string $amount): int { return (int) round((float) $amount * 100); }
    protected function queueFullRefundConfirmation(int $id, string $reference): void
    {
        $this->pdo->prepare("INSERT IGNORE INTO payment_notification_outbox (order_id, notification_type, reference_id)
            VALUES (:id, 'full_refund_confirmation', :reference)")->execute([':id' => $id, ':reference' => $reference]);
    }
    private function restoreStock(int $id): void { $this->pdo->exec('UPDATE products SET stock = stock + 1 WHERE id = 1'); }
}

function externalRefundDatabase(): ?PDO
{
    $pdo = disputeRecoveryMySQL();
    if ($pdo === null) return null;
    $pdo->exec("CREATE TEMPORARY TABLE orders (
        id BIGINT PRIMARY KEY, order_number VARCHAR(32), status VARCHAR(32), payment_status VARCHAR(32),
        payment_provider VARCHAR(32), payment_intent_id VARCHAR(128), test_payment BOOLEAN,
        payment_refund_attempt INT, payment_refund_id VARCHAR(128) NULL, payment_refund_outcome VARCHAR(32),
        payment_refunded_cents BIGINT, payment_refund_error_code VARCHAR(100) NULL,
        payment_refund_error_message VARCHAR(500) NULL, payment_refund_error_http_status INT NULL,
        total DECIMAL(10,2), currency CHAR(3), reservation_expires_at TIMESTAMP NULL,
        payment_refunds_scanned_at TIMESTAMP NULL, payment_refunds_scan_error VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("ALTER TABLE orders ADD email VARCHAR(120) DEFAULT 'customer@example.test', ADD address_line VARCHAR(120) DEFAULT 'Test address',
        ADD city VARCHAR(60) DEFAULT 'Brussels', ADD postal_code VARCHAR(20) DEFAULT '1000', ADD region VARCHAR(60) DEFAULT 'Brussels', ADD country CHAR(2) DEFAULT 'BE'");
    $pdo->exec("INSERT INTO orders (id, order_number, status, payment_status, payment_provider, payment_intent_id,
        test_payment, payment_refund_attempt, payment_refund_outcome, payment_refunded_cents, total, currency)
        VALUES (1, 'EXTERNAL-REFUND-1', 'confirmed', 'paid', 'stripe', 'pi_external1', 1, 0, 'none', 0, 100.00, 'EUR')");
    $pdo->exec("CREATE TEMPORARY TABLE payment_refund_attempts (
        order_id BIGINT, attempt INT, attempt_kind VARCHAR(16), stripe_refund_id VARCHAR(128) NULL,
        amount_cents BIGINT, currency CHAR(3), previous_payment_status VARCHAR(32), previous_order_status VARCHAR(32),
        withdrawal_request_id BIGINT NULL, status VARCHAR(16), reason VARCHAR(500),
        provider_error_code VARCHAR(100) NULL, provider_error_message VARCHAR(500) NULL, requested_by_admin_id BIGINT NULL,
        PRIMARY KEY (order_id, attempt), UNIQUE KEY uq_refund_id (stripe_refund_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TEMPORARY TABLE payment_external_refunds (
        stripe_refund_id VARCHAR(128) PRIMARY KEY, order_id BIGINT NULL, payment_intent_id VARCHAR(128) NULL,
        amount_cents BIGINT, currency CHAR(3), livemode BOOLEAN, provider_status VARCHAR(32),
        resolution VARCHAR(16) DEFAULT 'unmatched', local_attempt INT NULL, reviewed_by_admin_id BIGINT NULL,
        review_reason VARCHAR(500) NULL, review_revision INT DEFAULT 0, last_synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TEMPORARY TABLE payment_notification_outbox (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, order_id BIGINT, notification_type VARCHAR(60), reference_id VARCHAR(128),
        UNIQUE KEY uq_notification (order_id, notification_type, reference_id)
    ) ENGINE=InnoDB");
    $pdo->exec("ALTER TABLE payment_notification_outbox ADD status VARCHAR(16) DEFAULT 'pending', ADD attempts INT DEFAULT 0,
        ADD consecutive_failures INT DEFAULT 0, ADD available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, ADD locked_until TIMESTAMP NULL,
        ADD last_error VARCHAR(500) NULL, ADD sent_at TIMESTAMP NULL");
    $pdo->exec("CREATE TEMPORARY TABLE admin_audit_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY, admin_user_id BIGINT NULL, action_name VARCHAR(100),
        target_type VARCHAR(32), target_id BIGINT NOT NULL, previous_value VARCHAR(1000), new_value VARCHAR(1000)
    ) ENGINE=InnoDB");
    $pdo->exec('CREATE TEMPORARY TABLE payment_webhook_events (event_id VARCHAR(128) PRIMARY KEY, event_type VARCHAR(100)) ENGINE=InnoDB');
    $pdo->exec('CREATE TEMPORARY TABLE withdrawal_requests (id BIGINT PRIMARY KEY, order_id BIGINT, status VARCHAR(32)) ENGINE=InnoDB');
    $pdo->exec('CREATE TEMPORARY TABLE withdrawal_request_events (request_id BIGINT, actor_role VARCHAR(16), event_name VARCHAR(60), previous_status VARCHAR(32), new_status VARCHAR(32), note VARCHAR(2000)) ENGINE=InnoDB');
    $pdo->exec('CREATE TEMPORARY TABLE products (id BIGINT PRIMARY KEY, stock INT) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO products VALUES (1, 5)');
    $pdo->exec('CREATE TEMPORARY TABLE rate_limits (action_name VARCHAR(100), identifier_hash VARCHAR(64), attempts INT, window_started_at TIMESTAMP, PRIMARY KEY (action_name, identifier_hash)) ENGINE=InnoDB');
    return $pdo;
}

function externalRefundRow(string $id = 're_external1', string $status = 'succeeded', int $amount = 2500): array
{
    return ['id' => $id, 'object' => 'refund', 'status' => $status, 'amount' => $amount, 'currency' => 'eur',
        'payment_intent' => 'pi_external1', 'livemode' => false, 'metadata' => [], 'created' => 1000];
}

function externalRefundGateway(array &$refunds, array &$calls, ?Closure $list = null): StripeGateway
{
    return new StripeGateway(static function (string $url, $context) use (&$refunds, &$calls, $list): array {
        $options = stream_context_get_options($context);
        $calls[] = ['method' => $options['http']['method'], 'path' => parse_url($url, PHP_URL_PATH), 'query' => parse_url($url, PHP_URL_QUERY)];
        assertSameValue('GET', $options['http']['method'], 'External reconciliation must never send a refund or a charge.');
        $id = basename((string) parse_url($url, PHP_URL_PATH));
        if ($id === 'refunds') {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            return ['body' => json_encode($list ? $list($query) : ['object' => 'list', 'data' => array_values($refunds), 'has_more' => false], JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK'];
        }
        if (!isset($refunds[$id])) return ['body' => '{"error":{"code":"resource_missing","message":"Fixture refund missing"}}', 'statusLine' => 'HTTP/1.1 404 Not Found'];
        return ['body' => json_encode($refunds[$id], JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK'];
    });
}

function externalRefundEnvironment(callable $operation): void
{
    withEnvironment(['APP_ENV' => 'staging', 'STRIPE_SECRET_KEY' => 'sk_test_external_fixture',
        'STRIPE_WEBHOOK_SECRET' => 'whsec_external_fixture'], $operation);
}

function externalRefundWebhook(ExternalRefundIntegrationHarness $harness, array $refund, string $eventId = 'evt_external1'): array
{
    $payload = json_encode(['id' => $eventId, 'type' => 'refund.created', 'livemode' => false, 'data' => ['object' => $refund]], JSON_THROW_ON_ERROR);
    $now = time();
    return $harness->handleWebhook($payload, 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $payload, 'whsec_external_fixture'));
}

function expectExternalRefundConflict(callable $operation, ?string $code = null): void
{
    try { $operation(); } catch (HttpException $exception) {
        assertSameValue(409, $exception->status);
        if ($code !== null) assertSameValue($code, $exception->errorCode);
        return;
    }
    throw new RuntimeException('An ambiguous or unverified external refund must be rejected.');
}

function externalRefundAttempt(PDO $pdo, int $attempt, string $status, ?string $resolution = null, ?string $refundId = null, string $kind = 'partial', int $amount = 2500): void
{
    $pdo->prepare("INSERT INTO payment_refund_attempts (order_id, attempt, attempt_kind, stripe_refund_id,
        amount_cents, currency, previous_payment_status, previous_order_status, status, reason, provider_error_code)
        VALUES (1, :attempt, :kind, :refundId, :amount, 'EUR', 'paid', 'confirmed', :status, 'test ledger attempt', :resolution)")
        ->execute([':attempt' => $attempt, ':kind' => $kind, ':refundId' => $refundId, ':amount' => $amount, ':status' => $status, ':resolution' => $resolution]);
    $pdo->prepare('UPDATE orders SET payment_refund_attempt = :attempt WHERE id = 1')->execute([':attempt' => $attempt]);
}

return [
    'external review excludes a concurrent worker and releases its payment lock after a provider read fails' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            $peer = disputeRecoveryMySQL();
            $refunds = ['re_external1' => externalRefundRow()]; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            $harness->externalRefunds->capture($refunds['re_external1']);
            $input = ['refundId' => 're_external1', 'action' => 'record', 'reason' => 'Verified this is a distinct provider refund'];
            $lock = new PaymentOrderLock($peer);
            $lock->acquire(1);
            try {
                expectExternalRefundConflict(static fn () => $harness->externalRefunds->resolve($input, ['id' => 42]), 'payment_operation_in_progress');
                assertSameValue([], $calls);
                assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
            } finally { $lock->release(1); }
            $refunds = [];
            try {
                $harness->externalRefunds->resolve($input, ['id' => 42]);
                throw new RuntimeException('A failed provider read must not reconcile accounting.');
            } catch (StripeApiException) {
                assertSameValue(1, (int) $pdo->query("SELECT IS_FREE_LOCK('botanical-payment-order-1')")->fetchColumn());
            }
            $refunds = ['re_external1' => externalRefundRow()];
            $harness->externalRefunds->resolve($input, ['id' => 42]);
            assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue(1, (int) $pdo->query("SELECT IS_FREE_LOCK('botanical-payment-order-1')")->fetchColumn());
        });
    },
    'a rejected current refund preserves money confirmed by an older attempt while it was in flight' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['full', 'partial'] as $kind) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                externalRefundAttempt($pdo, 1, 'pending', null, 're_older');
                externalRefundAttempt($pdo, 2, 'pending', null, 're_external1', $kind, $kind === 'full' ? 7500 : 2500);
                $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_id = 're_external1', payment_refund_outcome = 'pending'");
                $refunds = ['re_older' => externalRefundRow('re_older'),
                    're_external1' => externalRefundRow('re_external1', 'failed', $kind === 'full' ? 7500 : 2500)]; $calls = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
                externalRefundWebhook($harness, $refunds['re_older']);
                assertSameValue('refund_pending', $harness->order()['payment_status']);
                assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
                externalRefundWebhook($harness, $refunds['re_external1'], 'evt_currentfailed');
                assertSameValue('partially_refunded', $harness->order()['payment_status']);
                assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
                assertSameValue('re_external1', $harness->order()['payment_refund_id']);
                assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            }
        });
    },
    'replayed provider failures do not duplicate financial audits but a changed failure reason is retained' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['full', 'partial'] as $kind) {
                foreach (['failed', 'canceled'] as $status) {
                    $pdo = externalRefundDatabase(); if ($pdo === null) return;
                    $amount = $kind === 'full' ? 10000 : 2500;
                    externalRefundAttempt($pdo, 1, 'pending', null, 're_external1', $kind, $amount);
                    $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_id = 're_external1', payment_refund_outcome = 'pending'");
                    $refunds = ['re_external1' => externalRefundRow('re_external1', $status, $amount)]; $calls = [];
                    $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
                    $harness->externalRefunds->scanOrder($harness->order());
                    $ledger = $pdo->query('SELECT * FROM payment_refund_attempts')->fetchAll();
                    $order = $harness->order();
                    assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
                    $harness->externalRefunds->scanOrder($order);
                    externalRefundWebhook($harness, $refunds['re_external1']);
                    assertSameValue($ledger, $pdo->query('SELECT * FROM payment_refund_attempts')->fetchAll());
                    assertSameValue($order, $harness->order());
                    assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
                    $refunds['re_external1']['failure_reason'] = 'lost_or_stolen_card';
                    $harness->externalRefunds->scanOrder($order);
                    $harness->externalRefunds->scanOrder($order);
                    assertSameValue('lost_or_stolen_card', $pdo->query('SELECT provider_error_code FROM payment_refund_attempts')->fetchColumn());
                    assertSameValue('lost_or_stolen_card', $harness->order()['payment_refund_error_code']);
                    assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
                    assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
                    assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
                }
            }
        });
    },
    'late provider refund returns are reviewed, reversed exactly once and can resume without double counting' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['failed', 'canceled', 'requires_action'] as $status) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                externalRefundAttempt($pdo, 1, 'succeeded', null, 're_external1');
                $pdo->exec("UPDATE orders SET payment_status = 'partially_refunded', payment_refunded_cents = 2500, payment_refund_id = 're_external1', payment_refund_outcome = 'succeeded'");
                $pdo->exec("INSERT INTO withdrawal_requests VALUES (7, 1, 'refunded')");
                $pdo->exec('UPDATE payment_refund_attempts SET withdrawal_request_id = 7');
                $refunds = ['re_external1' => externalRefundRow('re_external1', $status)]; $calls = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
                externalRefundWebhook($harness, $refunds['re_external1']);
                assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
                assertSameValue('unmatched', $pdo->query('SELECT resolution FROM payment_external_refunds')->fetchColumn());
                expectExternalRefundConflict(static fn () => $harness->externalRefunds->resolve(['action' => 'link', 'refundId' => 're_external1', 'attempt' => 1, 'reason' => 'This cannot conceal a returned provider refund'], ['id' => 42]));
                $input = ['action' => 'reverse', 'refundId' => 're_external1', 'attempt' => 1, 'reason' => 'Verified that Stripe returned these refund funds'];
                $harness->externalRefunds->resolve($input, ['id' => 42]);
                $harness->externalRefunds->resolve($input, ['id' => 42]);
                assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
                assertSameValue($status === 'requires_action' ? 'refund_pending' : 'paid', $harness->order()['payment_status']);
                assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
                assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM withdrawal_request_events')->fetchColumn());
                assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
                if ($status === 'requires_action') {
                    $refunds['re_external1'] = externalRefundRow();
                    externalRefundWebhook($harness, $refunds['re_external1'], 'evt_resumed');
                    externalRefundWebhook($harness, $refunds['re_external1'], 'evt_resumed_again');
                    assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
                    $harness->externalRefunds->scanOrder($harness->order());
                    assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
                    assertSameValue('partially_refunded', $harness->order()['payment_status']);
                    $refunds['re_external1'] = externalRefundRow('re_external1', 'requires_action');
                    externalRefundWebhook($harness, $refunds['re_external1'], 'evt_returned_again');
                    $harness->externalRefunds->resolve($input, ['id' => 42]);
                    assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
                    assertSameValue(2, (int) $pdo->query("SELECT COUNT(*) FROM payment_notification_outbox WHERE notification_type = 'refund_reversal_notice'")->fetchColumn());
                }
            }
        });
    },
    'manually counted full and partial refunds detect bank returns and ignore old success events until a fresh provider scan' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['full', 'partial'] as $kind) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                $amount = $kind === 'full' ? 10000 : 2500;
                externalRefundAttempt($pdo, 1, 'manual', 'manual_refunded', 're_external1', $kind, $amount);
                $pdo->prepare("UPDATE orders SET payment_status = :status, payment_refunded_cents = :amount,
                    status = :orderStatus, payment_refund_id = 're_external1', payment_refund_outcome = 'manual_refunded'")
                    ->execute([':status' => $kind === 'full' ? 'refunded' : 'partially_refunded', ':amount' => $amount,
                        ':orderStatus' => $kind === 'full' ? 'cancelled' : 'confirmed']);
                $refunds = ['re_external1' => externalRefundRow('re_external1', 'failed', $amount)]; $calls = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
                externalRefundWebhook($harness, $refunds['re_external1']);
                assertSameValue('unmatched', $pdo->query('SELECT resolution FROM payment_external_refunds')->fetchColumn());
                $harness->externalRefunds->resolve(['refundId' => 're_external1', 'action' => 'reverse', 'attempt' => 1,
                    'reason' => 'Verify the bank returned this manually counted amount'], ['id' => 42]);
                externalRefundWebhook($harness, externalRefundRow('re_external1', 'succeeded', $amount), 'evt_oldsuccess');
                assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
                assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
                $harness->externalRefunds->scanOrder($harness->order());
                assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
                assertSameValue('reversed', $pdo->query('SELECT resolution FROM payment_external_refunds')->fetchColumn());
                $refunds['re_external1'] = externalRefundRow('re_external1', 'succeeded', $amount);
                $harness->externalRefunds->scanOrder($harness->order());
                $harness->externalRefunds->scanOrder($harness->order());
                assertSameValue($amount, (int) $harness->order()['payment_refunded_cents']);
                assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
                assertSameValue('linked', $pdo->query('SELECT resolution FROM payment_external_refunds')->fetchColumn());
            }
        });
    },
    'a stale failure event cannot reverse a refund still confirmed by the provider' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'succeeded', null, 're_external1');
            $pdo->exec("UPDATE orders SET payment_status = 'partially_refunded', payment_refunded_cents = 2500, payment_refund_outcome = 'succeeded'");
            $refunds = ['re_external1' => externalRefundRow()]; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            externalRefundWebhook($harness, externalRefundRow('re_external1', 'failed'));
            $harness->externalRefunds->resolve(['action' => 'reverse', 'refundId' => 're_external1', 'attempt' => 1, 'reason' => 'Requery the provider before reversing any amount'], ['id' => 42]);
            assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue('linked', $pdo->query('SELECT resolution FROM payment_external_refunds')->fetchColumn());
            assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
            $harness->externalRefunds->scanOrder($harness->order());
            assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM admin_audit_log WHERE action_name = 'refund.external_provider_confirmed'")->fetchColumn());
        });
    },
    'a verified full refund return leaves cancelled stock untouched and cannot send a stale full-refund confirmation' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'succeeded', null, 're_external1', 'full', 10000);
            $pdo->exec("UPDATE orders SET status = 'cancelled', payment_status = 'refunded', payment_refunded_cents = 10000, payment_refund_outcome = 'succeeded'");
            $pdo->exec("INSERT INTO payment_notification_outbox (order_id, notification_type, reference_id) VALUES (1, 'full_refund_confirmation', 're_external1')");
            $refunds = ['re_external1' => externalRefundRow('re_external1', 'failed', 10000)]; $calls = [];
            $gateway = externalRefundGateway($refunds, $calls);
            $harness = new ExternalRefundIntegrationHarness($pdo, $gateway);
            externalRefundWebhook($harness, $refunds['re_external1']);
            $harness->externalRefunds->resolve(['action' => 'reverse', 'refundId' => 're_external1', 'attempt' => 1, 'reason' => 'Provider confirmed the complete refund was returned'], ['id' => 42]);
            assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue('cancelled', $harness->order()['status']);
            assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            $mailer = new class implements MailTransport {
                public array $sent = [];
                public function send(string $recipient, string $subject, string $text): void { $this->sent[] = [$recipient, $subject, $text]; }
            };
            $operations = new PaymentOperationsService($pdo, $gateway, new PaymentOrderLock($pdo), $mailer,
                static fn () => null, static fn () => null, $harness->apply(...));
            assertSameValue(1, $operations->dispatchOutbox());
            assertSameValue(1, count($mailer->sent));
            assertTrueValue(str_contains($mailer->sent[0][1], 'Refund status changed'));
            assertTrueValue(!str_contains($mailer->sent[0][2], 'A full refund'));
            assertSameValue('sent', $pdo->query("SELECT status FROM payment_notification_outbox WHERE notification_type = 'refund_reversal_notice'")->fetchColumn());
        });
    },
    'external refunds without metadata are durable, idempotent and do not change money or inventory' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            $refunds = ['re_external1' => externalRefundRow()]; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            externalRefundWebhook($harness, $refunds['re_external1']);
            assertSameValue(['received' => true, 'duplicate' => true], externalRefundWebhook($harness, $refunds['re_external1']));
            externalRefundWebhook($harness, externalRefundRow('re_external1', 'pending'), 'evt_stale');
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_external_refunds')->fetchColumn());
            assertSameValue('succeeded', $pdo->query('SELECT provider_status FROM payment_external_refunds')->fetchColumn());
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
            assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue('paid', $harness->order()['payment_status']);
            assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
            assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            assertSameValue([], $calls);
        });
    },
    'a distinct confirmed external refund is counted once through the real partial ledger without a Stripe POST' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            $refunds = ['re_external1' => externalRefundRow()]; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            $harness->apply($refunds['re_external1']);
            $input = ['refundId' => 're_external1', 'action' => 'record', 'reason' => 'Verified distinct refund in Stripe'];
            $harness->externalRefunds->resolve($input, ['id' => 42]);
            $harness->externalRefunds->resolve($input, ['id' => 42]);
            externalRefundWebhook($harness, $refunds['re_external1']);
            assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue('partially_refunded', $harness->order()['payment_status']);
            assertSameValue('confirmed', $harness->order()['status']);
            assertSameValue('external', $pdo->query('SELECT resolution FROM payment_external_refunds')->fetchColumn());
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_refund_attempts')->fetchColumn());
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
            assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            assertSameValue(['GET'], array_values(array_unique(array_column($calls, 'method'))));
        });
    },
    'linking a manually counted partial or full refund never counts or releases stock twice' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['partial' => 2500, 'full' => 10000] as $kind => $amount) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                externalRefundAttempt($pdo, 1, 'manual', 'manual_refunded', null, $kind, $amount);
                $pdo->exec("UPDATE orders SET payment_refunded_cents = {$amount}, payment_status = '" . ($kind === 'full' ? 'refunded' : 'partially_refunded') . "', status = '" . ($kind === 'full' ? 'cancelled' : 'confirmed') . "', payment_refund_outcome = 'manual_refunded'");
                $refunds = ['re_external1' => externalRefundRow('re_external1', 'succeeded', $amount)]; $calls = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
                $harness->apply($refunds['re_external1']);
                if ($kind === 'partial') expectExternalRefundConflict(static fn () => $harness->externalRefunds->resolve(['refundId' => 're_external1', 'action' => 'record', 'reason' => 'Do not count this manually claimed amount twice'], ['id' => 42]), 'external_refund_ambiguous');
                $input = ['refundId' => 're_external1', 'action' => 'link', 'attempt' => 1, 'reason' => 'Verified this is the manually counted refund'];
                $harness->externalRefunds->resolve($input, ['id' => 42]);
                $harness->externalRefunds->resolve($input, ['id' => 42]);
                assertSameValue($amount, (int) $harness->order()['payment_refunded_cents']);
                assertSameValue('succeeded', $pdo->query('SELECT status FROM payment_refund_attempts')->fetchColumn());
                assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
                assertSameValue('linked', $pdo->query('SELECT resolution FROM payment_external_refunds')->fetchColumn());
            }
        });
    },
    'external success linked to an older manual not-refunded attempt preserves a newer in-flight attempt' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'manual', 'manual_not_refunded');
            externalRefundAttempt($pdo, 2, 'pending', null, 're_newer2', 'partial', 1000);
            $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_id = 're_newer2', payment_refund_outcome = 'pending'");
            $refunds = ['re_external1' => externalRefundRow()]; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            $harness->apply($refunds['re_external1']);
            $harness->externalRefunds->resolve(['refundId' => 're_external1', 'action' => 'link', 'attempt' => 1, 'reason' => 'Confirmed provider refund for the older attempt'], ['id' => 42]);
            assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue('refund_pending', $harness->order()['payment_status']);
            assertSameValue('re_newer2', $harness->order()['payment_refund_id']);
            assertSameValue(2, (int) $harness->order()['payment_refund_attempt']);
        });
    },
    'a bound refund identity outranks mutable metadata pointing to another kind or attempt' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'pending', null, 're_external1', 'full', 10000);
            externalRefundAttempt($pdo, 2, 'pending', null, 're_other2');
            $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'pending', payment_refund_id = 're_other2'");
            $refund = [...externalRefundRow('re_external1', 'succeeded', 10000), 'metadata' => ['order_number' => 'EXTERNAL-REFUND-1', 'refund_attempt' => '2']];
            $refunds = ['re_external1' => $refund]; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            $harness->apply($refund);
            $harness->apply($refund);
            assertSameValue(10000, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue('refund_pending', $harness->order()['payment_status']);
            assertSameValue('re_other2', $harness->order()['payment_refund_id']);
            assertSameValue('pending', $harness->order()['payment_refund_outcome']);
            assertSameValue('succeeded', $pdo->query('SELECT status FROM payment_refund_attempts WHERE attempt = 1')->fetchColumn());
            assertSameValue(6, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            assertSameValue('pending', $pdo->query('SELECT status FROM payment_refund_attempts WHERE attempt = 2')->fetchColumn());
        });
    },
    'external review rejects active dismissal, pending accounting, mismatched links and excess amounts atomically' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'failed', 'declined', null, 'partial', 1000);
            $refunds = ['re_external1' => externalRefundRow()]; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            $harness->apply($refunds['re_external1']);
            foreach ([['action' => 'dismiss'], ['action' => 'link', 'attempt' => 1]] as $action) {
                expectExternalRefundConflict(static fn () => $harness->externalRefunds->resolve([...$action, 'refundId' => 're_external1', 'reason' => 'This decision is not financially valid'], ['id' => 42]));
            }
            $pdo->exec('UPDATE orders SET payment_refunded_cents = 9000');
            expectExternalRefundConflict(static fn () => $harness->externalRefunds->resolve(['action' => 'record', 'refundId' => 're_external1', 'reason' => 'Value exceeds the remaining local amount'], ['id' => 42]), 'refund_amount_exceeds_remaining');
            assertSameValue(9000, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_refund_attempts')->fetchColumn());
            assertSameValue('unmatched', $pdo->query('SELECT resolution FROM payment_external_refunds')->fetchColumn());
            assertSameValue(false, $pdo->inTransaction());
        });
    },
    'provider history failure is retried from the head without losing observed refunds or duplicating audit records' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            $refunds = []; $calls = []; $failTail = true;
            for ($index = 1; $index <= 101; $index++) $refunds['re_history' . $index] = [...externalRefundRow('re_history' . $index, 'succeeded', 10), 'created' => 1000 - $index];
            $gateway = externalRefundGateway($refunds, $calls, static function (array $query) use (&$refunds, &$failTail): array {
                if (isset($query['starting_after']) && $failTail) return ['object' => 'list', 'data' => [], 'has_more' => true];
                return ['object' => 'list', 'data' => array_slice(array_values($refunds), isset($query['starting_after']) ? 100 : 0, 100), 'has_more' => !isset($query['starting_after'])];
            });
            $harness = new ExternalRefundIntegrationHarness($pdo, $gateway);
            assertSameValue(0, $harness->externalRefunds->recover());
            assertSameValue(100, (int) $pdo->query('SELECT COUNT(*) FROM payment_external_refunds')->fetchColumn());
            assertTrueValue(is_string($harness->order()['payment_refunds_scan_error']));
            $before = count($calls);
            assertSameValue(0, $harness->externalRefunds->recover());
            assertSameValue($before, count($calls));
            $failTail = false;
            $pdo->exec("UPDATE orders SET payment_refunds_scanned_at = CURRENT_TIMESTAMP - INTERVAL 10 MINUTE");
            assertSameValue(101, $harness->externalRefunds->recover());
            assertSameValue(101, (int) $pdo->query('SELECT COUNT(*) FROM payment_external_refunds')->fetchColumn());
            assertSameValue(101, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
            assertSameValue(null, $harness->order()['payment_refunds_scan_error']);
            assertSameValue(101, $harness->externalRefunds->issues(['limit' => '25'])['total']);
            assertSameValue(25, count($harness->externalRefunds->issues(['limit' => '25', 'page' => '2'])['data']));
            assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
        });
    },
    'new partial and full refunds stop at read-only preflight when an external refund exists' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['startPartial', 'startFull'] as $action) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                $refunds = ['re_external1' => externalRefundRow()]; $calls = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
                expectExternalRefundConflict($harness->{$action}(...), 'external_refund_unmatched');
                assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_refund_attempts')->fetchColumn());
                assertSameValue('paid', $harness->order()['payment_status']);
                assertSameValue(['GET'], array_column($calls, 'method'));
                $refunds = []; // Temporary provider invisibility cannot clear an already recorded issue.
                expectExternalRefundConflict($harness->{$action}(...), 'external_refund_unmatched');
            }
        });
    },
    'outside-app or failed cases require review and reopen if later associated with an active app refund' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            $refunds = ['re_external1' => [...externalRefundRow(), 'payment_intent' => 'pi_orphan1']]; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            $harness->apply($refunds['re_external1']);
            assertSameValue(null, $pdo->query('SELECT order_id FROM payment_external_refunds')->fetchColumn());
            $harness->externalRefunds->resolve(['refundId' => 're_external1', 'action' => 'dismiss', 'reason' => 'Verified that this payment is outside the app'], ['id' => 42]);
            $pdo->exec("UPDATE orders SET payment_intent_id = 'pi_orphan1'");
            $harness->apply($refunds['re_external1']);
            assertSameValue('unmatched', $pdo->query('SELECT resolution FROM payment_external_refunds')->fetchColumn());
            expectExternalRefundConflict(static fn () => ExternalRefundService::assertReconciled($pdo, 1), 'external_refund_unmatched');
        });
    },
    'refund mode identity validation and failed provider reads leave the reconciliation queue untouched' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            $refunds = []; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            foreach ([[...externalRefundRow(), 'livemode' => true], [...externalRefundRow(), 'amount' => 0], [...externalRefundRow(), 'currency' => 'usd']] as $invalid) {
                expectExternalRefundConflict(static fn () => $harness->externalRefunds->capture($invalid));
            }
            assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_external_refunds')->fetchColumn());
            $harness->externalRefunds->capture(externalRefundRow());
            try { $harness->externalRefunds->resolve(['refundId' => 're_external1', 'action' => 'record', 'reason' => 'The provider read must succeed first'], ['id' => 42]); throw new RuntimeException('Missing provider object must not be resolved.'); }
            catch (StripeApiException $exception) { assertSameValue(404, $exception->httpStatus); }
            assertSameValue('unmatched', $pdo->query('SELECT resolution FROM payment_external_refunds')->fetchColumn());
            assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
        });
    },
    'external refund migration is applied in the disposable database and preserves reviewed data on restart' => static function (): void {
        $pdo = externalRefundDatabase(); if ($pdo === null) return;
        assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version = '2026-10-07-external-refund-reconciliation-v22'")->fetchColumn());
        assertSameValue(2, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name IN ('payment_refunds_scanned_at','payment_refunds_scan_error')")->fetchColumn());
        $pdo->exec("INSERT INTO payment_external_refunds (stripe_refund_id, amount_cents, currency, provider_status, resolution) VALUES ('re_reviewed', 100, 'EUR', 'succeeded', 'external')");
        ExternalRefundSchema::migrate($pdo);
        assertSameValue('external', $pdo->query('SELECT resolution FROM payment_external_refunds')->fetchColumn());
    },
];
