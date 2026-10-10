<?php

declare(strict_types=1);

function refundBalanceRow(string $id, int $amount, int $attempt): array
{
    return [...externalRefundRow($id, 'succeeded', $amount),
        'metadata' => ['order_number' => 'EXTERNAL-REFUND-1', 'refund_attempt' => (string) $attempt]];
}

function refundBalanceGateway(PDO $pdo, array &$refunds, array &$posts, ?Closure $onPost = null): StripeGateway
{
    return new StripeGateway(static function (string $url, $context) use ($pdo, &$refunds, &$posts, $onPost): array {
        $http = stream_context_get_options($context)['http'];
        assertSameValue(false, $pdo->inTransaction(), 'Stripe I/O must stay outside financial transactions.');
        if ($http['method'] === 'GET') {
            if (parse_url($url, PHP_URL_PATH) !== '/v1/refunds') {
                $id = basename((string) parse_url($url, PHP_URL_PATH));
                assertSameValue(true, isset($refunds[$id]), 'Only a known fixture refund may be retrieved.');
                return ['body' => json_encode($refunds[$id], JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK'];
            }
            return ['body' => json_encode(['object' => 'list', 'data' => array_values($refunds), 'has_more' => false], JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK'];
        }
        assertSameValue('POST', $http['method']);
        assertSameValue('/v1/refunds', parse_url($url, PHP_URL_PATH));
        parse_str($http['content'], $body);
        $posts[] = $body;
        if ($onPost !== null) return $onPost($body);
        $refund = refundBalanceRow('re_balancefull', (int) ($body['amount'] ?? 10000), (int) $body['metadata']['refund_attempt']);
        return ['body' => json_encode($refund, JSON_THROW_ON_ERROR), 'statusLine' => 'HTTP/1.1 200 OK'];
    });
}

return [
    'manual non-refund resolution derives payment status from money confirmed by other attempts' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['partial', 'full'] as $kind) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                externalRefundAttempt($pdo, 1, 'pending', null, 're_older');
                externalRefundAttempt($pdo, 2, 'unknown', null, null, $kind, $kind === 'full' ? 7500 : 1000);
                $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'unknown'");
                $refunds = ['re_older' => externalRefundRow('re_older')]; $calls = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
                externalRefundWebhook($harness, $refunds['re_older']);
                assertSameValue('refund_pending', $harness->order()['payment_status']);
                assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
                if ($kind === 'full') $harness->resolveFull('not_refunded');
                else $harness->resolvePartial('not_refunded');
                assertSameValue('partially_refunded', $harness->order()['payment_status']);
                assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
                assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
            }
        });
    },
    'full admin refunds send only the immutable exact remaining amount and cancel restock and notify once' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach ([0, 2500] as $alreadyRefunded) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                $refunds = []; $posts = [];
                if ($alreadyRefunded > 0) {
                    externalRefundAttempt($pdo, 1, 'succeeded', null, 're_older');
                    $pdo->exec("UPDATE orders SET payment_status = 'partially_refunded', payment_refunded_cents = 2500, payment_refund_outcome = 'succeeded'");
                    $refunds['re_older'] = refundBalanceRow('re_older', 2500, 1);
                }
                $harness = new ExternalRefundIntegrationHarness($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                $harness->startFull();
                assertSameValue(1, count($posts));
                assertSameValue((string) (10000 - $alreadyRefunded), $posts[0]['amount']);
                assertSameValue(10000 - $alreadyRefunded, (int) $pdo->query("SELECT amount_cents FROM payment_refund_attempts WHERE attempt_kind = 'full'")->fetchColumn());
                assertSameValue('cancelled', $harness->order()['status']);
                assertSameValue('refunded', $harness->order()['payment_status']);
                assertSameValue(10000, (int) $harness->order()['payment_refunded_cents']);
                externalRefundWebhook($harness, refundBalanceRow('re_balancefull', 10000 - $alreadyRefunded, $alreadyRefunded > 0 ? 2 : 1));
                assertSameValue(6, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
                assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM payment_notification_outbox WHERE notification_type = 'full_refund_confirmation'")->fetchColumn());
            }
        });
    },
    'full refund reservation uses fresh money recovered by the provider preflight not a stale order snapshot' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'manual', 'manual_not_refunded', 're_older');
            $pdo->exec("UPDATE orders SET payment_refund_outcome = 'manual_not_refunded'");
            $refunds = ['re_older' => refundBalanceRow('re_older', 2500, 1)]; $posts = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, refundBalanceGateway($pdo, $refunds, $posts));
            $harness->startFull();
            assertSameValue('7500', $posts[0]['amount']);
            assertSameValue(7500, (int) $pdo->query('SELECT amount_cents FROM payment_refund_attempts WHERE attempt = 2')->fetchColumn());
            assertSameValue('partially_refunded', $pdo->query('SELECT previous_payment_status FROM payment_refund_attempts WHERE attempt = 2')->fetchColumn());
            assertSameValue(10000, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue('cancelled', $harness->order()['status']);
            assertSameValue(6, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
        });
    },
    'an older partial credit arriving after full authorization never changes the amount or erases that credit on rejection' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'manual', 'manual_not_refunded', 're_older');
            $pdo->exec("UPDATE orders SET payment_refund_outcome = 'manual_not_refunded'");
            $refunds = []; $posts = []; $harness = null;
            $gateway = refundBalanceGateway($pdo, $refunds, $posts, static function () use (&$harness): array {
                externalRefundWebhook($harness, refundBalanceRow('re_older', 2500, 1), 'evt_older_during_full');
                return ['body' => '{"error":{"code":"amount_too_large","message":"Refund amount exceeds the remaining provider balance"}}', 'statusLine' => 'HTTP/1.1 400 Bad Request'];
            });
            $harness = new ExternalRefundIntegrationHarness($pdo, $gateway);
            $harness->startFull();
            assertSameValue(1, count($posts));
            assertSameValue('10000', $posts[0]['amount']);
            assertSameValue(10000, (int) $pdo->query('SELECT amount_cents FROM payment_refund_attempts WHERE attempt = 2')->fetchColumn());
            assertSameValue('failed', $pdo->query('SELECT status FROM payment_refund_attempts WHERE attempt = 2')->fetchColumn());
            assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
            assertSameValue('partially_refunded', $harness->order()['payment_status']);
            assertSameValue('confirmed', $harness->order()['status']);
            assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
        });
    },
    'a full provider rejection cannot undo signed success or a newer manual resolution' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['success', 'manual'] as $terminal) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                externalRefundAttempt($pdo, 1, 'pending', null, null, 'full', 10000);
                $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'pending'");
                $refunds = []; $calls = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
                if ($terminal === 'success') externalRefundWebhook($harness, refundBalanceRow('re_balancefull', 10000, 1));
                else $harness->resolveFull('not_refunded');
                $before = $harness->order();
                $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn();
                $harness->rejectFull(1);
                assertSameValue($before, $harness->order());
                assertSameValue($terminal === 'success' ? 'succeeded' : 'manual', $pdo->query('SELECT status FROM payment_refund_attempts')->fetchColumn());
                assertSameValue($auditCount, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
                assertSameValue(false, $pdo->inTransaction());
            }
        });
    },
    'full provider rejection commits the ledger order and audit together or rolls them all back' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'pending', null, null, 'full', 7500);
            $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'pending', payment_refunded_cents = 2500");
            $refunds = []; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            $pdo->exec('DROP TEMPORARY TABLE admin_audit_log');
            $pdo->exec('CREATE TEMPORARY TABLE admin_audit_log (unexpected_column INT) ENGINE=InnoDB');
            try {
                $harness->rejectFull(1);
                throw new RuntimeException('An unavailable audit must roll back the financial transition.');
            } catch (PDOException) {
                assertSameValue('refund_pending', $harness->order()['payment_status']);
                assertSameValue('pending', $harness->order()['payment_refund_outcome']);
                assertSameValue(2500, (int) $harness->order()['payment_refunded_cents']);
                assertSameValue('pending', $pdo->query('SELECT status FROM payment_refund_attempts')->fetchColumn());
                assertSameValue(false, $pdo->inTransaction());
            }
        });
    },
    'a stale partial manual decision cannot resolve another current pending attempt' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'unknown');
            $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'unknown'");
            $selected = $pdo->query('SELECT * FROM orders')->fetch();
            externalRefundAttempt($pdo, 2, 'unknown');
            $refunds = []; $calls = [];
            $harness = new ExternalRefundIntegrationHarness($pdo, externalRefundGateway($refunds, $calls));
            expectExternalRefundConflict(static fn () => $harness->resolvePartial('not_refunded', $selected));
            assertSameValue(2, (int) $harness->order()['payment_refund_attempt']);
            assertSameValue('refund_pending', $harness->order()['payment_status']);
            assertSameValue(['unknown', 'unknown'], $pdo->query('SELECT status FROM payment_refund_attempts ORDER BY attempt')->fetchAll(PDO::FETCH_COLUMN));
            assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
        });
    },
    'full cancellation cannot bypass an open return unresolved refund or invalid financial balance' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['return', 'pending', 'fully_refunded', 'over_refunded', 'negative'] as $blocked) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                if ($blocked === 'return') $pdo->exec("INSERT INTO withdrawal_requests VALUES (7, 1, 'submitted')");
                if ($blocked === 'pending') $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'unknown'");
                if ($blocked === 'fully_refunded') $pdo->exec('UPDATE orders SET payment_refunded_cents = 10000');
                if ($blocked === 'over_refunded') $pdo->exec('UPDATE orders SET payment_refunded_cents = 10001');
                if ($blocked === 'negative') $pdo->exec('UPDATE orders SET payment_refunded_cents = -1');
                $refunds = []; $posts = [];
                $harness = new ExternalRefundIntegrationHarness($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                expectExternalRefundConflict($harness->startFull(...));
                assertSameValue([], $posts);
                assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_refund_attempts')->fetchColumn());
                assertSameValue(5, (int) $pdo->query('SELECT stock FROM products')->fetchColumn());
                assertSameValue(false, $pdo->inTransaction());
            }
        });
    },
    'settled refund projection and retry policy preserve all three cumulative financial states' => static function (): void {
        assertSameValue('paid', RefundStatePolicy::settledPaymentStatus(0, 10000));
        assertSameValue('partially_refunded', RefundStatePolicy::settledPaymentStatus(2500, 10000));
        assertSameValue('refunded', RefundStatePolicy::settledPaymentStatus(10000, 10000));
        foreach ([[-1, 10000], [10001, 10000], [0, 0]] as [$refunded, $total]) {
            expectExternalRefundConflict(static fn () => RefundStatePolicy::settledPaymentStatus($refunded, $total), 'refund_balance_invalid');
        }
        assertSameValue(true, RefundStatePolicy::canStartNewAttempt('partially_refunded', 'definitive_failure'));
        assertSameValue(true, RefundStatePolicy::canStartNewAttempt('partially_refunded', 'manual_not_refunded'));
        assertSameValue(false, RefundStatePolicy::canStartNewAttempt('partially_refunded', 'unknown'));
    },
];
