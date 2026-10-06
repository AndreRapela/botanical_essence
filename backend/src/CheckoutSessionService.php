<?php

declare(strict_types=1);

/** Owns checkout-session creation and refuses to expose unattached/stale Stripe URLs. */
final class CheckoutSessionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $limiter,
        private readonly OrderService $orders,
        private readonly StripeGateway $stripe,
        private readonly PaymentOrderLock $orderLock,
        private readonly Closure $applyCheckoutEvent,
        private readonly Closure $cancelUnpaidOrder
    ) {
        PaymentPolicy::executionMode();
        PaymentPolicy::assertStripeKeyMatchesEnvironment($this->stripe->secretKey());
    }

    public function startCheckout(array $input, array $user): array
    {
        $method = Validation::oneOf($input, 'payment', ['bancontact', 'card', 'sepa_debit']);
        $currency = Validation::oneOf($input, 'currency', ['USD', 'EUR']);
        if (in_array($method, ['bancontact', 'sepa_debit'], true) && $currency !== 'EUR') {
            throw new HttpException('Bancontact e débito direto SEPA exigem que a compra seja finalizada em euros. Selecione EUR para continuar.', 422, [
                'currency' => 'Bancontact accepts EUR only.',
            ]);
        }

        $userId = (int) $user['id'];
        $this->orderLock->acquireForUser($userId);
        try {
            $this->assertNoOtherActiveCheckout($input, $userId);
            $localSimulation = $this->assertProviderReady($method, $currency, $user);

            $created = $this->orders->create($input, $user);
            $number = (string) $created['order']['number'];
            $order = $this->orderForUser($number, $userId);
            if ($order === null) {
                // The committed order can be recovered by retrying the same client idempotency key.
                throw new HttpException('O pedido foi registrado, mas ainda não pôde ser recuperado. Tente novamente com a mesma tentativa.', 503, ['orderNumber' => $number], 'checkout_order_unavailable');
            }

            $orderId = (int) $order['id'];
            try {
                $this->orderLock->acquire($orderId);
            } catch (HttpException $exception) {
                throw new HttpException($exception->getMessage(), $exception->status, ['orderNumber' => $number], $exception->errorCode);
            }
            try {
                return $this->startCheckoutForLockedOrder($number, $user, $localSimulation);
            } finally {
                $this->orderLock->release($orderId);
            }
        } finally {
            $this->orderLock->releaseForUser($userId);
        }
    }

    /** Retry an unresolved Stripe creation using its original order-derived idempotency key. */
    public function resumeCheckout(array $input, array $user): array
    {
        Validation::allowedFields($input, ['orderNumber']);
        $number = Validation::requiredString($input, 'orderNumber', 8, 32);
        $userId = (int) $user['id'];
        $order = $this->orderForUser($number, $userId);
        if ($order === null) {
            throw new HttpException('Pedido não encontrado.', 404);
        }

        $this->orderLock->acquireForUser($userId);
        try {
            $this->assertNoOtherActiveCheckout(['idempotencyKey' => $order['idempotency_key']], $userId);
            if (!in_array($order['payment_method'], ['card', 'bancontact', 'sepa_debit'], true)
                || !in_array($order['currency'], ['USD', 'EUR'], true)) {
                throw new HttpException('Os dados de pagamento deste pedido não podem ser retomados.', 409, [], 'payment_order_invalid');
            }
            $localSimulation = $this->assertProviderReady((string) $order['payment_method'], (string) $order['currency'], $user);
            if ($localSimulation) {
                throw new HttpException('Este pedido não pode ser retomado em modo de simulação local.', 409, [], 'payment_mode_changed');
            }

            $order = $this->orderForUser($number, $userId) ?? $order;
            if ($order['payment_status'] === 'paid') {
                return ['order' => $this->serializeOrder($order), 'checkoutUrl' => null, 'alreadyPaid' => true];
            }
            if ($order['status'] !== 'awaiting_payment' || $order['payment_status'] !== 'pending'
                || ($order['payment_provider'] ?? null) !== 'stripe') {
                throw new HttpException('Este pedido não tem uma sessão Stripe pendente que possa ser retomada.', 409, ['orderNumber' => $number], 'checkout_not_resumable');
            }

            $orderId = (int) $order['id'];
            $this->orderLock->acquire($orderId);
            try {
                return $this->startCheckoutForLockedOrder($number, $user, false);
            } finally {
                $this->orderLock->release($orderId);
            }
        } finally {
            $this->orderLock->releaseForUser($userId);
        }
    }

    private function assertProviderReady(string $method, string $currency, array $user): bool
    {
        $localSimulation = PaymentPolicy::executionMode() === PaymentPolicy::MODE_LOCAL_SIMULATION;
        PaymentPolicy::assertStripeKeyMatchesEnvironment($this->stripe->secretKey());
        if ($localSimulation) {
            return true;
        }
        if (!$this->stripe->configured()) {
            throw new HttpException('Configure uma chave Stripe para o modo de pagamento selecionado.', 503, [], 'stripe_key_missing');
        }
        $this->limiter->assertAllowed('payment-checkout', Request::ip() . '|' . $user['id'], 10, 3600);
        $limits = PaymentPolicy::stripePaymentLimits($method, $currency);
        if (!str_starts_with(trim((string) getenv('STRIPE_WEBHOOK_SECRET')), 'whsec_')) {
            throw new HttpException('Configure a assinatura do webhook Stripe antes de aceitar pagamentos pelo provedor.', 503, [], 'stripe_webhook_secret_missing');
        }
        try {
            $this->stripe->assertCheckoutRedirectConfiguration();
        } catch (Throwable) {
            throw new HttpException('Configure APP_URL com o endereço público correto antes de aceitar pagamentos. Nenhum pedido foi criado.', 503, [], 'stripe_redirect_configuration_invalid');
        }
        try {
            $account = $this->stripe->retrieveAccount();
        } catch (Throwable) {
            throw new HttpException('Não foi possível validar a conta Stripe agora. Nenhum pedido foi criado; tente novamente.', 503, [], 'stripe_account_unavailable');
        }
        PaymentPolicy::assertStripeAccountMatchesConfiguration($account, $limits);
        return false;
    }

    private function assertNoOtherActiveCheckout(array $input, int $userId): void
    {
        $idempotencyKey = $input['idempotencyKey'] ?? null;
        if (!is_string($idempotencyKey)
            || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $idempotencyKey)) {
            return; // Let OrderService return the canonical validation error.
        }

        $statement = $this->pdo->prepare(
            "SELECT order_number
             FROM orders
             WHERE user_id = :userId AND idempotency_key <> :idempotencyKey
               AND (
                    (status = 'awaiting_payment' AND payment_status = 'pending'
                     AND (reservation_expires_at IS NULL OR reservation_expires_at > CURRENT_TIMESTAMP))
                    OR (status = 'cancelled' AND payment_provider = 'stripe'
                        AND payment_session_id IS NOT NULL AND payment_status IN ('pending','paid','refund_pending'))
               )
             ORDER BY created_at DESC LIMIT 1"
        );
        $statement->execute([':userId' => $userId, ':idempotencyKey' => $idempotencyKey]);
        $activeOrderNumber = $statement->fetchColumn();
        if (is_string($activeOrderNumber) && $activeOrderNumber !== '') {
            throw new HttpException(
                'Você já tem um pagamento em andamento. Retome ou cancele esse pedido antes de iniciar outro.',
                409,
                ['orderNumber' => $activeOrderNumber],
                'checkout_in_progress'
            );
        }
    }

    private function startCheckoutForLockedOrder(string $number, array $user, bool $localSimulation): array
    {
        $order = $this->orderForUser($number, (int) $user['id']);
        if ($order === null) {
            throw new RuntimeException('O pedido não pôde ser recuperado para pagamento.');
        }
        if ($order['status'] === 'cancelled') {
            throw new HttpException(
                'Este pedido foi encerrado. Confirme o estado do pagamento anterior antes de iniciar outra compra.',
                409,
                ['orderNumber' => $number],
                'order_cancelled'
            );
        }
        $provider = (string) ($order['payment_provider'] ?? '');
        $previouslyAttemptedWithoutSession = $provider === 'stripe' && !is_string($order['payment_session_id'] ?? null);
        if ($previouslyAttemptedWithoutSession && !PaymentPolicy::canSafelyReplayCheckoutCreation($order)) {
            throw new HttpException(
                'A janela segura de repetição da Stripe expirou. O pedido e o estoque permanecem bloqueados até uma reconciliação administrativa; nenhuma nova cobrança foi iniciada.',
                409,
                ['orderNumber' => $number],
                'payment_session_reconciliation_required'
            );
        }
        if ($localSimulation) {
            if ($provider !== '' && $provider !== 'local_test') {
                throw new HttpException('Este pedido pertence a outra modalidade de pagamento. Crie um novo pedido para continuar.', 409, [], 'payment_mode_changed');
            }
            if ($order['payment_status'] === 'paid') {
                $this->markSimulatedPayment((int) $order['id']);
                $simulatedOrder = $this->orderForUser($number, (int) $user['id']) ?? $order;
                return ['order' => $this->serializeOrder($simulatedOrder), 'checkoutUrl' => null, 'simulated' => true, 'alreadyPaid' => true];
            }
            $this->markSimulatedPayment((int) $order['id']);
            $simulatedOrder = $this->orderForUser($number, (int) $user['id']);
            if (($simulatedOrder['payment_provider'] ?? null) !== 'local_test'
                || ($simulatedOrder['payment_status'] ?? null) !== 'paid') {
                throw new HttpException('A simulação local não pôde ser registrada com segurança. Atualize o histórico antes de tentar novamente.', 409, [], 'local_simulation_not_recorded');
            }
            return ['order' => $this->serializeOrder($simulatedOrder), 'checkoutUrl' => null, 'simulated' => true];
        }
        if ($provider === 'local_test') {
            throw new HttpException('Este pedido foi concluído somente em simulação local e não pode ser reaproveitado no Stripe. Crie um novo pedido.', 409, [], 'payment_mode_changed');
        }
        if ($order['payment_status'] === 'paid') {
            return ['order' => $this->serializeOrder($order), 'checkoutUrl' => null, 'alreadyPaid' => true];
        }
        if ($provider === 'stripe' && !PaymentPolicy::orderPaymentModeMatchesEnvironment($order)) {
            throw new HttpException('Este pedido foi criado em outro modo Stripe e não pode ser retomado neste ambiente.', 409, ['orderNumber' => $number], 'payment_mode_changed');
        }
        $totalCents = $this->cents((string) $order['total']);
        PaymentPolicy::assertOrderAmountAllowed((string) $order['payment_method'], (string) $order['currency'], $totalCents);

        if ($order['payment_session_id'] && $order['payment_provider'] === 'stripe') {
            try {
                $sessionId = (string) $order['payment_session_id'];
                $session = $this->stripe->retrieveCheckoutSession($sessionId);
                if (!PaymentPolicy::stripeObjectMatchesOrder($session, $order)) {
                    throw new HttpException('A sessão Stripe não corresponde ao ambiente e ao modo deste pedido.', 409, [], 'stripe_session_mode_mismatch');
                }
                if (strtolower((string) ($session['currency'] ?? '')) !== strtolower((string) $order['currency'])
                    || (int) ($session['amount_total'] ?? -1) !== $totalCents) {
                    $this->expireUnusableSession($sessionId, $order, $number);
                    throw $this->unverifiedCheckout($number, 'O valor ou a moeda da sessão Stripe não corresponde ao pedido. O link não será liberado e a sessão será reconciliada.');
                }
                if (($session['status'] ?? '') === 'open' && $this->isSecureCheckoutUrl((string) ($session['url'] ?? ''))) {
                    $sessionExpiresAt = $this->checkoutSessionExpiry($session);
                    $this->extendReservationForCheckoutSession((int) $order['id'], $sessionId, $sessionExpiresAt);
                    $currentOrder = $this->orderForUser($number, (int) $user['id']);
                    if (PaymentPolicy::checkoutSessionIsActive($currentOrder ?? [], $sessionId)
                        && strtotime((string) ($currentOrder['reservation_expires_at'] ?? '')) > time()) {
                        $this->orders->notifyPaymentReady((int) $order['id'], (string) $session['url']);
                        return ['order' => $this->serializeOrder($currentOrder), 'checkoutUrl' => $session['url']];
                    }
                    $session = $this->expireCheckoutSessionIfOpen($sessionId);
                    if (($session['payment_status'] ?? '') === 'paid') {
                        ($this->applyCheckoutEvent)($session, true);
                        $updatedOrder = $this->orderForUser($number, (int) $user['id']) ?? $order;
                        if ($updatedOrder['payment_status'] === 'paid') {
                            return ['order' => $this->serializeOrder($updatedOrder), 'checkoutUrl' => null, 'alreadyPaid' => true];
                        }
                    }
                    if (($session['status'] ?? '') === 'expired') {
                        ($this->cancelUnpaidOrder)((int) $order['id'], 'failed');
                        throw new HttpException('A sessão de pagamento expirou. Você pode iniciar uma nova tentativa.', 409, ['orderNumber' => $number], 'payment_session_expired');
                    }
                    throw $this->unverifiedCheckout($number, 'Não foi possível confirmar a sessão segura. O pedido continua protegido; tente retomá-lo novamente.');
                }
                if (($session['payment_status'] ?? '') === 'paid') {
                    ($this->applyCheckoutEvent)($session, true);
                    $updatedOrder = $this->orderForUser($number, (int) $user['id']) ?? $order;
                    return ['order' => $this->serializeOrder($updatedOrder), 'checkoutUrl' => null, 'alreadyPaid' => true];
                }
                if (($session['status'] ?? '') === 'expired') {
                    ($this->cancelUnpaidOrder)((int) $order['id'], 'failed');
                    throw new HttpException('A sessão de pagamento expirou. Você pode iniciar uma nova tentativa.', 409, [], 'payment_session_expired');
                }
                if (($session['status'] ?? '') === 'complete') {
                    throw new HttpException('O provedor ainda está confirmando o pagamento deste pedido.', 409, ['orderNumber' => $number], 'payment_confirmation_pending');
                }
                throw $this->unverifiedCheckout($number, 'Não foi possível confirmar o estado da sessão. O pedido continua protegido; tente novamente.');
            } catch (HttpException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw $this->unverifiedCheckout($number, 'Não foi possível recuperar sua sessão segura. O pedido continua protegido; tente novamente.');
            }
        }

        try {
            $reservationExpired = $this->reservationHasExpired($order);
        } catch (Throwable $exception) {
            throw $this->unverifiedCheckout($number, 'Não foi possível confirmar o prazo da reserva. O pedido continua protegido; tente novamente em instantes.');
        }
        if ($reservationExpired && !$previouslyAttemptedWithoutSession) {
            ($this->cancelUnpaidOrder)((int) $order['id'], 'failed');
            throw new HttpException('A reserva do pedido expirou sem uma sessão pagável. Você pode iniciar uma nova tentativa.', 409, ['orderNumber' => $number], 'payment_session_expired');
        }

        try {
            $items = $this->orderItems((int) $order['id']);
            $order = $this->markStripeCheckoutAttempt($order);
        } catch (Throwable $exception) {
            if ($previouslyAttemptedWithoutSession) {
                error_log("Could not safely retry the unresolved Checkout preparation for order {$number}: {$exception->getMessage()}");
                throw $this->unverifiedCheckout($number, 'A tentativa anterior ainda pode ter criado uma sessão. O estoque permanece protegido; tente recuperar este mesmo pedido quando o serviço estiver disponível.');
            }
            $this->cancelBeforeProviderOrThrow($order, $number, $exception);
        }

        try {
            $session = $this->stripe->createCheckoutSession($order, $items);
        } catch (Throwable $exception) {
            if (CheckoutFailurePolicy::shouldReleaseReservation($exception)) {
                $this->cancelReservationOrThrow($order, $number);
                error_log("Stripe definitively rejected Checkout creation for order {$number}: {$exception->getMessage()}");
                throw new HttpException('O provedor recusou a criação do pagamento. O pedido foi encerrado e o estoque liberado; tente novamente.', 502, ['orderNumber' => $number], 'stripe_checkout_rejected');
            }
            error_log("Stripe Checkout creation outcome is unresolved for order {$number}: {$exception->getMessage()}");
            throw $this->unverifiedCheckout($number, 'Não foi possível confirmar se a Stripe criou a sessão. O pedido e o estoque ficam protegidos; use “Tentar novamente” para recuperar a mesma tentativa.');
        }

        $sessionId = (string) ($session['id'] ?? '');
        try {
            $checkoutUrl = (string) ($session['url'] ?? '');
            if ($sessionId === '' || !preg_match('/^cs_(?:test|live)_[A-Za-z0-9]+$/', $sessionId)
                || !PaymentPolicy::stripeObjectMatchesOrder($session, $order)
                || !$this->isSecureCheckoutUrl($checkoutUrl)
                || strtolower((string) ($session['currency'] ?? '')) !== strtolower((string) $order['currency'])
                || (int) ($session['amount_total'] ?? -1) !== $totalCents) {
                $this->expireUnusableSession($sessionId, $order, $number);
                throw $this->unverifiedCheckout($number, 'A Stripe devolveu uma sessão inválida ou incompatível. O link não foi liberado e o pedido continua protegido para reconciliação.');
            }
            $sessionExpiresAt = $this->checkoutSessionExpiry($session);
            $this->attachStripeSession($order, $session, $sessionId, $sessionExpiresAt);
            $savedOrder = $this->orderForUser($number, (int) $user['id']);
            if (!PaymentPolicy::checkoutSessionIsActive($savedOrder ?? [], $sessionId)
                || strtotime((string) ($savedOrder['reservation_expires_at'] ?? '')) <= time()) {
                $latestSession = $this->expireCheckoutSessionIfOpen($sessionId);
                if (($latestSession['payment_status'] ?? '') === 'paid') {
                    ($this->applyCheckoutEvent)($latestSession, true);
                    $savedOrder = $this->orderForUser($number, (int) $user['id']) ?? $savedOrder;
                    if (($savedOrder['payment_status'] ?? null) === 'paid') {
                        return ['order' => $this->serializeOrder($savedOrder), 'checkoutUrl' => null, 'alreadyPaid' => true];
                    }
                }
                if (($latestSession['status'] ?? '') === 'expired') {
                    ($this->cancelUnpaidOrder)((int) $order['id'], 'failed');
                    throw new HttpException('O pedido deixou de estar disponível antes do pagamento; a sessão foi encerrada e o estoque liberado.', 409, ['orderNumber' => $number], 'order_changed');
                }
                throw $this->unverifiedCheckout($number, 'Não foi possível confirmar a associação da sessão Stripe ao pedido. O link não foi liberado; tente retomar o mesmo pedido.');
            }
            $this->orders->notifyPaymentReady((int) $order['id'], $checkoutUrl);
            return ['order' => $this->serializeOrder($savedOrder ?? $order), 'checkoutUrl' => $checkoutUrl];
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($sessionId !== '') {
                $this->expireUnusableSession($sessionId, $order, $number);
            }
            error_log("Stripe Checkout session for order {$number} could not be safely attached: {$exception->getMessage()}");
            throw $this->unverifiedCheckout($number, 'Não foi possível confirmar a sessão de pagamento. O pedido continua protegido; tente recuperar a mesma sessão.');
        }
    }

    private function markStripeCheckoutAttempt(array $order): array
    {
        $expectedTestPayment = PaymentPolicy::expectedStripeLivemode() ? 0 : 1;
        if (($order['payment_provider'] ?? null) === 'stripe'
            && !PaymentPolicy::orderPaymentModeMatchesEnvironment($order)) {
            throw new HttpException('Este pedido pertence a outro modo Stripe e não pode ser retomado.', 409, [], 'payment_mode_changed');
        }
        $update = $this->pdo->prepare(
            "UPDATE orders SET payment_provider = 'stripe', test_payment = :testPayment,
                    payment_session_attempted_at = COALESCE(payment_session_attempted_at, CURRENT_TIMESTAMP),
                    reservation_expires_at = NULL
             WHERE id = :id AND status = 'awaiting_payment' AND payment_status = 'pending'
                   AND payment_session_id IS NULL AND (payment_provider IS NULL OR payment_provider = 'stripe')"
        );
        $update->execute([':testPayment' => $expectedTestPayment, ':id' => $order['id']]);

        $check = $this->pdo->prepare(
            'SELECT * FROM orders WHERE id = :id LIMIT 1'
        );
        $check->execute([':id' => $order['id']]);
        $current = $check->fetch();
        if (!$current || $current['status'] !== 'awaiting_payment' || $current['payment_status'] !== 'pending'
            || $current['payment_provider'] !== 'stripe' || $current['payment_session_id'] !== null
            || !PaymentPolicy::orderPaymentModeMatchesEnvironment($current)) {
            throw new RuntimeException('The order is no longer eligible for Stripe Checkout creation.');
        }
        return $current;
    }

    private function attachStripeSession(array $order, array $session, string $sessionId, int $sessionExpiresAt): void
    {
        $update = $this->pdo->prepare(
            "UPDATE orders SET payment_provider = 'stripe', payment_session_id = :sessionId,
                    test_payment = :testPayment,
                    reservation_expires_at = GREATEST(
                        COALESCE(reservation_expires_at, CURRENT_TIMESTAMP),
                        FROM_UNIXTIME(:sessionExpiresAt) + INTERVAL 5 MINUTE
                    )
             WHERE id = :id AND status = 'awaiting_payment' AND payment_status = 'pending'
                   AND payment_provider = 'stripe' AND payment_session_id IS NULL"
        );
        $update->execute([
            ':sessionId' => $sessionId,
            ':testPayment' => $session['livemode'] ? 0 : 1,
            ':sessionExpiresAt' => $sessionExpiresAt,
            ':id' => $order['id'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The Stripe session could not be attached to the pending order.');
        }
    }

    private function cancelBeforeProviderOrThrow(array $order, string $number, Throwable $cause): never
    {
        $this->cancelReservationOrThrow($order, $number);
        if ($cause instanceof HttpException) {
            throw $cause;
        }
        error_log("Checkout preparation failed before contacting Stripe for order {$number}: {$cause->getMessage()}");
        throw new HttpException('Não foi possível preparar o pagamento. O pedido foi encerrado e o estoque liberado; tente novamente.', 503, ['orderNumber' => $number], 'checkout_preparation_failed');
    }

    private function cancelReservationOrThrow(array $order, string $number): void
    {
        try {
            ($this->cancelUnpaidOrder)((int) $order['id'], 'failed');
        } catch (Throwable $cleanupFailure) {
            error_log("Could not release the reservation for order {$number} after a definite pre-checkout failure: {$cleanupFailure->getMessage()}");
            throw $this->unverifiedCheckout($number, 'O pagamento não foi aberto, mas não foi possível confirmar a liberação do estoque. Tente verificar este pedido novamente.');
        }
    }

    private function unverifiedCheckout(string $number, string $message): HttpException
    {
        return new HttpException($message, 503, ['orderNumber' => $number], 'payment_session_unverified');
    }

    private function expireUnusableSession(string $sessionId, array $order, string $number): void
    {
        if (!preg_match('/^cs_(?:test|live)_[A-Za-z0-9]+$/', $sessionId)) {
            return;
        }
        try {
            $session = $this->expireCheckoutSessionIfOpen($sessionId);
            if (($session['payment_status'] ?? '') === 'paid'
                && PaymentPolicy::stripeObjectMatchesOrder($session, $order)) {
                ($this->applyCheckoutEvent)($session, true);
                return;
            }
            if (($session['status'] ?? '') === 'expired' && ($session['payment_status'] ?? '') !== 'paid') {
                ($this->cancelUnpaidOrder)((int) $order['id'], 'failed');
                return;
            }
            error_log("Unexposed Stripe Checkout session remains unresolved for order {$number}; inventory stays reserved.");
        } catch (Throwable $exception) {
            error_log("Unexposed Stripe Checkout session could not be reconciled for order {$number}: {$exception->getMessage()}");
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

    private function isSecureCheckoutUrl(string $url): bool
    {
        return PaymentPolicy::isSecureStripeCheckoutUrl($url);
    }

    private function expireCheckoutSessionIfOpen(string $sessionId): array
    {
        try {
            $session = $this->stripe->retrieveCheckoutSession($sessionId);
            if (($session['status'] ?? '') === 'open' && ($session['payment_status'] ?? '') !== 'paid') {
                try {
                    $this->stripe->expireCheckoutSession($sessionId);
                } catch (Throwable $exception) {
                    // A concurrent completion can make expiration fail. The fresh
                    // read below decides whether the session is paid or unusable.
                    error_log('Could not expire an unexposed checkout session cleanly: ' . $exception->getMessage());
                }
                $session = $this->stripe->retrieveCheckoutSession($sessionId);
            }
            return $session;
        } catch (Throwable $exception) {
            throw new HttpException('Não foi possível confirmar o estado da sessão de pagamento. Nenhum novo link foi liberado; tente novamente em instantes.', 502, [], 'payment_session_unverified');
        }
    }

    private function checkoutSessionExpiry(array $session): int
    {
        $expiresAt = filter_var($session['expires_at'] ?? null, FILTER_VALIDATE_INT);
        if ($expiresAt === false || $expiresAt <= time()) {
            throw new RuntimeException('The payment provider returned an invalid checkout expiry.');
        }
        return $expiresAt;
    }

    private function extendReservationForCheckoutSession(int $orderId, string $sessionId, int $sessionExpiresAt): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE orders SET reservation_expires_at = GREATEST(
                        COALESCE(reservation_expires_at, CURRENT_TIMESTAMP),
                        FROM_UNIXTIME(:sessionExpiresAt) + INTERVAL 5 MINUTE
                    )
             WHERE id = :id AND status = 'awaiting_payment' AND payment_status = 'pending'
                   AND payment_provider = 'stripe' AND payment_session_id = :sessionId"
        );
        $statement->execute([
            ':sessionExpiresAt' => $sessionExpiresAt,
            ':id' => $orderId,
            ':sessionId' => $sessionId,
        ]);
    }

    private function orderForUser(string $number, int $userId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orders WHERE order_number = :number AND user_id = :userId LIMIT 1');
        $statement->execute([':number' => $number, ':userId' => $userId]);
        $order = $statement->fetch();
        return $order ?: null;
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

    private function markSimulatedPayment(int $orderId): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $statement = $this->pdo->prepare(
                "UPDATE orders SET payment_provider = 'local_test', payment_status = 'paid',
                        status = 'confirmed', test_payment = TRUE, reservation_expires_at = NULL
                 WHERE id = :id AND payment_status = 'pending' AND status = 'awaiting_payment'"
            );
            $statement->execute([':id' => $orderId]);

            $order = $this->pdo->prepare('SELECT status, payment_status, payment_provider FROM orders WHERE id = :id LIMIT 1');
            $order->execute([':id' => $orderId]);
            $current = $order->fetch();
            if ($current && $current['status'] === 'confirmed'
                && $current['payment_status'] === 'paid' && $current['payment_provider'] === 'local_test') {
                $queue = $this->pdo->prepare(
                    "INSERT IGNORE INTO payment_notification_outbox (order_id, notification_type)
                     VALUES (:orderId, 'payment_confirmation')"
                );
                $queue->execute([':orderId' => $orderId]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
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

    private function cents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new RuntimeException('An order has an invalid stored amount.');
        }
        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
