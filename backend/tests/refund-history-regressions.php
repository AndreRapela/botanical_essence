<?php

declare(strict_types=1);

function refundHistorySeed(PDO $pdo, string $oldStatus = 'pending', ?string $oldReference = 're_historyold'): void
{
    externalRefundAttempt($pdo, 1, $oldStatus, null, $oldReference);
    externalRefundAttempt($pdo, 2, 'succeeded', null, 're_historycurrent');
    $pdo->exec("UPDATE orders SET payment_status = 'partially_refunded', payment_refunded_cents = 2500,
        payment_refund_outcome = 'succeeded', payment_refund_id = 're_historycurrent'");
}

function refundHistoryProviderRows(string $oldStatus = 'pending'): array
{
    return ['re_historyold' => externalRefundRow('re_historyold', $oldStatus),
        're_historycurrent' => refundBalanceRow('re_historycurrent', 2500, 2)];
}

function refundHistoryReverse(ExternalRefundIntegrationHarness $harness, array &$refunds): void
{
    $refunds['re_historyold']['status'] = 'requires_action';
    externalRefundWebhook($harness, $refunds['re_historyold'], 'evt_history_bank_return');
    $harness->externalRefunds->resolve(['refundId' => 're_historyold', 'action' => 'reverse', 'attempt' => 1,
        'reason' => 'Fresh provider evidence requires corrected recipient details'], ['id' => 42]);
}

function refundHistoryAssertBlocked(ExternalRefundIntegrationHarness $harness, PDO $pdo, array &$posts, string $kind): void
{
    $before = $harness->order();
    $ledger = $pdo->query('SELECT * FROM payment_refund_attempts ORDER BY attempt')->fetchAll();
    $notices = (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn();
    $requested = (int) $pdo->query("SELECT COUNT(*) FROM admin_audit_log WHERE action_name IN ('order.refund_requested','order.partial_refund_requested')")->fetchColumn();
    expectExternalRefundConflict(static fn () => $kind === 'full' ? $harness->startFull() : $harness->startPartial(), 'refund_history_unresolved');
    assertSameValue([], $posts, 'An old unresolved attempt never authorizes another provider POST.');
    assertSameValue($before, $harness->order());
    assertSameValue($ledger, $pdo->query('SELECT * FROM payment_refund_attempts ORDER BY attempt')->fetchAll());
    assertSameValue($notices, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
    assertSameValue($requested, (int) $pdo->query("SELECT COUNT(*) FROM admin_audit_log WHERE action_name IN ('order.refund_requested','order.partial_refund_requested')")->fetchColumn());
    assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
    assertSameValue(false, $pdo->inTransaction());
}

function refundHistoryPaymentService(PDO $pdo, StripeGateway $gateway): PaymentService
{
    $pdo->exec("ALTER TABLE orders ADD customer_name VARCHAR(120) DEFAULT 'Fixture buyer',
        ADD payment_method VARCHAR(32) DEFAULT 'card', ADD payment_session_attempted_at TIMESTAMP NULL,
        ADD payment_session_id VARCHAR(128) NULL, ADD subtotal DECIMAL(10,2) DEFAULT 100,
        ADD shipping_amount DECIMAL(10,2) DEFAULT 0");
    $pdo->exec('CREATE TEMPORARY TABLE order_items (id BIGINT, order_id BIGINT, product_name VARCHAR(120), unit_price DECIMAL(10,2), quantity INT, product_id BIGINT) ENGINE=InnoDB');
    $service = null;
    withEnvironment(['PAYMENT_EXECUTION_MODE' => 'stripe_test'], static function () use ($pdo, $gateway, &$service): void {
        $limiter = new RateLimiter($pdo); $inventory = new InventoryService($pdo);
        $orders = new OrderService($pdo, $limiter, new Mailer(), $inventory,
            new ShippingService($pdo, $limiter), new ExchangeRateService($pdo));
        $service = new PaymentService($pdo, $limiter, $orders, $inventory, $gateway);
    });
    if (!$service instanceof PaymentService) throw new LogicException('The real payment service could not be initialized.');
    return $service;
}

return [
    'older pending or unknown ledger attempts block new full and partial refunds despite a settled current projection' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['pending', 'unknown'] as $status) foreach (['full', 'partial'] as $kind) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                refundHistorySeed($pdo, $status);
                $refunds = refundHistoryProviderRows($status); $posts = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                $harness->externalRefunds->scanOrder($harness->order());
                refundHistoryAssertBlocked($harness, $pdo, $posts, $kind);
            }
        });
    },
    'reviewed requires-action bank returns cannot authorize another refund through a newer succeeded attempt' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['full', 'partial'] as $kind) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                refundHistorySeed($pdo, 'succeeded');
                $pdo->exec('UPDATE orders SET payment_refunded_cents = 5000');
                $refunds = refundHistoryProviderRows('succeeded'); $posts = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                refundHistoryReverse($harness, $refunds);
                assertSameValue('reversed', $pdo->query("SELECT resolution FROM payment_external_refunds WHERE stripe_refund_id = 're_historyold'")->fetchColumn());
                assertSameValue('pending', $pdo->query('SELECT status FROM payment_refund_attempts WHERE attempt = 1')->fetchColumn());
                $harness->externalRefunds->scanOrder($harness->order());
                refundHistoryAssertBlocked($harness, $pdo, $posts, $kind);
            }
        });
    },
    'absence from a complete provider list does not resolve an older unbound pending or unknown attempt' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['pending', 'unknown'] as $status) foreach (['full', 'partial'] as $kind) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                refundHistorySeed($pdo, $status, null);
                $refunds = ['re_historycurrent' => refundBalanceRow('re_historycurrent', 2500, 2)]; $posts = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                refundHistoryAssertBlocked($harness, $pdo, $posts, $kind);
            }
        });
    },
    'a real bank-return review between provider preflight and reservation still blocks both refund kinds' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['full', 'partial'] as $kind) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                refundHistorySeed($pdo, 'succeeded');
                $pdo->exec('UPDATE orders SET payment_refunded_cents = 5000');
                $refunds = refundHistoryProviderRows('succeeded'); $posts = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                $afterReview = null;
                $harness->afterPreflight = static function () use ($harness, &$refunds, &$afterReview): void {
                    refundHistoryReverse($harness, $refunds);
                    $afterReview = $harness->order();
                };
                expectExternalRefundConflict(static fn () => $kind === 'full' ? $harness->startFull() : $harness->startPartial(), 'refund_history_unresolved');
                assertSameValue([], $posts);
                assertSameValue($afterReview, $harness->order());
                assertSameValue(2, (int) $harness->order()['payment_refund_attempt']);
                assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
                assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM admin_audit_log WHERE action_name IN ('order.refund_requested','order.partial_refund_requested')")->fetchColumn());
                assertSameValue(false, $pdo->inTransaction());
            }
        });
    },
    'authoritative historical recovery can settle an older returned refund without replacing the current identity' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['succeeded', 'failed'] as $terminal) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                refundHistorySeed($pdo, 'succeeded');
                $pdo->exec('UPDATE orders SET payment_refunded_cents = 5000');
                $refunds = refundHistoryProviderRows('succeeded'); $posts = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                refundHistoryReverse($harness, $refunds);
                $refunds['re_historyold']['status'] = $terminal;
                $harness->externalRefunds->recover();
                $harness->externalRefunds->scanOrder($harness->order());
                assertSameValue([], $posts);
                assertSameValue($terminal, $pdo->query('SELECT status FROM payment_refund_attempts WHERE attempt = 1')->fetchColumn());
                assertSameValue('re_historycurrent', $harness->order()['payment_refund_id']);
                assertSameValue(2, (int) $harness->order()['payment_refund_attempt']);
                assertSameValue($terminal === 'succeeded' ? 5000 : 2500, (int) $harness->order()['payment_refunded_cents']);
                $harness->startFull();
                assertSameValue(1, count($posts));
                assertSameValue($terminal === 'succeeded' ? '5000' : '7500', $posts[0]['amount']);
            }
        });
    },
    'late-payment dispatcher excludes only its own immutable attempt and never posts while another attempt is unresolved' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = lateRefundDatabase(); if ($pdo === null) return;
            lateRefundPrepare($pdo);
            externalRefundAttempt($pdo, 2, 'pending', null, 're_historyold');
            $pdo->exec('UPDATE orders SET payment_refund_attempt = 1');
            $refunds = ['re_historyold' => [...externalRefundRow('re_historyold', 'pending'),
                'metadata' => ['order_number' => 'EXTERNAL-REFUND-1', 'refund_attempt' => '2']]]; $calls = []; $now = time();
            $worker = lateRefundWorker($pdo, lateRefundGateway($pdo, $refunds, $calls), $now);
            $worker->runBatch(); $worker->runBatch();
            assertSameValue([], array_values(array_filter($calls, static fn (array $call): bool => $call['method'] === 'POST')));
            $job = $pdo->query('SELECT * FROM payment_refund_dispatches')->fetch();
            assertSameValue('manual_required', $job['state']);
            assertSameValue('refund_history_unresolved', $job['last_error_code']);
            assertSameValue(null, $job['request_started_at']);
            assertSameValue(0, (int) $job['attempts']);
            assertSameValue(0, (int) $pdo->query('SELECT payment_refunded_cents FROM orders')->fetchColumn());
            assertSameValue(false, $pdo->inTransaction());
        });
    },
    'historical admin projection batches only unresolved non-current attempts with their immutable identities and amounts' => static function (): void {
        $pdo = externalRefundDatabase(); if ($pdo === null) return;
        refundHistorySeed($pdo);
        externalRefundAttempt($pdo, 3, 'unknown', null, null, 'full', 7500);
        externalRefundAttempt($pdo, 4, 'failed');
        $pdo->exec("INSERT INTO payment_refund_attempts (order_id, attempt, attempt_kind, stripe_refund_id, amount_cents, status)
            VALUES (2, 1, 'partial', 're_order2pending', 1200, 'pending'), (2, 2, 'full', NULL, 2000, 'unknown'),
                   (3, 1, 'full', 're_unrelated', 5000, 'pending')");
        $safety = new RefundLedgerSafety($pdo);
        assertSameValue([], $safety->historicalUnresolvedForOrders([]));
        $history = $safety->historicalUnresolvedForOrders([['id' => 1, 'refundAttempt' => 2], ['id' => 2, 'refundAttempt' => 2]]);
        assertSameValue([1, 2], array_keys($history));
        assertSameValue([1, 3], array_column($history[1], 'attempt'));
        assertSameValue('re_historyold', $history[1][0]['id']);
        assertSameValue(2500, $history[1][0]['amountCents']);
        assertSameValue(null, $history[1][1]['id']);
        assertSameValue('unknown', $history[1][1]['status']);
        assertSameValue(1, count($history[2]));
        assertSameValue('re_order2pending', $history[2][0]['id']);
    },
    'ledger safety requires a transaction and never permits a different unresolved attempt to be excluded accidentally' => static function (): void {
        $pdo = externalRefundDatabase(); if ($pdo === null) return;
        refundHistorySeed($pdo);
        $safety = new RefundLedgerSafety($pdo);
        try { $safety->assertNoUnresolved(1); throw new RuntimeException('A nontransactional financial check must be refused.'); }
        catch (LogicException) { assertSameValue(false, $pdo->inTransaction()); }
        $pdo->beginTransaction();
        try {
            expectExternalRefundConflict(static fn () => $safety->assertNoUnresolved(1, 2), 'refund_history_unresolved');
            $safety->assertNoUnresolved(1, 1);
            $pdo->rollBack();
        } finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
        assertSameValue('pending', $pdo->query('SELECT status FROM payment_refund_attempts WHERE attempt = 1')->fetchColumn());
    },
    'real admin payment handlers expose historical pending refunds and recheck all history without a new provider mutation' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            refundHistorySeed($pdo, 'succeeded'); $pdo->exec('UPDATE orders SET payment_refunded_cents = 5000');
            $refunds = refundHistoryProviderRows('succeeded'); $posts = [];
            $gateway = refundBalanceGateway($pdo, $refunds, $posts);
            $harness = new ExternalRefundIntegrationHarness($pdo, $gateway);
            refundHistoryReverse($harness, $refunds);
            $service = refundHistoryPaymentService($pdo, $gateway);
            $order = $service->adminOrders()['data'][0];
            assertSameValue('re_historycurrent', $order['refundId']);
            assertSameValue(2, $order['refundAttempt']);
            assertSameValue(true, $order['refundAttemptIsPartial']);
            assertSameValue(2500, $order['refundAttemptAmountCents']);
            assertSameValue(1, $order['historicalRefunds'][0]['attempt']);
            assertSameValue('re_historyold', $order['historicalRefunds'][0]['id']);
            assertSameValue('refund_history_checked', $service->cancelForAdmin([
                'orderNumber' => 'EXTERNAL-REFUND-1', 'action' => 'check_refund_history'], ['id' => 42])['code']);
            expectExternalRefundConflict(static fn () => $service->cancelForAdmin([
                'orderNumber' => 'EXTERNAL-REFUND-1', 'action' => 'cancel'], ['id' => 42]), 'refund_history_unresolved');
            expectExternalRefundConflict(static fn () => $service->partialRefundForAdmin([
                'orderNumber' => 'EXTERNAL-REFUND-1', 'amountCents' => 1000, 'reason' => 'An explicit admin refund request'], ['id' => 42]), 'refund_history_unresolved');
            assertSameValue([], $posts);
            assertSameValue(1, (int) $pdo->query("SELECT IS_FREE_LOCK('botanical-payment-order-1')")->fetchColumn());
            $refunds['re_historyold']['status'] = 'failed';
            $service->cancelForAdmin(['orderNumber' => 'EXTERNAL-REFUND-1', 'action' => 'check_refund_history'], ['id' => 42]);
            assertSameValue([], $service->adminOrders()['data'][0]['historicalRefunds']);
            assertSameValue(2500, $service->adminOrders()['data'][0]['refundedTotalCents']);
            assertSameValue([], $posts);
            $pdo->exec('UPDATE orders SET payment_refund_attempt = 99');
            $legacy = $service->adminOrders()['data'][0];
            assertSameValue(false, $legacy['refundAttemptIsPartial']);
            assertSameValue(null, $legacy['refundAttemptAmountCents']);
            assertSameValue(null, $legacy['refundAttemptReason']);
        });
    },
];
