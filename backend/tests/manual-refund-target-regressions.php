<?php

declare(strict_types=1);

/** Test-only construction of the exact selection; production accepts it solely from the request. */
function manualTargetForOrder(PDO $pdo, array $order): RefundResolutionTarget
{
    $query = $pdo->prepare('SELECT * FROM payment_refund_attempts WHERE order_id = :id AND attempt = :attempt');
    $query->execute([':id' => $order['id'], ':attempt' => $order['payment_refund_attempt']]);
    $ledger = $query->fetch() ?: null;
    return RefundResolutionTarget::fromInput(['refundTarget' => [
        'orderNumber' => $order['order_number'],
        'attempt' => (int) $order['payment_refund_attempt'],
        'refundId' => $ledger['stripe_refund_id'] ?? $order['payment_refund_id'] ?? null,
        'kind' => $ledger['attempt_kind'] ?? 'full',
        'amountCents' => $ledger === null ? null : (int) $ledger['amount_cents'],
        'refundedTotalCents' => (int) $order['payment_refunded_cents'],
        'outcome' => $order['payment_refund_outcome'],
        'totalCents' => (int) round((float) $order['total'] * 100), 'currency' => strtoupper((string) $order['currency']),
    ]]);
}

function manualTargetProjection(array $order): array
{
    return ['orderNumber' => $order['number'], 'attempt' => $order['refundAttempt'], 'refundId' => $order['refundId'],
        'kind' => $order['refundAttemptIsPartial'] ? 'partial' : 'full', 'amountCents' => $order['refundAttemptAmountCents'],
        'refundedTotalCents' => $order['refundedTotalCents'], 'outcome' => $order['refundOutcome'],
        'totalCents' => (int) round((float) $order['total'] * 100), 'currency' => $order['currency']];
}

function manualTargetRequest(array $target, string $resolution = 'not_refunded'): array
{
    return ['orderNumber' => 'EXTERNAL-REFUND-1', 'action' => 'resolve_refund', 'resolution' => $resolution,
        'reason' => 'Independently verified the exact displayed financial attempt', 'refundTarget' => $target];
}

function manualTargetFinancialState(PDO $pdo): array
{
    return [$pdo->query('SELECT * FROM orders')->fetchAll(),
        $pdo->query('SELECT * FROM payment_refund_attempts ORDER BY attempt')->fetchAll(),
        $pdo->query('SELECT * FROM admin_audit_log ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM payment_notification_outbox ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM products')->fetchAll()];
}

