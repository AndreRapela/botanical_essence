<?php

declare(strict_types=1);

final class StripeApiException extends RuntimeException
{
    public function __construct(
        public readonly ?int $httpStatus,
        public readonly ?string $providerCode,
        public readonly ?string $providerType,
        public readonly string $providerMessage,
        public readonly bool $definitive
    ) {
        parent::__construct('The payment provider could not complete the request.');
    }

    public static function fromTransportFailure(): self
    {
        return new self(null, 'transport_error', null, 'No response was received from Stripe.', false);
    }

    public static function isDefinitiveHttpStatus(?int $status): bool
    {
        return $status !== null && $status >= 400 && $status < 500 && !in_array($status, [408, 409], true);
    }
}

/** A reservation may be released only when Stripe definitively rejected creation. */
final class CheckoutFailurePolicy
{
    public static function shouldReleaseReservation(Throwable $exception): bool
    {
        return $exception instanceof StripeApiException && $exception->definitive;
    }
}

final class StripeGateway
{
    private const API = 'https://api.stripe.com/v1';
    private const MAX_RESPONSE_BYTES = 4_194_304;

    /** @var Closure(string, resource, int): array{body: string|false, statusLine: string} */
    private readonly Closure $httpTransport;

    /**
     * @param callable(string, resource, int): array{body: string|false, statusLine: string}|null $httpTransport
     */
    public function __construct(?callable $httpTransport = null)
    {
        $this->httpTransport = $httpTransport !== null
            ? Closure::fromCallable($httpTransport)
            : static function (string $url, $context, int $maxBytes): array {
                $body = @file_get_contents($url, false, $context, 0, $maxBytes);
                return ['body' => $body, 'statusLine' => $http_response_header[0] ?? ''];
            };
    }

    public function configured(): bool
    {
        return trim((string) getenv('STRIPE_SECRET_KEY')) !== '';
    }

    public function secretKey(): string
    {
        return trim((string) getenv('STRIPE_SECRET_KEY'));
    }

    public function retrieveAccount(): array
    {
        return $this->request('GET', '/account');
    }

    /** Validate all redirect URL configuration before an order reserves inventory. */
    public function assertCheckoutRedirectConfiguration(): void
    {
        $this->appUrl();
    }

    public function createCheckoutSession(array $order, array $items): array
    {
        PaymentPolicy::assertOrderAmountAllowed(
            (string) $order['payment_method'],
            (string) $order['currency'],
            $this->cents((string) $order['total'])
        );
        // This is the customer's presentment/charge currency. Stripe account settlement is separate.
        $chargeCurrency = strtolower((string) $order['currency']);
        $params = [
            'mode' => 'payment',
            'ui_mode' => 'hosted',
            'locale' => 'auto',
            'expires_at' => (string) self::checkoutExpiryForAttempt($order),
            'client_reference_id' => (string) $order['order_number'],
            'customer_email' => (string) $order['email'],
            // Keep provider object IDs out of browser history, referrers and access logs.
            // The order number is stored in user-scoped session storage before redirect,
            // then the authenticated client reconciles the order by its public number.
            'success_url' => $this->appUrl() . '/payment?payment=success',
            'cancel_url' => $this->appUrl() . '/payment?' . http_build_query([
                'payment' => 'cancelled',
            ]),
            'payment_method_types[0]' => (string) $order['payment_method'],
            'metadata[order_number]' => (string) $order['order_number'],
            'payment_intent_data[metadata][order_number]' => (string) $order['order_number'],
        ];
        if ($order['payment_method'] === 'card') {
            $params['payment_intent_data[payment_method_options][card][request_three_d_secure]'] = 'automatic';
        }

        foreach ($items as $index => $item) {
            $prefix = "line_items[{$index}]";
            $params["{$prefix}[price_data][currency]"] = $chargeCurrency;
            $params["{$prefix}[price_data][unit_amount]"] = (string) $this->cents((string) $item['unitPrice']);
            $params["{$prefix}[price_data][product_data][name]"] = (string) $item['productName'];
            $params["{$prefix}[quantity]"] = (string) $item['quantity'];
        }
        $shippingCents = $this->cents((string) $order['shipping_amount']);
        if ($shippingCents > 0) {
            $index = count($items);
            $prefix = "line_items[{$index}]";
            $params["{$prefix}[price_data][currency]"] = $chargeCurrency;
            $params["{$prefix}[price_data][unit_amount]"] = (string) $shippingCents;
            $params["{$prefix}[price_data][product_data][name]"] = 'Estimated shipping';
            $params["{$prefix}[quantity]"] = '1';
        }

        return $this->request('POST', '/checkout/sessions', $params, 'checkout-' . (string) $order['order_number']);
    }

    /** Keep the exact same request body when replaying a Stripe idempotency key. */
    public static function checkoutExpiryForAttempt(array $order): int
    {
        $attemptedAt = $order['payment_session_attempted_at'] ?? null;
        if (!is_string($attemptedAt)) {
            throw new RuntimeException('A Stripe Checkout attempt timestamp is required for idempotent session creation.');
        }
        $started = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $attemptedAt, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($started === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException('A Stripe Checkout attempt timestamp is invalid.');
        }
        return $started->getTimestamp() + 55 * 60;
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        return $this->request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
    }

    public function expireCheckoutSession(string $sessionId): array
    {
        return $this->request('POST', '/checkout/sessions/' . rawurlencode($sessionId) . '/expire');
    }

