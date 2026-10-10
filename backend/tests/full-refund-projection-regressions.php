<?php

declare(strict_types=1);

function refundProjectionSeed(PDO $pdo, ?int $withdrawalId = null): void
{
    externalRefundAttempt($pdo, 1, 'succeeded', null, 're_prior');
    externalRefundAttempt($pdo, 2, 'pending', null, 're_projection', 'full', 7500);
    $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refunded_cents = 2500,
        payment_refund_outcome = 'pending', payment_refund_id = 're_projection'");
    if ($withdrawalId !== null) {
        $pdo->exec("INSERT INTO withdrawal_requests VALUES ($withdrawalId, 1, 'refund_pending')");
        $pdo->exec('ALTER TABLE withdrawal_requests ADD handled_by_admin_id BIGINT NULL');
        $pdo->exec('ALTER TABLE withdrawal_request_events ADD actor_user_id BIGINT NULL');
        $pdo->exec("UPDATE payment_refund_attempts SET withdrawal_request_id = $withdrawalId WHERE attempt = 2");
        $pdo->exec("UPDATE orders SET status = 'delivered'");
    }
}

function refundProjectionReverse(ExternalRefundIntegrationHarness $harness, array $refund): void
{
    externalRefundWebhook($harness, $refund, 'evt_projection_bankreturn');
    $harness->externalRefunds->resolve(['refundId' => 're_prior', 'action' => 'reverse', 'attempt' => 1,
        'reason' => 'The current provider response confirms this previous credit was returned'], ['id' => 42]);
    assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
    assertSameValue('refund_pending', $harness->order()['payment_status']);
}

function refundProjectionOutbox(PDO $pdo, StripeGateway $gateway, ExternalRefundIntegrationHarness $harness): array
{
    $mailer = new class implements MailTransport {
        public array $sent = [];
        public function send(string $recipient, string $subject, string $text): void { $this->sent[] = [$subject, $text]; }
    };
    $operations = new PaymentOperationsService($pdo, $gateway, new PaymentOrderLock($pdo), $mailer,
        static fn () => null, static fn () => null, $harness->apply(...));
    $operations->dispatchOutbox();
    return $mailer->sent;
}

