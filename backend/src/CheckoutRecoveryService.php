<?php

declare(strict_types=1);

/** Reconciles, resumes and safely cancels a buyer's existing Checkout order. */
final class CheckoutRecoveryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $limiter,
        private readonly PaymentOrderLock $orderLock,
        private readonly StripeGateway $stripe,
        private readonly Closure $applyCheckoutEvent,
        private readonly Closure $cancelUnpaidOrder
    ) {
    }

    public function statusBySession(array $payload, array $user): array
    {
        $this->limiter->assertAllowed('payment-status', (string) $user['id'], 60, 3600);
        Validation::allowedFields($payload, ['sessionId']);
        $sessionId = Validation::requiredString($payload, 'sessionId', 10, 128);
        if (!preg_match('/^cs_(?:test|live)_[A-Za-z0-9]+$/', $sessionId)) {
            throw new HttpException('Sessão de pagamento inválida.', 422);
        }
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE payment_session_id = :sessionId AND user_id = :userId LIMIT 1');
        $statement->execute([':sessionId' => $sessionId, ':userId' => $user['id']]);
        $order = $statement->fetch();
        if (!$order) throw new HttpException('Sessão de pagamento não encontrada.', 404);
        $orderId = (int) $order['id'];
        $this->orderLock->acquire($orderId);
        try {
            $statement->execute([':sessionId' => $sessionId, ':userId' => $user['id']]);
            $order = $statement->fetch() ?: $order;
            if ($order['payment_status'] !== 'paid') {
                try {
                    $session = $this->stripe->retrieveCheckoutSession($sessionId);
                } catch (Throwable) {
                    throw new HttpException('Não foi possível consultar o pagamento agora. Tente novamente.', 502, [], 'payment_session_unverified');
                }
                $this->assertSessionMatchesOrder($session, $order);
                ($this->applyCheckoutEvent)($session, (string) ($session['payment_status'] ?? '') === 'paid');
                if (($session['status'] ?? '') === 'expired' && ($session['payment_status'] ?? '') !== 'paid') {
                    ($this->cancelUnpaidOrder)($orderId, 'failed');
                }
                $statement->execute([':sessionId' => $sessionId, ':userId' => $user['id']]);
                $order = $statement->fetch() ?: $order;
            }
        } finally {
            $this->orderLock->release($orderId);
        }
        return ['order' => $this->serializeOrder($order), 'paymentStatus' => (string) $order['payment_status']];
    }

    public function status(array $payload, array $user): array
    {
        $this->limiter->assertAllowed('payment-status', (string) $user['id'], 60, 3600);
        Validation::allowedFields($payload, ['orderNumber']);
        $number = Validation::requiredString($payload, 'orderNumber', 8, 32);
        $order = $this->orderForUser($number, (int) $user['id']);
        if ($order === null) throw new HttpException('Pedido não encontrado.', 404);

        $orderId = (int) $order['id'];
        $this->orderLock->acquire($orderId);
        try {
            $order = $this->orderForUser($number, (int) $user['id']) ?? $order;
            $session = null;
            if ($order['status'] === 'cancelled' && $order['payment_status'] === 'pending'
                && ($order['payment_provider'] ?? null) !== 'stripe'
                && $this->reservationHasExpired($order)) {
                $failed = $this->pdo->prepare(
                    "UPDATE orders SET payment_status = 'failed'
                     WHERE id = :id AND status = 'cancelled' AND payment_status = 'pending'"
                );
                $failed->execute([':id' => $order['id']]);
                $order = $this->orderForUser($number, (int) $user['id']) ?? $order;
            }
            if ($order['payment_status'] !== 'paid'
                && in_array($order['status'], ['awaiting_payment', 'cancelled'], true)
                && ($order['status'] !== 'cancelled' || $order['payment_status'] === 'pending')
                && $order['payment_provider'] === 'stripe'
                && is_string($order['payment_session_id'] ?? null)) {
                try {
                    $session = $this->stripe->retrieveCheckoutSession((string) $order['payment_session_id']);
                } catch (Throwable) {
                    throw new HttpException('Não foi possível consultar o pagamento agora. Nenhum novo pedido foi criado; tente novamente.', 502, [], 'payment_session_unverified');
                }
                $this->assertSessionMatchesOrder($session, $order);
                if ($order['status'] === 'cancelled'
                    && $order['payment_status'] === 'pending'
                    && ($session['payment_status'] ?? '') !== 'paid'
                    && ($session['status'] ?? '') === 'open') {
                    $session = $this->expireSessionAndRecheck((string) $order['payment_session_id'], $order);
                }
                if (($session['payment_status'] ?? '') === 'paid') {
                    ($this->applyCheckoutEvent)($session, true);
                } elseif (($session['status'] ?? '') === 'expired') {
                    if ($order['status'] === 'cancelled') {
                        $failed = $this->pdo->prepare(
                            "UPDATE orders SET payment_status = 'failed'
                             WHERE id = :id AND status = 'cancelled' AND payment_status = 'pending'"
                        );
                        $failed->execute([':id' => $order['id']]);
                    } else {
                        ($this->cancelUnpaidOrder)((int) $order['id'], 'failed');
                    }
                }
                $order = $this->orderForUser($number, (int) $user['id']) ?? $order;
            }

            $canResume = $order['status'] === 'awaiting_payment'
                && $order['payment_status'] === 'pending'
                && $session !== null
                && ($session['status'] ?? '') === 'open'
                && is_string($session['url'] ?? null)
                && $this->isSecureCheckoutUrl((string) $session['url'])
                && strtotime((string) ($order['reservation_expires_at'] ?? '')) > time();
            $canRetry = $order['status'] === 'awaiting_payment'
                && $order['payment_status'] === 'pending'
                && ($order['payment_provider'] ?? null) === 'stripe'
                && !is_string($order['payment_session_id'] ?? null)
                && PaymentPolicy::canSafelyReplayCheckoutCreation($order);
            $canCancel = $order['status'] === 'awaiting_payment'
                && $order['payment_status'] === 'pending'
                && (($order['payment_provider'] ?? null) !== 'stripe' || is_string($order['payment_session_id'] ?? null))
                && ($session === null || in_array(($session['status'] ?? ''), ['open', 'expired'], true));
            $canCancel = $canCancel || ($order['status'] === 'cancelled'
                && $order['payment_status'] === 'pending'
                && $session !== null
                && ($session['status'] ?? '') === 'open');

            return [
                'order' => $this->serializeOrder($order),
                'paymentStatus' => (string) $order['payment_status'],
                'checkoutUrl' => $canResume ? (string) $session['url'] : null,
                'canResume' => $canResume,
                'canRetry' => $canRetry,
                'canCancel' => $canCancel,
                'providerStatus' => is_array($session)
                    ? (string) ($session['status'] ?? 'unknown')
                    : (($order['payment_provider'] ?? null) === 'stripe' && !is_string($order['payment_session_id'] ?? null)
                        ? (PaymentPolicy::canSafelyReplayCheckoutCreation($order) ? 'creation_unconfirmed' : 'manual_reconciliation_required')
                        : null),
            ];
        } finally {
            $this->orderLock->release($orderId);
        }
    }

    public function cancel(array $input, array $user): array
    {
        Validation::allowedFields($input, ['orderNumber']);
        $number = Validation::requiredString($input, 'orderNumber', 8, 32);
        $this->limiter->assertAllowed('payment-cancel', (string) $user['id'], 10, 3600);
        $order = $this->orderForUser($number, (int) $user['id']);
        if ($order === null) throw new HttpException('Pedido não encontrado.', 404);

        $orderId = (int) $order['id'];
        $this->orderLock->acquire($orderId);
        try {
            $order = $this->orderForUser($number, (int) $user['id']) ?? $order;
            if ($order['payment_status'] === 'paid') {
                return ['order' => $this->serializeOrder($order), 'cancelled' => false, 'alreadyPaid' => true];
            }
            if ($order['status'] === 'cancelled') {
                if ($order['payment_status'] === 'pending'
                    && ($order['payment_provider'] ?? null) === 'stripe'
                    && is_string($order['payment_session_id'] ?? null)) {
                    $sessionId = (string) $order['payment_session_id'];
                    try {
                        $session = $this->stripe->retrieveCheckoutSession($sessionId);
                        $this->assertSessionMatchesOrder($session, $order);
                        if (($session['status'] ?? '') === 'open' && ($session['payment_status'] ?? '') !== 'paid') {
                            $session = $this->expireSessionAndRecheck($sessionId, $order);
                        }
                    } catch (HttpException $exception) {
                        throw $exception;
                    } catch (Throwable) {
                        throw new HttpException('Não foi possível confirmar o encerramento do pagamento. A compra permanece bloqueada até a reconciliação.', 502, [], 'payment_session_unverified');
                    }
                    if (($session['payment_status'] ?? '') === 'paid') {
                        ($this->applyCheckoutEvent)($session, true);
                        $order = $this->orderForUser($number, (int) $user['id']) ?? $order;
                        return ['order' => $this->serializeOrder($order), 'cancelled' => false, 'alreadyPaid' => true];
                    }
                    if (($session['status'] ?? '') !== 'expired') {
                        throw new HttpException('O provedor ainda está confirmando o pagamento. A compra permanece bloqueada até a reconciliação.', 409, [], 'payment_confirmation_pending');
                    }
                    $failed = $this->pdo->prepare(
                        "UPDATE orders SET payment_status = 'failed'
                         WHERE id = :id AND status = 'cancelled' AND payment_status = 'pending'"
                    );
                    $failed->execute([':id' => $orderId]);
                    $order = $this->orderForUser($number, (int) $user['id']) ?? $order;
                } elseif ($order['payment_status'] === 'pending'
                    && ($order['payment_provider'] ?? null) !== 'stripe'
                    && $this->reservationHasExpired($order)) {
                    $failed = $this->pdo->prepare(
                        "UPDATE orders SET payment_status = 'failed'
                         WHERE id = :id AND status = 'cancelled' AND payment_status = 'pending'"
                    );
                    $failed->execute([':id' => $orderId]);
                    $order = $this->orderForUser($number, (int) $user['id']) ?? $order;
                }
                if (in_array($order['payment_status'], ['pending', 'paid', 'refund_pending'], true)) {
                    throw new HttpException('O pagamento ou o reembolso ainda precisa de reconciliação. A compra permanece bloqueada para evitar nova cobrança.', 409, ['orderNumber' => $number], 'payment_confirmation_pending');
                }
                return ['order' => $this->serializeOrder($order), 'cancelled' => true, 'alreadyPaid' => false];
            }
            if ($order['status'] !== 'awaiting_payment' || $order['payment_status'] !== 'pending') {
                throw new HttpException('Este pedido não está mais aguardando pagamento e não pode ser cancelado pelo checkout.', 409);
            }

            if (($order['payment_provider'] ?? null) === 'stripe'
                && !is_string($order['payment_session_id'] ?? null)) {
                throw new HttpException(
                    'A Stripe ainda pode ter criado uma sessão. Recupere a mesma tentativa ou faça reconciliação administrativa antes de cancelar e liberar estoque.',
                    409,
                    ['orderNumber' => $number],
                    'payment_session_unverified'
                );
            }

            if (($order['payment_provider'] ?? null) === 'stripe' && is_string($order['payment_session_id'] ?? null)) {
                $sessionId = (string) $order['payment_session_id'];
                try {
                    $session = $this->stripe->retrieveCheckoutSession($sessionId);
                    $this->assertSessionMatchesOrder($session, $order);
                    if (($session['payment_status'] ?? '') !== 'paid' && ($session['status'] ?? '') === 'open') {
                        try {
                            $this->stripe->expireCheckoutSession($sessionId);
                        } catch (Throwable $exception) {
                            // A provider read after the expiration attempt resolves completion races.
                            error_log('Customer checkout cancellation could not expire the Stripe session: ' . $exception->getMessage());
                        }
                        $session = $this->stripe->retrieveCheckoutSession($sessionId);
                        $this->assertSessionMatchesOrder($session, $order);
                    }
                } catch (HttpException $exception) {
                    throw $exception;
                } catch (Throwable) {
                    throw new HttpException('Não foi possível confirmar o estado do pagamento. O pedido foi mantido e não pode ser cancelado agora.', 502, [], 'payment_session_unverified');
                }

                if (($session['payment_status'] ?? '') === 'paid') {
                    ($this->applyCheckoutEvent)($session, true);
                    $order = $this->orderForUser($number, (int) $user['id']) ?? $order;
                    return ['order' => $this->serializeOrder($order), 'cancelled' => false, 'alreadyPaid' => true];
                }
                if (($session['status'] ?? '') !== 'expired') {
                    throw new HttpException(
                        'O provedor ainda está confirmando ou mantendo o pagamento aberto. Atualize o estado antes de cancelar.',
                        409,
                        [],
                        'payment_confirmation_pending'
                    );
                }
            }

            ($this->cancelUnpaidOrder)($orderId, 'failed');
            $order = $this->orderForUser($number, (int) $user['id']) ?? $order;
            if ($order['status'] !== 'cancelled') {
                throw new HttpException('O pedido mudou enquanto era cancelado. Atualize o estado antes de tentar novamente.', 409, [], 'order_changed');
            }
            return ['order' => $this->serializeOrder($order), 'cancelled' => true, 'alreadyPaid' => false];
        } finally {
            $this->orderLock->release($orderId);
        }
    }

    private function orderForUser(string $number, int $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE order_number = :number AND user_id = :userId LIMIT 1');
        $statement->execute([':number' => $number, ':userId' => $userId]);
        $order = $statement->fetch();
        return $order ?: null;
    }

    private function assertSessionMatchesOrder(array $session, array $order): void
    {
        if (!PaymentPolicy::stripeObjectMatchesOrder($session, $order)) {
            throw new HttpException('A sessão Stripe não corresponde ao ambiente e ao modo deste pedido.', 409, [], 'stripe_session_mode_mismatch');
        }
        if (strtolower((string) ($session['currency'] ?? '')) !== strtolower((string) $order['currency'])
            || (int) ($session['amount_total'] ?? -1) !== $this->cents((string) $order['total'])) {
            throw new HttpException('Os dados da sessão Stripe não correspondem ao pedido.', 409, [], 'stripe_session_order_mismatch');
        }
    }

    private function expireSessionAndRecheck(string $sessionId, array $order): array
    {
        try {
            try {
                $this->stripe->expireCheckoutSession($sessionId);
            } catch (Throwable $exception) {
                // A fresh provider read, not the expiration response, decides the result.
                error_log('Could not expire a cancelled checkout session: ' . $exception->getMessage());
            }
            $session = $this->stripe->retrieveCheckoutSession($sessionId);
        } catch (Throwable) {
            throw new HttpException('Não foi possível confirmar o encerramento do pagamento. A compra permanece bloqueada até a reconciliação.', 502, [], 'payment_session_unverified');
        }
        $this->assertSessionMatchesOrder($session, $order);
        return $session;
    }

    private function isSecureCheckoutUrl(string $url): bool
    {
        return PaymentPolicy::isSecureStripeCheckoutUrl($url);
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

    private function cents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new RuntimeException('An order has an invalid stored amount.');
        }
        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function serializeOrder(array $order): array
    {
        return [
            'number' => (string) $order['order_number'],
            'status' => (string) $order['status'],
            'paymentStatus' => (string) $order['payment_status'],
            'subtotal' => (float) $order['subtotal'],
            'shipping' => (float) $order['shipping_amount'],
            'total' => (float) $order['total'],
            'currency' => (string) $order['currency'],
            'testPayment' => (bool) ($order['test_payment'] ?? false),
            'createdAt' => $order['created_at'] ?? null,
        ];
    }
}
