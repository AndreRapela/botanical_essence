<?php

declare(strict_types=1);

final class PaymentPolicy
{
    public const MODE_LOCAL_SIMULATION = 'local_simulation';
    public const MODE_STRIPE_TEST = 'stripe_test';
    public const MODE_STRIPE_LIVE = 'stripe_live';
    public const STRIPE_CHECKOUT_IDEMPOTENCY_REPLAY_SECONDS = 23 * 60 * 60;

    private const STRIPE_MINIMUM_CENTS_BY_CURRENCY = [
        'EUR' => 50,
        'USD' => 50,
    ];

    // Stripe accepts at most eight digits in minor units for EUR and USD.
    private const STRIPE_MAXIMUM_CENTS_BY_CURRENCY = [
        'EUR' => 99_999_999,
        'USD' => 99_999_999,
    ];

    private const STRIPE_LIVEMODE_BY_ENVIRONMENT = [
        'development' => false,
        'test' => false,
        'staging' => false,
        'production' => true,
    ];

    public static function executionMode(?string $configuredMode = null, ?string $environment = null): string
    {
        $environment ??= strtolower(trim((string) (getenv('APP_ENV') ?: 'production')));
        $mode = strtolower(trim($configuredMode ?? (string) getenv('PAYMENT_EXECUTION_MODE')));
        if ($mode === '') {
            // Backward compatibility for existing development .env files. New
            // deployments should set PAYMENT_EXECUTION_MODE explicitly.
            $legacySimulation = $environment === 'development'
                && filter_var(getenv('PAYMENT_TEST_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN);
            $mode = $legacySimulation
                ? self::MODE_LOCAL_SIMULATION
                : ($environment === 'production' ? self::MODE_STRIPE_LIVE : self::MODE_STRIPE_TEST);
        }

        if (!in_array($mode, [self::MODE_LOCAL_SIMULATION, self::MODE_STRIPE_TEST, self::MODE_STRIPE_LIVE], true)) {
            throw new HttpException('PAYMENT_EXECUTION_MODE inválido. Use local_simulation, stripe_test ou stripe_live.', 503, [], 'invalid_payment_mode');
        }
        self::expectedStripeLivemode($environment);
        if ($mode === self::MODE_LOCAL_SIMULATION && $environment !== 'development') {
            throw new HttpException('A simulação local de pagamentos só pode ser usada em APP_ENV=development.', 503, [], 'simulation_environment_mismatch');
        }
        if ($mode === self::MODE_STRIPE_TEST && self::expectedStripeLivemode($environment)) {
            throw new HttpException('stripe_test exige um ambiente não produtivo.', 503, [], 'stripe_environment_mismatch');
        }
        if ($mode === self::MODE_STRIPE_LIVE && !self::expectedStripeLivemode($environment)) {
            throw new HttpException('stripe_live só pode ser usado em APP_ENV=production.', 503, [], 'stripe_environment_mismatch');
        }
        return $mode;
    }

    public static function localSimulationEnabled(): bool
    {
        return self::executionMode() === self::MODE_LOCAL_SIMULATION;
    }

    /** @deprecated Use localSimulationEnabled() to make the no-PSP behavior explicit. */
    public static function testModeEnabled(): bool
    {
        return self::localSimulationEnabled();
    }

    public static function expectedStripeLivemode(?string $environment = null): bool
    {
        $environment ??= (string) (getenv('APP_ENV') ?: 'production');
        $environment = strtolower(trim($environment));
        if (!array_key_exists($environment, self::STRIPE_LIVEMODE_BY_ENVIRONMENT)) {
            throw new HttpException('APP_ENV inválido para pagamentos. Use development, test, staging ou production.', 503, [], 'invalid_payment_environment');
        }
        return self::STRIPE_LIVEMODE_BY_ENVIRONMENT[$environment];
    }

    public static function assertStripeKeyMatchesEnvironment(?string $secretKey = null, ?string $environment = null): void
    {
        $expectedLivemode = self::expectedStripeLivemode($environment);
        $secretKey = trim($secretKey ?? (string) getenv('STRIPE_SECRET_KEY'));
        if ($secretKey === '') {
            return;
        }

        $actualLivemode = match (true) {
            str_starts_with($secretKey, 'sk_live_') => true,
            str_starts_with($secretKey, 'sk_test_') => false,
            default => throw new HttpException('A chave secreta Stripe deve começar com sk_test_ ou sk_live_.', 503, [], 'invalid_stripe_key'),
        };
        if ($actualLivemode !== $expectedLivemode) {
            throw new HttpException(
                $expectedLivemode
                    ? 'APP_ENV=production exige uma chave Stripe sk_live_.'
                    : 'Este ambiente exige uma chave Stripe sk_test_; cobranças live foram bloqueadas.',
                503,
                [],
                'stripe_environment_mismatch'
            );
        }
    }

    public static function stripeObjectMatchesEnvironment(array $stripeObject, ?string $environment = null): bool
    {
        return is_bool($stripeObject['livemode'] ?? null)
            && $stripeObject['livemode'] === self::expectedStripeLivemode($environment);
    }

    public static function stripeObjectMatchesOrder(array $stripeObject, array $order, ?string $environment = null): bool
    {
        if (!self::stripeObjectMatchesEnvironment($stripeObject, $environment)) {
            return false;
        }
        $isTestPayment = self::orderIsTestPayment($order);
        return $isTestPayment !== null && $isTestPayment === !$stripeObject['livemode'];
    }

    /** Reject a Stripe refund unless it exactly matches a refund attempt created for this order. */
    public static function assertStripeRefundMatchesAttempt(array $refund, array $order, int $attempt, int $expectedAmountCents, ?string $boundRefundId = null): void
    {
        $paymentIntent = $refund['payment_intent'] ?? null;
        $paymentIntentId = is_array($paymentIntent)
            ? (string) ($paymentIntent['id'] ?? '')
            : (is_string($paymentIntent) ? $paymentIntent : '');
        $metadata = $refund['metadata'] ?? null;
        $metadataAttempt = is_array($metadata) ? ($metadata['refund_attempt'] ?? null) : null;

        if (!preg_match('/^re_[A-Za-z0-9]+$/', (string) ($refund['id'] ?? ''))
            || ($order['payment_provider'] ?? null) !== 'stripe'
            || !self::stripeObjectMatchesOrder($refund, $order)
            || $paymentIntentId === ''
            || !is_string($order['payment_intent_id'] ?? null)
            || !hash_equals((string) $order['payment_intent_id'], $paymentIntentId)) {
            throw new HttpException('O reembolso Stripe não corresponde ao pagamento e ao ambiente deste pedido.', 409, [], 'stripe_refund_mismatch');
        }

        if ($boundRefundId !== null && !hash_equals($boundRefundId, (string) $refund['id'])) {
            throw new HttpException('A referência Stripe diverge da tentativa vinculada.', 409, [], 'stripe_refund_attempt_mismatch');
        }
        if ($boundRefundId === null && (!is_array($metadata)
            || !is_string($order['order_number'] ?? null)
            || !hash_equals((string) $order['order_number'], (string) ($metadata['order_number'] ?? ''))
            || !(is_int($metadataAttempt) || (is_string($metadataAttempt) && ctype_digit($metadataAttempt)))
            || (int) $metadataAttempt !== $attempt)) {
            throw new HttpException('A tentativa de reembolso Stripe não corresponde ao pedido.', 409, [], 'stripe_refund_attempt_mismatch');
        }

        $expectedCurrency = strtolower((string) ($order['currency'] ?? ''));
        if ($expectedAmountCents < 1
            || !is_int($refund['amount'] ?? null)
            || $refund['amount'] !== $expectedAmountCents
            || !in_array($expectedCurrency, ['eur', 'usd'], true)
            || strtolower((string) ($refund['currency'] ?? '')) !== $expectedCurrency) {
            throw new HttpException('O valor ou a moeda do reembolso Stripe não corresponde ao saldo autorizado.', 409, [], 'stripe_refund_amount_mismatch');
        }
    }

    public static function orderPaymentModeMatchesEnvironment(array $order, ?string $environment = null): bool
    {
        $isTestPayment = self::orderIsTestPayment($order);
        return $isTestPayment !== null && $isTestPayment === !self::expectedStripeLivemode($environment);
    }

    /** Stripe may prune idempotency keys after 24 hours; retry only within a safety margin. */
    public static function canSafelyReplayCheckoutCreation(array $order, ?int $now = null): bool
    {
        $startedAt = self::checkoutAttemptTimestamp($order, false);
        if ($startedAt === null) return false;
        $now ??= time();
        $age = $now - $startedAt;
        return $age >= 0 && $age < self::STRIPE_CHECKOUT_IDEMPOTENCY_REPLAY_SECONDS;
    }

    /** A stale unknown attempt can only be released after an operator checks the provider independently. */
    public static function requiresManualCheckoutReconciliation(array $order, ?int $now = null): bool
    {
        $startedAt = self::checkoutAttemptTimestamp($order, true);
        if ($startedAt === null) return false;
        $age = ($now ?? time()) - $startedAt;
        return $age >= self::STRIPE_CHECKOUT_IDEMPOTENCY_REPLAY_SECONDS;
    }

    private static function checkoutAttemptTimestamp(array $order, bool $allowLegacyOrderTime): ?int
    {
        $attemptedAt = $order['payment_session_attempted_at'] ?? null;
        if ((!is_string($attemptedAt) || $attemptedAt === '') && $allowLegacyOrderTime) {
            $attemptedAt = $order['created_at'] ?? null;
        }
        if (!is_string($attemptedAt) || $attemptedAt === '') return null;
        $started = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $attemptedAt, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($started === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $started->getTimestamp();
    }

    public static function isSecureStripeCheckoutUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        return in_array($host, ['checkout.stripe.com', 'buy.stripe.com'], true);
    }

    private static function orderIsTestPayment(array $order): ?bool
    {
        $testPayment = $order['test_payment'] ?? null;
        if (!in_array($testPayment, [true, false, 1, 0, '1', '0'], true)) {
            return null;
        }
        return in_array($testPayment, [true, 1, '1'], true);
    }

    public static function testProductSlug(): string
    {
        $slug = getenv('PAYMENT_TEST_PRODUCT_SLUG') ?: 'monstera-essencial';
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) ? $slug : 'monstera-essencial';
    }

    public static function isTestProduct(string $slug): bool
    {
        return self::localSimulationEnabled() && hash_equals(self::testProductSlug(), $slug);
    }

    public static function unitCents(string $slug, int $storedCents): int
    {
        return self::isTestProduct($slug) ? 1 : $storedCents;
    }

    public static function freeTestShipping(array $quote): array
    {
        if (!self::localSimulationEnabled()) {
            return $quote;
        }
        $quote['source'] = 'payment-test-free-shipping';
        $quote['quotes'] = array_map(static fn (array $rate): array => [
            ...$rate,
            'amountCents' => 0,
            'amount' => 0.0,
            'description' => 'Free delivery in local payment test mode.',
        ], $quote['quotes'] ?? []);
        return $quote;
    }

    /**
     * Returns account-scoped limits explicitly configured for this Stripe
     * account, settlement currency, payment method and presentment currency.
     * Cross-currency minima cannot safely be inferred from a static FX rate.
     *
     * @return array{accountId:string,settlementCurrency:string,method:string,currency:string,minCents:int,maxCents:int}
     */
    public static function stripePaymentLimits(string $method, string $currency): array
    {
        $method = strtolower(trim($method));
        $currency = strtoupper(trim($currency));
        if (!in_array($method, ['card', 'bancontact', 'sepa_debit'], true)
            || (in_array($method, ['bancontact', 'sepa_debit'], true) && $currency !== 'EUR')
            || !isset(self::STRIPE_MINIMUM_CENTS_BY_CURRENCY[$currency])) {
            throw new HttpException('Este método de pagamento não aceita a moeda selecionada.', 422, [
                'payment' => 'Choose a supported payment method and currency.',
            ], 'unsupported_payment_currency');
        }

        $accountId = trim((string) getenv('STRIPE_ACCOUNT_ID'));
        $settlementCurrency = strtoupper(trim((string) getenv('STRIPE_SETTLEMENT_CURRENCY')));
        if (!preg_match('/^acct_[A-Za-z0-9]+$/', $accountId)
            || !isset(self::STRIPE_MINIMUM_CENTS_BY_CURRENCY[$settlementCurrency])) {
            throw new HttpException('Configure STRIPE_ACCOUNT_ID e STRIPE_SETTLEMENT_CURRENCY conforme a conta Stripe antes de aceitar pagamentos.', 503, [], 'stripe_account_configuration_missing');
        }

        $rawLimits = trim((string) getenv('STRIPE_PAYMENT_LIMITS_JSON'));
        try {
            $configuration = $rawLimits !== '' ? json_decode($rawLimits, true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $configuration = null;
        }
        if (!is_array($configuration)
            || strtoupper((string) ($configuration['settlementCurrency'] ?? '')) !== $settlementCurrency
            || !is_array($configuration['methods'] ?? null)) {
            throw new HttpException('Configure STRIPE_PAYMENT_LIMITS_JSON para esta conta e moeda de liquidação.', 503, [], 'stripe_limits_configuration_missing');
        }

        $configured = $configuration['methods'][$method][$currency] ?? null;
        if (!is_array($configured)
            || !is_int($configured['minCents'] ?? null)
            || !is_int($configured['maxCents'] ?? null)) {
            throw new HttpException("Faltam limites Stripe configurados para {$method}/{$currency} nesta conta.", 503, [], 'stripe_method_limits_missing');
        }
        $minimum = $configured['minCents'];
        $maximum = $configured['maxCents'];
        if ($minimum < self::STRIPE_MINIMUM_CENTS_BY_CURRENCY[$currency]
            || $maximum < $minimum
            || $maximum > self::STRIPE_MAXIMUM_CENTS_BY_CURRENCY[$currency]) {
            throw new HttpException("Os limites configurados para {$method}/{$currency} são inválidos ou excedem o teto Stripe.", 503, [], 'invalid_stripe_method_limits');
        }

        return [
            'accountId' => $accountId,
            'settlementCurrency' => $settlementCurrency,
            'method' => $method,
            'currency' => $currency,
            'minCents' => $minimum,
            'maxCents' => $maximum,
        ];
    }

    public static function assertStripeAccountMatchesConfiguration(array $account, array $limits): void
    {
        $accountId = (string) ($account['id'] ?? '');
        $defaultCurrency = strtoupper((string) ($account['default_currency'] ?? ''));
        if ($accountId === '' || !hash_equals($limits['accountId'], $accountId)
            || $defaultCurrency !== $limits['settlementCurrency']) {
            throw new HttpException('A conta Stripe conectada não corresponde à conta/moeda de liquidação configurada; pagamentos foram bloqueados.', 503, [], 'stripe_account_configuration_mismatch');
        }
        $capability = match ($limits['method']) {
            'card' => 'card_payments',
            'bancontact' => 'bancontact_payments',
            'sepa_debit' => 'sepa_debit_payments',
            default => null,
        };
        if ($capability === null || ($account['capabilities'][$capability] ?? null) !== 'active') {
            throw new HttpException('A conta Stripe não tem o método de pagamento ativado. Ative-o no Dashboard antes de aceitar pedidos.', 503, [], 'stripe_payment_method_not_enabled');
        }
    }

    public static function assertOrderAmountAllowed(string $method, string $currency, int $amountCents): void
    {
        $method = strtolower(trim($method));
        $currency = strtoupper(trim($currency));
        if (!in_array($method, ['card', 'bancontact', 'sepa_debit'], true)
            || (in_array($method, ['bancontact', 'sepa_debit'], true) && $currency !== 'EUR')
            || !isset(self::STRIPE_MINIMUM_CENTS_BY_CURRENCY[$currency])) {
            throw new HttpException('Este método de pagamento não aceita a moeda selecionada.', 422, [
                'payment' => 'Choose a supported payment method and currency.',
            ], 'unsupported_payment_currency');
        }

        if (self::localSimulationEnabled()) {
            $maximum = self::STRIPE_MAXIMUM_CENTS_BY_CURRENCY[$currency];
            if ($amountCents <= 0 || $amountCents > $maximum) {
                throw new HttpException('O total ultrapassa o limite seguro configurado para o pedido.', 422, [], 'order_amount_out_of_range');
            }
            return;
        }

        $limits = self::stripePaymentLimits($method, $currency);
        if ($amountCents < $limits['minCents'] || $amountCents > $limits['maxCents']) {
            throw new HttpException(
                "O total deve ficar entre {$limits['minCents']} e {$limits['maxCents']} centavos para {$method}/{$currency} nesta conta Stripe.",
                422,
                ['amountCents' => 'The order total is outside the configured provider limits.'],
                'payment_amount_out_of_range'
            );
        }
    }

    public static function checkoutSessionIsActive(array $order, string $sessionId): bool
    {
        $storedSessionId = $order['payment_session_id'] ?? null;
        return $sessionId !== ''
            && ($order['status'] ?? null) === 'awaiting_payment'
            && ($order['payment_status'] ?? null) === 'pending'
            && is_string($storedSessionId)
            && hash_equals($storedSessionId, $sessionId);
    }

    public static function validStripeSignature(string $payload, string $signatureHeader, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || strlen($payload) > 1_048_576) {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[a-f0-9]{64}$/i', $value)) {
                $signatures[] = strtolower($value);
            }
        }
        if ($timestamp === null || $signatures === [] || abs(($now ?? time()) - $timestamp) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }
        return false;
    }
}