return [
    'real full manual resolution refuses a legacy request that cannot identify the attempt the administrator verified' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['refunded', 'not_refunded'] as $resolution) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                externalRefundAttempt($pdo, 1, 'manual', 'manual_not_refunded', 're_selectedold', 'full', 10000);
                externalRefundAttempt($pdo, 2, 'unknown', null, null, 'full', 10000);
                $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'unknown'");
                $refunds = []; $posts = [];
                $service = refundHistoryPaymentService($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                $before = $pdo->query('SELECT * FROM orders')->fetch();
                $ledger = $pdo->query('SELECT * FROM payment_refund_attempts ORDER BY attempt')->fetchAll();
                expectExternalRefundConflict(static fn () => $service->cancelForAdmin([
                    'orderNumber' => 'EXTERNAL-REFUND-1', 'action' => 'resolve_refund', 'resolution' => $resolution,
                    'reason' => 'The browser still showed the earlier manually verified attempt'], ['id' => 42]), 'refund_target_required');
                assertSameValue($before, $pdo->query('SELECT * FROM orders')->fetch());
                assertSameValue($ledger, $pdo->query('SELECT * FROM payment_refund_attempts ORDER BY attempt')->fetchAll());
                assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
                assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
                assertSameValue([], $posts);
                assertSameValue(false, $pdo->inTransaction());
            }
        });
    },
    'real partial manual resolution refuses a legacy request that cannot identify the attempt the administrator verified' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['refunded', 'not_refunded'] as $resolution) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                externalRefundAttempt($pdo, 1, 'manual', 'manual_not_refunded', 're_selectedold', 'partial', 2500);
                externalRefundAttempt($pdo, 2, 'unknown', null, null, 'partial', 2500);
                $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'unknown'");
                $refunds = []; $posts = [];
                $service = refundHistoryPaymentService($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                $before = $pdo->query('SELECT * FROM orders')->fetch();
                $ledger = $pdo->query('SELECT * FROM payment_refund_attempts ORDER BY attempt')->fetchAll();
                expectExternalRefundConflict(static fn () => $service->cancelForAdmin([
                    'orderNumber' => 'EXTERNAL-REFUND-1', 'action' => 'resolve_refund', 'resolution' => $resolution,
                    'reason' => 'The browser still showed the earlier manually verified attempt'], ['id' => 42]), 'refund_target_required');
                assertSameValue($before, $pdo->query('SELECT * FROM orders')->fetch());
                assertSameValue($ledger, $pdo->query('SELECT * FROM payment_refund_attempts ORDER BY attempt')->fetchAll());
                assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
                assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
                assertSameValue([], $posts);
                assertSameValue(false, $pdo->inTransaction());
            }
        });
    },
    'a stale browser selection cannot resolve a newer full or partial attempt through the real admin handler' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['full', 'partial'] as $kind) foreach (['refunded', 'not_refunded'] as $resolution) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                $amount = $kind === 'full' ? 10000 : 2500;
                externalRefundAttempt($pdo, 1, 'unknown', null, null, $kind, $amount);
                $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'unknown'");
                $refunds = []; $posts = [];
                $service = refundHistoryPaymentService($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                $target = manualTargetProjection($service->adminOrders()['data'][0]);
                $pdo->exec("UPDATE payment_refund_attempts SET status = 'manual', provider_error_code = 'manual_not_refunded' WHERE attempt = 1");
                externalRefundAttempt($pdo, 2, 'unknown', null, null, $kind, $amount);
                $before = manualTargetFinancialState($pdo);
                expectExternalRefundConflict(static fn () => $service->cancelForAdmin(manualTargetRequest($target, $resolution), ['id' => 42]), 'refund_target_stale');
                assertSameValue($before, manualTargetFinancialState($pdo));
                assertSameValue([], $posts);
                assertSameValue(false, $pdo->inTransaction());
                assertSameValue(1, (int) $pdo->query("SELECT IS_FREE_LOCK('botanical-payment-order-1')")->fetchColumn());
            }
        });
    },
    'all displayed identity and balance fields must match even when the selected attempt number remains current' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['full', 'partial'] as $kind) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                externalRefundAttempt($pdo, 1, 'unknown', null, 're_selectedcurrent', $kind, $kind === 'full' ? 10000 : 2500);
                $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'unknown'");
                $refunds = []; $posts = [];
                $service = refundHistoryPaymentService($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                $target = manualTargetProjection($service->adminOrders()['data'][0]);
                foreach (['orderNumber' => 'EXTERNAL-REFUND-2', 'attempt' => 2, 'refundId' => null,
                    'kind' => $kind === 'full' ? 'partial' : 'full', 'amountCents' => 1000,
                    'refundedTotalCents' => 1, 'outcome' => 'pending', 'totalCents' => 9000, 'currency' => 'USD'] as $field => $value) {
                    $before = manualTargetFinancialState($pdo);
                    expectExternalRefundConflict(static fn () => $service->cancelForAdmin(manualTargetRequest([...$target, $field => $value]), ['id' => 42]), 'refund_target_stale');
                    assertSameValue($before, manualTargetFinancialState($pdo));
                }
                assertSameValue([], $posts);
                assertSameValue(false, $pdo->inTransaction());
            }
        });
    },
    'a newly bound provider identity invalidates an earlier unknown-reference selection and is projected from the ledger' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['full', 'partial'] as $kind) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                externalRefundAttempt($pdo, 1, 'unknown', null, null, $kind, $kind === 'full' ? 10000 : 2500);
                $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'unknown'");
                $refunds = []; $posts = [];
                $service = refundHistoryPaymentService($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                $target = manualTargetProjection($service->adminOrders()['data'][0]);
                assertSameValue(null, $target['refundId']);
                $pdo->exec("UPDATE payment_refund_attempts SET stripe_refund_id = 're_boundafterverification'");
                assertSameValue(null, $pdo->query('SELECT payment_refund_id FROM orders')->fetchColumn());
                assertSameValue('re_boundafterverification', $service->adminOrders()['data'][0]['refundId']);
                $before = manualTargetFinancialState($pdo);
                expectExternalRefundConflict(static fn () => $service->cancelForAdmin(manualTargetRequest($target), ['id' => 42]), 'refund_target_stale');
                assertSameValue($before, manualTargetFinancialState($pdo));
                $fresh = manualTargetProjection($service->adminOrders()['data'][0]);
                $service->cancelForAdmin(manualTargetRequest($fresh), ['id' => 42]);
                assertSameValue('manual_not_refunded', $pdo->query('SELECT provider_error_code FROM payment_refund_attempts')->fetchColumn());
                assertSameValue(0, (int) $pdo->query('SELECT payment_refunded_cents FROM orders')->fetchColumn());
                assertSameValue([], $posts);
            }
        });
    },
    'a fresh selection permits both manual outcomes once without a Stripe request or changing unrelated attempts' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach (['full', 'partial'] as $kind) foreach (['refunded', 'not_refunded'] as $resolution) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                $amount = $kind === 'full' ? 10000 : 2500;
                externalRefundAttempt($pdo, 1, 'unknown', null, 're_exactselected', $kind, $amount);
                $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'unknown'");
                $refunds = []; $posts = [];
                $service = refundHistoryPaymentService($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                $target = manualTargetProjection($service->adminOrders()['data'][0]);
                assertSameValue('refund_resolved_manually', $service->cancelForAdmin(manualTargetRequest($target, $resolution), ['id' => 42])['code']);
                assertSameValue('manual', $pdo->query('SELECT status FROM payment_refund_attempts')->fetchColumn());
                assertSameValue($resolution === 'refunded' ? $amount : 0, (int) $pdo->query('SELECT payment_refunded_cents FROM orders')->fetchColumn());
                assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
                $settled = manualTargetFinancialState($pdo);
                expectExternalRefundConflict(static fn () => $service->cancelForAdmin(manualTargetRequest($target, $resolution), ['id' => 42]));
                assertSameValue($settled, manualTargetFinancialState($pdo));
                assertSameValue([], $posts);
            }
        });
    },
    'legacy full backfill requires an explicit null amount and unchanged displayed balance before creating a ledger row' => static function (): void {
        externalRefundEnvironment(static function (): void {
            foreach ([false, true] as $balanceChanged) {
                $pdo = externalRefundDatabase(); if ($pdo === null) return;
                $pdo->exec("UPDATE orders SET payment_refund_attempt = 1, payment_status = 'refund_pending', payment_refund_outcome = 'unknown'");
                $refunds = []; $posts = [];
                $service = refundHistoryPaymentService($pdo, refundBalanceGateway($pdo, $refunds, $posts));
                $target = manualTargetProjection($service->adminOrders()['data'][0]);
                assertSameValue(null, $target['amountCents']);
                if ($balanceChanged) {
                    $pdo->exec('UPDATE orders SET payment_refunded_cents = 2500');
                    $before = manualTargetFinancialState($pdo);
                    expectExternalRefundConflict(static fn () => $service->cancelForAdmin(manualTargetRequest($target), ['id' => 42]), 'refund_target_stale');
                    assertSameValue($before, manualTargetFinancialState($pdo));
                } else {
                    $service->cancelForAdmin(manualTargetRequest($target), ['id' => 42]);
                    assertSameValue('full', $pdo->query('SELECT attempt_kind FROM payment_refund_attempts')->fetchColumn());
                    assertSameValue(10000, (int) $pdo->query('SELECT amount_cents FROM payment_refund_attempts')->fetchColumn());
                }
                assertSameValue([], $posts);
            }
        });
    },
    'malformed targets and targets attached to another admin action are rejected without any financial mutation' => static function (): void {
        externalRefundEnvironment(static function (): void {
            $pdo = externalRefundDatabase(); if ($pdo === null) return;
            externalRefundAttempt($pdo, 1, 'unknown', null, null, 'full', 10000);
            $pdo->exec("UPDATE orders SET payment_status = 'refund_pending', payment_refund_outcome = 'unknown'");
            $refunds = []; $posts = [];
            $service = refundHistoryPaymentService($pdo, refundBalanceGateway($pdo, $refunds, $posts));
            $target = manualTargetProjection($service->adminOrders()['data'][0]);
            foreach ([null, true, [], [...$target, 'attempt' => '1'], [...$target, 'amountCents' => 1.5],
                [...$target, 'kind' => 'other'], [...$target, 'refundId' => 'pi_wrongtype'], [...$target, 'extra' => 'ignored?']] as $invalid) {
                $before = manualTargetFinancialState($pdo);
                $input = manualTargetRequest($target); $input['refundTarget'] = $invalid;
                try { $service->cancelForAdmin($input, ['id' => 42]); throw new RuntimeException('Malformed manual targets must be refused.'); }
                catch (HttpException $exception) { assertSameValue(422, $exception->status); }
                assertSameValue($before, manualTargetFinancialState($pdo));
            }
            $input = manualTargetRequest($target); $input['action'] = 'check_refund_history'; unset($input['resolution'], $input['reason']);
            try { $service->cancelForAdmin($input, ['id' => 42]); throw new RuntimeException('Unrelated actions must not accept manual selections.'); }
            catch (HttpException $exception) { assertSameValue(422, $exception->status); }
            assertSameValue([], $posts);
        });
    },
    'manual selection matching itself refuses use outside a financial transaction' => static function (): void {
        $pdo = externalRefundDatabase(); if ($pdo === null) return;
        externalRefundAttempt($pdo, 1, 'unknown', null, null, 'full', 10000);
        $pdo->exec("UPDATE orders SET payment_refund_outcome = 'unknown'");
        $order = $pdo->query('SELECT * FROM orders')->fetch();
        $ledger = $pdo->query('SELECT * FROM payment_refund_attempts')->fetch();
        $target = manualTargetForOrder($pdo, $order);
        try { $target->assertMatches($pdo, $order, $ledger); throw new RuntimeException('Nontransactional matching must fail closed.'); }
        catch (LogicException) { assertSameValue(false, $pdo->inTransaction()); }
    },
];