return [
    'full refund success after an older bank return is counted once without prematurely cancelling or restocking' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            refundProjectionSeed($pdo);
            $refunds = ['re_prior' => externalRefundRow('re_prior', 'failed')]; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            refundProjectionReverse($harness, $refunds['re_prior']);
            $refund = refundBalanceRow('re_projection', 7500, 2);
            externalRefundWebhook($harness, $refund, 'evt_projection_success');
            externalRefundWebhook($harness, $refund, 'evt_projection_success');
            externalRefundWebhook($harness, $refund, 'evt_projection_repeat');
            assertSameValue(7500, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue('partially_refunded', $harness->order()['payment_status']);
            assertSameValue('confirmed', $harness->order()['status']);
            assertSameValue('succeeded', $pdo->query('SELECT status FROM payment_refund_attempts WHERE attempt = 2')->fetchColumn());
            assertSameValue('refund_balance_remaining', $harness->order()['payment_refund_error_code']);
            assertSameValue('refund_balance_remaining', $harness->fullResult($refund)['code']);
            assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM payment_notification_outbox WHERE notification_type = 'refund_confirmation' AND reference_id = '2'")->fetchColumn());
            assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM payment_notification_outbox WHERE notification_type = 'full_refund_confirmation'")->fetchColumn());
            assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM admin_audit_log WHERE action_name = 'order.full_refund_completed'")->fetchColumn());
            $messages = refundProjectionOutbox($pdo, externalRefundGateway($refunds, $calls), $harness);
            assertSameValue(2, count($messages));
            assertTrueValue(str_contains(implode(' ', array_column($messages, 1)), 'A refund of €75.00 EUR'));
            assertTrueValue(!str_contains(implode(' ', array_column($messages, 1)), 'A full refund'));
        });
    },
    'only a new explicit full refund may return the post-reversal remaining balance and release stock once' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            refundProjectionSeed($pdo);
            $refunds = ['re_prior' => externalRefundRow('re_prior', 'failed')]; $posts = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, refundBalanceGateway($pdo, $refunds, $posts));
            refundProjectionReverse($harness, $refunds['re_prior']);
            $refunds['re_projection'] = refundBalanceRow('re_projection', 7500, 2);
            externalRefundWebhook($harness, $refunds['re_projection'], 'evt_projection_success');
            assertSameValue([], $posts, 'Recording provider success must not authorize another refund.');
            assertSameValue('refund_balance_confirmed', $harness->startFull()['code']);
            assertSameValue(1, count($posts)); assertSameValue('2500', $posts[0]['amount']);
            assertSameValue('refunded', $harness->order()['payment_status']);
            assertSameValue('cancelled', $harness->order()['status']);
            assertSameValue(10000, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue(6, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            externalRefundWebhook($harness, refundBalanceRow('re_balancefull', 2500, 3), 'evt_projection_last_repeat');
            assertSameValue(6, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
        });
    },
    'an older full success preserves a newer pending refund identity outcome and attempt' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'succeeded', null, 're_prior');
            externalRefundAttempt($pdo, 2, 'manual', 'manual_not_refunded', 're_projection', 'full', 7500);
            externalRefundAttempt($pdo, 3, 'pending', null, 're_newpending', 'partial', 1000);
            $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refunded_cents = 2500,
                payment_refund_outcome = 'pending', payment_refund_id = 're_newpending', payment_refund_error_code = 'new_attempt_marker'");
            $refunds = []; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            externalRefundWebhook($harness, refundBalanceRow('re_projection', 7500, 2));
            externalRefundWebhook($harness, refundBalanceRow('re_projection', 7500, 2), 'evt_projection_older_repeat');
            assertSameValue(10000, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue('refund_pending', $harness->order()['payment_status']);
            assertSameValue('pending', $harness->order()['payment_refund_outcome']);
            assertSameValue('re_newpending', $harness->order()['payment_refund_id']);
            assertSameValue('new_attempt_marker', $harness->order()['payment_refund_error_code']);
            assertSameValue(3, (int) $harness->order()['payment_refund_attempt']);
            assertSameValue('cancelled', $harness->order()['status']);
            assertSameValue(6, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM payment_notification_outbox WHERE notification_type = 'full_refund_confirmation'")->fetchColumn());
            $failure = [...refundBalanceRow('re_newpending', 1000, 3), 'status' => 'failed', 'failure_reason' => 'declined'];
            externalRefundWebhook($harness, $failure, 'evt_projection_new_failure');
            assertSameValue('refunded', $harness->order()['payment_status']);
            assertSameValue(10000, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue(6, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
        });
    },
    'a manually counted full attempt with a later balance shortfall is never counted or restocked again' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'manual', 'manual_refunded', 're_projection', 'full', 7500);
            $pdo->exec("UPDATE orders SET status = 'cancelled', payment_status = 'partially_refunded',
                payment_refunded_cents = 7500, payment_refund_outcome = 'manual_refunded'");
            $refunds = []; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            externalRefundWebhook($harness, refundBalanceRow('re_projection', 7500, 1));
            externalRefundWebhook($harness, refundBalanceRow('re_projection', 7500, 1), 'evt_projection_manual_repeat');
            assertSameValue(7500, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue('partially_refunded', $harness->order()['payment_status']);
            assertSameValue('cancelled', $harness->order()['status']);
            assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM payment_notification_outbox WHERE notification_type = 'refund_confirmation'")->fetchColumn());
        });
    },
    'an already counted older full success can finalize its ledger while the newer order projection is unchanged' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'manual', 'manual_refunded', 're_projection', 'full', 7500);
            externalRefundAttempt($pdo, 2, 'pending', null, 're_newpending', 'partial', 500);
            $pdo->exec("UPDATE orders SET status = 'cancelled', payment_status = 'refund_pending',
                payment_refunded_cents = 7500, payment_refund_outcome = 'pending', payment_refund_id = 're_newpending'");
            $refunds = []; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            $before = $harness->order();
            externalRefundWebhook($harness, refundBalanceRow('re_projection', 7500, 1));
            externalRefundWebhook($harness, refundBalanceRow('re_projection', 7500, 1), 'evt_projection_unchanged_repeat');
            assertSameValue($before, $harness->order());
            assertSameValue('succeeded', $pdo->query('SELECT status FROM payment_refund_attempts WHERE attempt = 1')->fetchColumn());
            assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM payment_notification_outbox WHERE notification_type = 'refund_confirmation'")->fetchColumn());
        });
    },
    'a valid full success is recorded after fulfillment changes without cancelling or restocking shipped goods' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['shipped', 'delivered'] as $status) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                externalRefundAttempt($pdo, 1, 'pending', null, 're_projection', 'full', 10000);
                $pdo->prepare("UPDATE orders SET status = :status, payment_status = 'refund_pending', payment_refund_outcome = 'pending'")
                    ->execute([':status' => $status]);
                $refunds = []; $calls = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
                externalRefundWebhook($harness, refundBalanceRow('re_projection', 10000, 1));
                externalRefundWebhook($harness, refundBalanceRow('re_projection', 10000, 1), 'evt_projection_delivery_repeat');
                assertSameValue(10000, (int) $harness->order()['payment_refunded_cents']);
                assertSameValue('refunded', $harness->order()['payment_status']);
                assertSameValue($status, $harness->order()['status']);
                assertSameValue('refund_fulfillment_review_required', $harness->order()['payment_refund_error_code']);
                assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            }
        });
    },
    'full return refund shortfall keeps the consumer case actionable until the remaining amount is explicitly refunded' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            refundProjectionSeed($pdo, 7);
            $refunds = ['re_prior' => externalRefundRow('re_prior', 'failed')]; $posts = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, refundBalanceGateway($pdo, $refunds, $posts));
            refundProjectionReverse($harness, $refunds['re_prior']);
            $refunds['re_projection'] = refundBalanceRow('re_projection', 7500, 2);
            externalRefundWebhook($harness, $refunds['re_projection'], 'evt_projection_return_success');
            assertSameValue('return_received', $pdo->query('SELECT status FROM withdrawal_requests')->fetchColumn());
            assertSameValue('refund.balance_remaining', $pdo->query('SELECT event_name FROM withdrawal_request_events')->fetchColumn());
            assertSameValue('delivered', $harness->order()['status']);
            assertSameValue([], $posts);
            $harness->startFull(7);
            assertSameValue('2500', $posts[0]['amount']);
            assertSameValue('refunded', $pdo->query('SELECT status FROM withdrawal_requests')->fetchColumn());
            assertSameValue('delivered', $harness->order()['status']);
            assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
        });
    },
    'refund receipts never assert manual non-refunds or known unreviewed returned credits as confirmed money' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['manual_not_refunded', 'returned', 'aggregate_unreviewed'] as $blocked) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                $status = $blocked === 'manual_not_refunded' ? 'manual' : 'succeeded';
                externalRefundAttempt($pdo, 1, $status, $blocked === 'manual_not_refunded' ? $blocked : null, 're_prior', 'full', 7500);
                $pdo->exec("UPDATE orders SET payment_status = 'partially_refunded', payment_refunded_cents = 7500");
                $refunds = ['re_prior' => externalRefundRow('re_prior', 'failed', 7500)]; $calls = [];
                $gateway = externalRefundGateway($refunds, $calls);
                $harness = new ExternalRefundIntegrationHarness($pdo, $gateway);
                if ($blocked !== 'manual_not_refunded') externalRefundWebhook($harness, $refunds['re_prior']);
                if ($blocked === 'aggregate_unreviewed') {
                    $pdo->exec("UPDATE orders SET payment_status = 'refunded', payment_refunded_cents = 10000");
                    $pdo->exec("INSERT INTO payment_notification_outbox (order_id, notification_type, reference_id) VALUES (1, 'full_refund_confirmation', 're_other')");
                } else {
                    $pdo->exec("INSERT INTO payment_notification_outbox (order_id, notification_type, reference_id) VALUES (1, 'refund_confirmation', '1')");
                }
                assertSameValue([], refundProjectionOutbox($pdo, $gateway, $harness));
                assertSameValue(0, (int) $pdo->query("SELECT COUNT(*) FROM payment_notification_outbox WHERE status = 'sent'")->fetchColumn());
            }
        });
    },
    'shortfall reconciliation rolls back ledger money and webhook marker if its accurate confirmation cannot be queued' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'pending', null, 're_projection', 'full', 7500);
            $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'pending'");
            $pdo->exec('DROP TEMPORARY TABLE payment_notification_outbox');
            $pdo->exec('CREATE TEMPORARY TABLE payment_notification_outbox (unexpected_column INT) ENGINE=InnoDB');
            $refunds = []; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            try {
                externalRefundWebhook($harness, refundBalanceRow('re_projection', 7500, 1));
                throw new RuntimeException('An unavailable confirmation queue must roll back the transition.');
            } catch (PDOException) {
                assertSameValue(0, (int) $harness->order()['payment_refunded_cents']);
                assertSameValue('pending', $pdo->query('SELECT status FROM payment_refund_attempts')->fetchColumn());
                assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_webhook_events')->fetchColumn());
                assertSameValue(false, $pdo->inTransaction());
            }
        });
    },
];
