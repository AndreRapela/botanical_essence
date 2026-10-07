<?php
declare(strict_types=1);

final class PaymentService
{
    use PaymentWebhookActions;
    use RefundAdminActions;
    use RefundAdminResultActions;
    use PartialRefundActions;

    private readonly LatePaymentRecoveryService $latePaymentRecovery;
    private readonly PaymentOrderLock $orderLock;
    private readonly CheckoutSessionService $checkout;
    private readonly CheckoutRecoveryService $checkoutRecovery;
    private readonly WithdrawalService $withdrawals;
    private readonly PaymentOperationsService $operations;
    private readonly UnattachedCheckoutResolver $unattachedCheckoutResolver;
    private readonly ExternalRefundService $externalRefunds;

    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $limiter,
        OrderService $orders,
        private readonly InventoryService $inventory,
        private readonly StripeGateway $stripe = new StripeGateway(),
        ?WithdrawalService $withdrawals = null,
        ?Mailer $mailer = null
    ) {
        $this->withdrawals = $withdrawals ?? new WithdrawalService($pdo, $limiter, new Mailer());
        $this->unattachedCheckoutResolver = new UnattachedCheckoutResolver($pdo);
        $this->latePaymentRecovery = new LatePaymentRecoveryService($pdo, $this->stripe);
        $this->orderLock = new PaymentOrderLock($pdo);
        $this->checkout = new CheckoutSessionService(
            $pdo,
            $limiter,
            $orders,
            $this->stripe,
            $this->orderLock,
            fn (array $session, bool $paid): mixed => $this->applyCheckoutEvent($session, $paid),
            fn (int $orderId, string $status): mixed => $this->cancelUnpaidOrder($orderId, $status)
        );
        $this->checkoutRecovery = new CheckoutRecoveryService(
            $pdo,
            $limiter,
            $this->orderLock,
            $this->stripe,
            fn (array $session, bool $paid): mixed => $this->applyCheckoutEvent($session, $paid),
            fn (int $orderId, string $status): mixed => $this->cancelUnpaidOrder($orderId, $status)
        );
        $this->externalRefunds = new ExternalRefundService($pdo, $this->stripe, $limiter, $this->orderLock,
            fn (array $refund): mixed => $this->applyRefundEvent($refund));
        $this->operations = new PaymentOperationsService(
            $pdo,
            $this->stripe,
            $this->orderLock,
            $mailer ?? new Mailer(),
            fn (array $session, bool $paid): mixed => $this->applyCheckoutEvent($session, $paid),
            fn (int $orderId, string $status): mixed => $this->cancelUnpaidOrder($orderId, $status),
            fn (array $refund): mixed => $this->applyRefundEvent($refund),
            recoverExternalRefunds: fn (int $limit): int => $this->externalRefunds->recover($limit)
        );
    }

    public function startCheckout(array $input, array $user): array
    {
        return $this->checkout->startCheckout($input, $user);
    }

    public function resumeCheckout(array $input, array $user): array
    {
        return $this->checkout->resumeCheckout($input, $user);
    }

    public function sessionStatus(array $payload, array $user): array { return $this->checkoutRecovery->statusBySession($payload, $user); }

    public function checkoutOrderStatus(array $payload, array $user): array
    {
        return $this->checkoutRecovery->status($payload, $user);
    }

    public function cancelCheckoutOrder(array $input, array $user): array
    {
        return $this->checkoutRecovery->cancel($input, $user);
    }

    public function runPaymentOperationsBatch(int $limit = 50): array
    {
        return $this->operations->runBatch($limit);
    }

    public function adminDisputes(array $query = []): array
    {
        return $this->operations->adminDisputes($query);
    }

    public function externalRefundIssues(array $query = []): array
    {
        return $this->externalRefunds->issues($query);
    }

    public function resolveExternalRefund(array $input, array $admin): array
    {
        return $this->externalRefunds->resolve($input, $admin);
    }



    public function adminOrders(array $query = []): array
    {
        $this->inventory->releaseExpiredReservations();
        $pageValue = $query['page'] ?? null;
        $limitValue = $query['limit'] ?? null;
        $page = is_string($pageValue) && ctype_digit($pageValue) ? max(1, min((int) $pageValue, 1_000_000)) : 1;
        $limit = is_string($limitValue) && ctype_digit($limitValue) ? max(1, min((int) $limitValue, 100)) : 50;
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $offset = ($page - 1) * $limit;
        $statement = $this->pdo->prepare(
            "SELECT o.id, o.order_number AS number, o.customer_name AS customerName, o.email,
                    o.status, o.payment_status AS paymentStatus, o.payment_method AS paymentMethod,
                    o.payment_provider AS paymentProvider, o.test_payment AS testPayment,
                    DATE_FORMAT(o.payment_session_attempted_at, '%Y-%m-%dT%H:%i:%sZ') AS paymentSessionAttemptedAt,
                    (o.payment_provider = 'stripe' AND o.status = 'awaiting_payment' AND o.payment_status = 'pending'
                     AND o.payment_session_id IS NULL) AS paymentSessionUnconfirmed,
                    (o.payment_provider = 'stripe' AND o.status IN ('awaiting_payment','cancelled') AND o.payment_status = 'pending'
                     AND o.payment_session_id IS NULL
                     AND (o.payment_session_attempted_at <= CURRENT_TIMESTAMP - INTERVAL 23 HOUR
                          OR (o.payment_session_attempted_at IS NULL
                              AND o.created_at <= CURRENT_TIMESTAMP - INTERVAL 23 HOUR))) AS paymentReconciliationRequired,
                    o.payment_refund_id AS refundId, o.payment_refund_attempt AS refundAttempt,
                    o.payment_refund_outcome AS refundOutcome,
                    o.payment_refund_error_code AS refundErrorCode,
                    o.payment_refund_error_message AS refundErrorMessage,
                    o.payment_refund_error_http_status AS refundErrorHttpStatus,
                    o.payment_refunds_scan_error AS refundScanError,
                    EXISTS(SELECT 1 FROM payment_external_refunds er WHERE er.order_id = o.id AND er.resolution = 'unmatched') AS externalRefundUnmatched,
                    o.payment_refunded_cents AS refundedTotalCents,
                    EXISTS(SELECT 1 FROM payment_refund_attempts pra
                           WHERE pra.order_id = o.id AND pra.attempt = o.payment_refund_attempt AND pra.attempt_kind = 'partial') AS refundAttemptIsPartial,
                    (SELECT pra.amount_cents FROM payment_refund_attempts pra
                     WHERE pra.order_id = o.id AND pra.attempt = o.payment_refund_attempt LIMIT 1) AS refundAttemptAmountCents,
                    (SELECT pra.reason FROM payment_refund_attempts pra
                     WHERE pra.order_id = o.id AND pra.attempt = o.payment_refund_attempt LIMIT 1) AS refundAttemptReason,
                    o.subtotal, o.shipping_amount AS shipping, o.total, o.currency,
                    DATE_FORMAT(o.created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt
             FROM orders o ORDER BY paymentReconciliationRequired DESC, o.id DESC LIMIT :limit OFFSET :offset"
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $orders = $statement->fetchAll();
        foreach ($orders as &$order) {
            $order['id'] = (int) $order['id'];
            $order['testPayment'] = (bool) $order['testPayment'];
            $order['paymentSessionUnconfirmed'] = (bool) $order['paymentSessionUnconfirmed'];
            $order['paymentReconciliationRequired'] = (bool) $order['paymentReconciliationRequired'];
            $order['externalRefundUnmatched'] = (bool) $order['externalRefundUnmatched'];
            $order['refundAttempt'] = (int) $order['refundAttempt'];
            $order['refundedTotalCents'] = (int) $order['refundedTotalCents'];
            $order['refundAttemptIsPartial'] = (bool) $order['refundAttemptIsPartial'];
            $order['refundAttemptAmountCents'] = $order['refundAttemptAmountCents'] === null ? null : (int) $order['refundAttemptAmountCents'];
            $order['refundErrorHttpStatus'] = $order['refundErrorHttpStatus'] === null
                ? null
                : (int) $order['refundErrorHttpStatus'];
            $order['subtotal'] = (float) $order['subtotal'];
            $order['shipping'] = (float) $order['shipping'];
            $order['total'] = (float) $order['total'];
            $items = $this->orderItems($order['id']);
            $order['items'] = array_map(static fn (array $item): array => [
                'name' => $item['productName'],
                'quantity' => (int) $item['quantity'],
            ], $items);
        }
        unset($order);
        return ['data' => $orders, 'page' => $page, 'limit' => $limit, 'total' => $total, 'hasMore' => $offset + count($orders) < $total];
    }

    public function cancelForAdmin(array $input, array $admin): array
    {
        Validation::allowedFields($input, ['orderNumber', 'action', 'resolution', 'reason']);
        $number = Validation::requiredString($input, 'orderNumber', 8, 32);
        $action = isset($input['action'])
            ? Validation::oneOf($input, 'action', ['cancel', 'check_refund', 'retry_refund', 'resolve_refund', 'resolve_checkout_attempt'])
            : 'cancel';
        $resolution = $action === 'resolve_refund'
            ? Validation::oneOf($input, 'resolution', ['refunded', 'not_refunded'])
            : null;
        $reason = in_array($action, ['resolve_refund', 'resolve_checkout_attempt'], true)
            ? Validation::requiredString($input, 'reason', 10, 500)
            : null;
        $this->limiter->assertAllowed('admin-write', (string) $admin['id'], 300, 3600);
        $order = $this->orderForAdmin($number);
        if ($order === null) {
            throw new HttpException('Pedido não encontrado.', 404);
        }

        $orderId = (int) $order['id'];
        $this->orderLock->acquire($orderId);
        try {
            return $this->cancelForAdminWithPaymentLock($number, $admin, $action, $resolution, $reason);
        } finally {
            $this->orderLock->release($orderId);
        }
    }

    public function adminWithdrawalAction(array $input, array $admin): array
    {
        Validation::allowedFields($input, ['requestId', 'action', 'note']);
        $requestId = Validation::integer($input, 'requestId', 1, PHP_INT_MAX);
        $action = Validation::oneOf($input, 'action', [
            'review', 'send_instructions', 'mark_received', 'not_eligible', 'refund', 'check_refund', 'retry_refund',
        ]);
        if (!in_array($action, ['refund', 'check_refund', 'retry_refund'], true)) {
            return $this->withdrawals->adminTransition($input, $admin);
        }

        $this->limiter->assertAllowed('admin-payment', (string) $admin['id'], 100, 3600);
        $request = $this->withdrawals->forAdmin($requestId);
        $orderNumber = (string) $request['orderNumber'];
        $order = $this->orderForAdmin($orderNumber);
        if ($order === null) {
            throw new HttpException('Pedido não encontrado.', 404);
        }
        if (($order['payment_provider'] ?? null) === 'stripe'
            && !PaymentPolicy::orderPaymentModeMatchesEnvironment($order)) {
            throw new HttpException('O modo Stripe deste pedido não corresponde ao ambiente atual.', 409, [], 'stripe_order_mode_mismatch');
        }

        $this->orderLock->acquire((int) $order['id']);
        try {
            $request = $this->withdrawals->forAdmin($requestId);
            $order = $this->orderForAdmin($orderNumber) ?? $order;
            if ($action === 'check_refund') {
                if ($request['status'] !== 'refund_pending' || $order['payment_provider'] !== 'stripe') {
                    throw new HttpException('Esta solicitação não possui um reembolso Stripe pendente para consultar.', 409);
                }
                if ($this->hasTrackedPartialRefundAttempt($order)) {
                    return $this->reconcilePartialRefundAttempt($order, (int) $admin['id']);
                }
                return $this->reconcileAdminRefund($order, (int) $admin['id'], $requestId);
            }
            if ($action === 'retry_refund') {
                if ($this->hasTrackedPartialRefundAttempt($order)) {
                    $attempt = $this->refundAttemptRow((int) $order['id'], (int) $order['payment_refund_attempt']);
                    $retryablePartialState = ($attempt['status'] ?? null) === 'failed'
                        || (($attempt['status'] ?? null) === 'manual' && ($attempt['provider_error_code'] ?? null) === 'manual_not_refunded');
                    if ($request['status'] !== 'refund_failed' || !$retryablePartialState
                        || !WithdrawalPolicy::canStartRefund('refund_failed', $order)) {
                        throw new HttpException('Só é seguro repetir após recusa definitiva e confirmação de que a Stripe não emitiu o reembolso.', 409);
                    }
                    $remaining = max(0, $this->cents((string) $order['total']) - (int) ($order['payment_refunded_cents'] ?? 0));
                    return $this->startPartialRefundAttempt($order, $remaining, 'Consumer withdrawal request ' . $request['requestNumber'], (int) $admin['id'], $requestId);
                }
                if ($request['status'] !== 'refund_failed'
                    || !RefundStatePolicy::canStartNewAttempt((string) $order['payment_status'], (string) $order['payment_refund_outcome'])) {
                    throw new HttpException('Só é seguro tentar novamente após uma recusa definitiva do provedor. Consulte o estado antes de repetir.', 409);
                }
                return $this->startAdminRefundAttempt($order, (int) $admin['id'], $requestId);
            }

            if ($request['status'] !== 'return_received') {
                throw new HttpException('Registre o recebimento do produto ou comprovante de envio antes de solicitar o reembolso.', 409, [], 'return_not_received');
            }
            if (($order['payment_provider'] ?? null) === 'local_test' && (bool) ($order['test_payment'] ?? false)) {
                if ($order['payment_status'] !== 'paid') {
                    throw new HttpException('O pagamento de teste já foi encerrado.', 409);
                }
                $this->withdrawals->recordLocalTestRefund($requestId, (int) $admin['id']);
                return ['message' => 'Reembolso local de teste registrado; nenhum dinheiro foi movimentado.'];
            }
            if (!WithdrawalPolicy::canStartRefund((string) $request['status'], $order)) {
                throw new HttpException('O pedido não está pronto para devolver o saldo restante da cobrança Stripe. Verifique o pagamento e a solicitação.', 409);
            }
            $remaining = max(0, $this->cents((string) $order['total']) - (int) ($order['payment_refunded_cents'] ?? 0));
            return $this->startPartialRefundAttempt($order, $remaining, 'Consumer withdrawal request ' . $request['requestNumber'], (int) $admin['id'], $requestId);
        } finally {
            $this->orderLock->release((int) $order['id']);
        }
    }

    private function cancelForAdminWithPaymentLock(
        string $number,
        array $admin,
        string $action = 'cancel',
        ?string $resolution = null,
        ?string $reason = null
    ): array
    {
        $order = $this->orderForAdmin($number);
        if ($order === null) {
            throw new HttpException('Pedido não encontrado.', 404);
        }
        if ($order['payment_provider'] === 'stripe'
            && !PaymentPolicy::orderPaymentModeMatchesEnvironment($order)) {
            throw new HttpException('O modo Stripe registrado neste pedido não corresponde ao ambiente atual; nenhuma ação de cobrança ou reembolso foi enviada.', 409, [], 'stripe_order_mode_mismatch');
        }
        if ($action === 'resolve_checkout_attempt') {
            return $this->unattachedCheckoutResolver->resolve($order, $admin, (string) $reason);
        }
        if ($action === 'check_refund') {
            if ($this->hasTrackedPartialRefundAttempt($order)) {
                return $this->reconcilePartialRefundAttempt($order, (int) $admin['id']);
            }
            return $this->reconcileAdminRefund($order, (int) $admin['id']);
        }
        if ($action === 'retry_refund') {
            if ($this->hasTrackedPartialRefundAttempt($order)) {
                throw new HttpException('Esta era uma tentativa parcial. Inicie um novo reembolso escolhendo novamente o valor; resultados incertos devem ser consultados antes.', 409);
            }
            return $this->retryAdminRefund($order, (int) $admin['id']);
        }
        if ($action === 'resolve_refund') {
            if ($this->hasTrackedPartialRefundAttempt($order)) {
                return $this->resolvePartialRefundAttempt($order, (int) $admin['id'], (string) $resolution, (string) $reason);
            }
            return $this->resolveAdminRefund($order, (int) $admin['id'], (string) $resolution, (string) $reason);
        }
        $cancelledRefundRetry = $order['status'] === 'cancelled'
            && $order['payment_provider'] === 'stripe'
            && in_array($order['payment_status'], ['paid', 'refund_pending'], true);
        if (in_array($order['status'], ['shipped', 'delivered'], true)
            || ($order['status'] === 'cancelled' && !$cancelledRefundRetry)) {
            throw new HttpException('Este pedido já foi enviado, entregue ou cancelado e não pode ser cancelado por aqui.', 409);
        }

        if ($order['status'] === 'awaiting_payment' && $order['payment_status'] === 'pending'
            && ($order['payment_provider'] ?? null) === 'stripe'
            && !is_string($order['payment_session_id'] ?? null)) {
            throw new HttpException(
                'A Stripe ainda pode ter criado uma sessão. Recupere a mesma tentativa ou faça reconciliação administrativa antes de cancelar e liberar o estoque.',
                409,
                ['orderNumber' => $number],
                'payment_session_unverified'
            );
        }

        if ($order['payment_provider'] === 'stripe' && $order['payment_session_id'] && $order['payment_status'] === 'pending') {
            try {
                $session = $this->stripe->retrieveCheckoutSession((string) $order['payment_session_id']);
                if (!PaymentPolicy::stripeObjectMatchesOrder($session, $order)) {
                    throw new HttpException('A sessão Stripe não corresponde ao ambiente e ao modo deste pedido.', 409, [], 'stripe_session_mode_mismatch');
                }
                if (($session['payment_status'] ?? '') === 'paid') {
                    $this->applyCheckoutEvent($session, true);
                    $order = $this->orderForAdmin($number) ?? $order;
                } elseif (($session['status'] ?? '') === 'open') {
                    try {
                        $this->stripe->expireCheckoutSession((string) $order['payment_session_id']);
                    } catch (Throwable $exception) {
                        // A checkout may complete between retrieve and expire. The
                        // fresh read below decides whether to refund or keep waiting.
                        error_log('Could not expire checkout during admin cancellation: ' . $exception->getMessage());
                    }
                    $expiredSession = $this->stripe->retrieveCheckoutSession((string) $order['payment_session_id']);
                    if (!PaymentPolicy::stripeObjectMatchesOrder($expiredSession, $order)) {
                        throw new HttpException('A sessão Stripe não corresponde ao ambiente e ao modo deste pedido.', 409, [], 'stripe_session_mode_mismatch');
                    }
                    if (($expiredSession['payment_status'] ?? '') === 'paid') {
                        $this->applyCheckoutEvent($expiredSession, true);
                        $order = $this->orderForAdmin($number) ?? $order;
                    } elseif (($expiredSession['status'] ?? '') !== 'expired') {
                        throw new HttpException('O provedor ainda está finalizando o pagamento. Tente cancelar novamente em instantes.', 409);
                    }
                } elseif (($session['status'] ?? '') === 'complete') {
                    throw new HttpException('O pagamento está sendo confirmado pelo provedor. Aguarde a confirmação antes de cancelar.', 409);
                }
            } catch (HttpException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new HttpException('Não foi possível cancelar a sessão de pagamento agora. Tente novamente.', 502);
            }
        }

        $paymentStatus = (string) $order['payment_status'];
        if ($paymentStatus === 'refund_pending' && $order['payment_provider'] !== 'stripe') {
            throw new HttpException('Este pedido não tem um reembolso do provedor em andamento.', 409);
        }
        if ($paymentStatus === 'refund_pending' && $order['payment_provider'] === 'stripe') {
            if ($this->hasTrackedPartialRefundAttempt($order)) {
                return $this->reconcilePartialRefundAttempt($order, (int) $admin['id']);
            }
            return $this->reconcileAdminRefund($order, (int) $admin['id']);
        }
        if ($paymentStatus === 'partially_refunded') {
            throw new HttpException('Este pedido já recebeu um reembolso parcial. Confira o saldo restante e processe qualquer valor adicional como reembolso parcial antes de cancelar.', 409, [], 'order_partially_refunded');
        }
        if ($paymentStatus === 'paid' && $order['payment_provider'] === 'stripe') {
            $paymentIntentId = (string) ($order['payment_intent_id'] ?? '');
            if ($paymentIntentId === '') {
                throw new HttpException('O provedor ainda não confirmou a referência de reembolso. Tente novamente em instantes.', 409);
            }
            return $this->startAdminRefundAttempt($order, (int) $admin['id']);
        }

        $finalPaymentStatus = $paymentStatus === 'paid' ? 'refunded' : ($paymentStatus === 'pending' ? 'failed' : $paymentStatus);
        $this->finishCancellation((int) $order['id'], (int) $admin['id'], $paymentStatus, $finalPaymentStatus, null, $paymentStatus, $order['payment_session_id'] ?? null);
        return ['message' => $paymentStatus === 'paid' ? 'Pedido de teste cancelado (reembolso simulado).' : 'Pedido cancelado.'];
    }






    private function cancelUnpaidOrder(int $orderId, string $paymentStatus): void
    {
        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $select = $this->pdo->prepare('SELECT status, payment_status FROM orders WHERE id = :id FOR UPDATE');
            $select->execute([':id' => $orderId]);
            $order = $select->fetch();
            if ($order && $order['status'] === 'awaiting_payment' && $order['payment_status'] === 'pending') {
                $this->restoreStock($orderId);
                $update = $this->pdo->prepare(
                    "UPDATE orders SET status = 'cancelled', payment_status = :paymentStatus,
                            reservation_expires_at = NULL WHERE id = :id"
                );
                $update->execute([':paymentStatus' => $paymentStatus, ':id' => $orderId]);
            } elseif ($order && $order['status'] === 'cancelled' && $order['payment_status'] === 'pending'
                && $paymentStatus === 'failed') {
                // Inventory expiry may have cancelled the order before Stripe's
                // expiration event arrived; do not restore stock a second time.
                $update = $this->pdo->prepare(
                    "UPDATE orders SET payment_status = 'failed', reservation_expires_at = NULL
                     WHERE id = :id AND status = 'cancelled' AND payment_status = 'pending'"
                );
                $update->execute([':id' => $orderId]);
            }
            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function reservationHasExpired(array $order): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT reservation_expires_at IS NOT NULL AND reservation_expires_at <= CURRENT_TIMESTAMP
             FROM orders WHERE id = :id'
        );
        $statement->execute([':id' => $order['id']]);
        return (bool) $statement->fetchColumn();
    }

    private function finishCancellation(
        int $orderId,
        int $adminId,
        string $previousPaymentStatus,
        string $paymentStatus,
        ?string $refundId,
        string $expectedPaymentStatus,
        ?string $expectedPaymentSessionId
    ): void
    {
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT status, payment_status, payment_provider, payment_session_id, payment_refund_outcome, payment_refund_error_code, payment_refund_error_message, payment_refund_error_http_status FROM orders WHERE id = :id FOR UPDATE');
            $select->execute([':id' => $orderId]);
            $order = $select->fetch();
            if (!$order || $order['status'] === 'cancelled') {
                throw new HttpException('Este pedido já foi cancelado.', 409);
            }
            $status = (string) $order['status'];
            if ($order['payment_status'] !== $expectedPaymentStatus) {
                throw new HttpException('O estado do pagamento mudou durante o cancelamento. Atualize o pedido antes de tentar novamente.', 409);
            }
            if (($order['payment_session_id'] ?? null) !== $expectedPaymentSessionId) {
                throw new HttpException('A sessão de pagamento mudou durante o cancelamento. Atualize o pedido antes de tentar novamente.', 409, [], 'payment_session_changed');
            }
            if (!in_array($status, ['awaiting_payment', 'confirmed', 'preparing'], true)) {
                throw new HttpException('Pedidos enviados ou entregues não podem ser cancelados por aqui.', 409);
            }
            $this->restoreStock($orderId);
            $update = $this->pdo->prepare(
                "UPDATE orders SET status = 'cancelled', payment_status = :paymentStatus,
                        payment_refunded_cents = IF(:isRefunded = 1, ROUND(total * 100), payment_refunded_cents),
                        payment_refund_outcome = :refundOutcome,
                        payment_refund_error_code = :refundErrorCode,
                        payment_refund_error_message = :refundErrorMessage,
                        payment_refund_error_http_status = :refundErrorHttpStatus,
                        payment_refund_id = COALESCE(:refundId, payment_refund_id),
                        reservation_expires_at = NULL WHERE id = :id"
            );
            $refunded = $paymentStatus === 'refunded';
            $update->execute([
                ':paymentStatus' => $paymentStatus,
                ':isRefunded' => $refunded ? 1 : 0,
                ':refundOutcome' => $refunded ? 'succeeded' : $order['payment_refund_outcome'],
                ':refundErrorCode' => $refunded ? null : $order['payment_refund_error_code'],
                ':refundErrorMessage' => $refunded ? null : $order['payment_refund_error_message'],
                ':refundErrorHttpStatus' => $refunded ? null : $order['payment_refund_error_http_status'],
                ':refundId' => $refundId,
                ':id' => $orderId,
            ]);
            if ($paymentStatus === 'refunded' && $order['payment_provider'] === 'stripe') {
                $this->queueFullRefundConfirmation($orderId, $refundId ?? ('manual-' . gmdate('YmdHis')));
            }
            $audit = $this->pdo->prepare(
                'INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, \'order.cancelled\', \'order\', :orderId, :previous, :new)'
            );
            $audit->execute([
                ':adminId' => $adminId,
                ':orderId' => $orderId,
                ':previous' => $status . ':' . $previousPaymentStatus,
                ':new' => 'cancelled:' . $paymentStatus,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function restoreStock(int $orderId): void
    {
        $items = $this->pdo->prepare(
            'SELECT product_id, SUM(quantity) AS quantity FROM order_items
             WHERE order_id = :orderId AND product_id IS NOT NULL GROUP BY product_id'
        );
        $items->execute([':orderId' => $orderId]);
        $restore = $this->pdo->prepare('UPDATE products SET stock = stock + :quantity WHERE id = :productId');
        foreach ($items->fetchAll() as $item) {
            $restore->execute([':quantity' => (int) $item['quantity'], ':productId' => (int) $item['product_id']]);
        }
    }

    private function queueFullRefundConfirmation(int $orderId, string $reference): void
    {
        $queue = $this->pdo->prepare(
            "INSERT IGNORE INTO payment_notification_outbox (order_id, notification_type, reference_id)
             VALUES (:orderId, 'full_refund_confirmation', :reference)"
        );
        $queue->execute([':orderId' => $orderId, ':reference' => $reference]);
    }

    private function orderItems(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT product_name AS productName, unit_price AS unitPrice, quantity
             FROM order_items WHERE order_id = :orderId ORDER BY id'
        );
        $statement->execute([':orderId' => $orderId]);
        return $statement->fetchAll();
    }

    private function orderForAdmin(string $number): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE order_number = :number LIMIT 1');
        $statement->execute([':number' => $number]);
        $order = $statement->fetch();
        return $order ?: null;
    }


    private function cents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new RuntimeException('An order has an invalid stored amount.');
        }
        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