    public function retrieveDispute(string $disputeId): array
    {
        return $this->request('GET', '/disputes/' . rawurlencode($disputeId));
    }

    public function listDisputes(int $createdGte, ?string $startingAfter = null, ?int $createdLte = null): array
    {
        if ($createdGte < 0 || ($createdLte !== null && $createdLte < $createdGte)) {
            throw new InvalidArgumentException('Invalid dispute recovery time interval.');
        }
        $query = ['limit' => 100, 'created' => ['gte' => $createdGte]];
        if ($createdLte !== null) $query['created']['lte'] = $createdLte;
        if ($startingAfter !== null && $startingAfter !== '') {
            $query['starting_after'] = $startingAfter;
        }
        return $this->request('GET', '/disputes?' . http_build_query($query));
    }

    public function retrieveCharge(string $chargeId): array
    {
        return $this->request('GET', '/charges/' . rawurlencode($chargeId));
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        return $this->request('GET', '/payment_intents/' . rawurlencode($paymentIntentId));
    }

    public function refundPaymentIntent(string $paymentIntentId, string $orderNumber, int $attempt, ?int $amountCents = null): array
    {
        $params = [
            'payment_intent' => $paymentIntentId,
            'reason' => 'requested_by_customer',
            'metadata[order_number]' => $orderNumber,
            'metadata[refund_attempt]' => (string) $attempt,
        ];
        if ($amountCents !== null) {
            if ($amountCents < 1) {
                throw new InvalidArgumentException('Refund amount must be positive.');
            }
            $params['amount'] = (string) $amountCents;
        }
        return $this->request(
            'POST',
            '/refunds',
            $params,
            'refund-' . $orderNumber . '-' . $attempt
        );
    }

    public function retrieveRefund(string $refundId): array
    {
        return $this->request('GET', '/refunds/' . rawurlencode($refundId));
    }

    public function refundsForPaymentIntent(string $paymentIntentId): array
    {
        $query = http_build_query(['payment_intent' => $paymentIntentId, 'limit' => 100]);
        $response = $this->request('GET', '/refunds?' . $query);
        return is_array($response['data'] ?? null) ? $response['data'] : [];
    }

    private function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            throw new RuntimeException('Stripe is not configured.');
        }
        $headers = [
            'Authorization: Bearer ' . $secret,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $method === 'GET' ? '' : http_build_query($params),
                'timeout' => 12,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
                // The Bearer secret must never be forwarded to a redirect target.
                // Stripe API endpoints do not need redirects; treat any 3xx as an error.
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $transportResponse = ($this->httpTransport)(self::API . $path, $context, self::MAX_RESPONSE_BYTES + 1);
        if (!is_array($transportResponse)
            || (!is_string($transportResponse['body'] ?? null) && ($transportResponse['body'] ?? null) !== false)
            || !is_string($transportResponse['statusLine'] ?? null)) {
            throw new RuntimeException('The payment provider returned an invalid transport response.');
        }
        $responseBody = $transportResponse['body'];
        $statusLine = $transportResponse['statusLine'];
        preg_match('/\s([1-5][0-9]{2})\s/', $statusLine, $statusMatch);
        $status = (int) ($statusMatch[1] ?? 0);
        if ($responseBody === false && $status === 0) {
            throw StripeApiException::fromTransportFailure();
        }
        if (is_string($responseBody) && strlen($responseBody) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('The payment provider returned an oversized response.');
        }
        if ($status < 200 || $status >= 300) {
            $body = is_string($responseBody) ? json_decode($responseBody, true) : null;
            $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
            $providerCode = is_string($error['code'] ?? null) ? $this->safeProviderCode($error['code']) : null;
            $providerType = is_string($error['type'] ?? null) ? $this->safeProviderCode($error['type']) : null;
            $providerMessage = is_string($error['message'] ?? null)
                ? $this->safeProviderMessage($error['message'])
                : 'Stripe returned HTTP ' . ($status ?: 'without a status') . '.';
            $definitive = StripeApiException::isDefinitiveHttpStatus($status > 0 ? $status : null);
            error_log('Stripe API request failed with HTTP status ' . ($status ?: 'unavailable')
                . ($providerCode !== null ? ' (' . $providerCode . ')' : '') . '.');
            throw new StripeApiException($status > 0 ? $status : null, $providerCode, $providerType, $providerMessage, $definitive);
        }
        if ($responseBody === false) {
            throw StripeApiException::fromTransportFailure();
        }
        try {
            $response = json_decode($responseBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The payment provider returned an invalid response.', 0, $exception);
        }
        if (!is_array($response)) {
            throw new RuntimeException('The payment provider returned an invalid response.');
        }
        return $response;
    }

    private function safeProviderMessage(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($message)) ?? '';
        $message = $message !== '' ? $message : 'Stripe rejected the request.';
        return function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
    }

    private function safeProviderCode(string $code): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '', $code) ?? '';
        return substr($safe !== '' ? $safe : 'provider_error', 0, 100);
    }

    private function appUrl(): string
    {
        $url = rtrim((string) (getenv('APP_URL') ?: ''), '/');
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL)
            || !is_array($parts)
            || !isset($parts['host'])
            || !in_array($scheme, ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('APP_URL must be an absolute URL before payments can be enabled.');
        }
        if ((getenv('APP_ENV') ?: 'production') !== 'development' && $scheme !== 'https') {
            throw new RuntimeException('Production payment redirects require HTTPS.');
        }
        return $url;
    }

    private function cents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new RuntimeException('An order has an invalid stored amount.');
        }
        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
