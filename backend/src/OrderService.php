<?php

declare(strict_types=1);

final class OrderService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $limiter,
        private readonly Mailer $mailer,
        private readonly InventoryService $inventory,
        private readonly ShippingService $shipping,
        private readonly ExchangeRateService $exchangeRates
    )
    {
    }

    public function create(array $input, array $user): array
    {
        Validation::allowedFields($input, [
            'idempotencyKey', 'name', 'email', 'address', 'city', 'postalCode', 'region', 'country',
            'delivery', 'payment', 'currency', 'items', 'exchangeRateToken', 'shippingTariffVersion',
        ]);
        if (($user['emailVerifiedAt'] ?? null) === null) {
            throw new HttpException('Confirme seu e-mail antes de comprar.', 403);
        }
        $idempotencyKey = isset($input['idempotencyKey']) && is_string($input['idempotencyKey'])
            ? trim($input['idempotencyKey'])
            : '';
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $idempotencyKey)) {
            throw new HttpException('Identificador da compra inválido.', 422);
        }

        $name = Validation::requiredString($input, 'name', 2, 120);
        $email = (string) $user['email'];
        if (array_key_exists('email', $input)) {
            $submittedEmail = Validation::email($input);
            if (!hash_equals(strtolower($email), $submittedEmail)) {
                throw new HttpException('O e-mail do pedido deve ser o mesmo da conta autenticada.', 422, [
                    'email' => 'Use o e-mail confirmado da sua conta.',
                ]);
            }
        }
        $address = Validation::requiredString($input, 'address', 5, 200);
        $city = Validation::requiredString($input, 'city', 2, 100);
        $postalCode = Validation::requiredString($input, 'postalCode', 4, 20);
        $region = Validation::optionalString($input, 'region', 100);
        $country = Validation::requiredString($input, 'country', 2, 80);
        $delivery = Validation::requiredString($input, 'delivery', 3, 60);
        $paymentMethod = Validation::oneOf($input, 'payment', ['bancontact', 'card', 'sepa_debit']);
        $currency = Validation::oneOf($input, 'currency', ['USD', 'EUR']);
        $exchangeRateToken = Validation::requiredString($input, 'exchangeRateToken', 64, 64);
        if (!preg_match('/^[a-f0-9]{64}$/i', $exchangeRateToken)) {
            throw new HttpException('A prévia cambial expirou. Atualize a página antes de pagar.', 422, [], 'exchange_rate_changed');
        }
        $shippingTariffVersion = Validation::requiredString($input, 'shippingTariffVersion', 3, 120);
        if ($paymentMethod === 'sepa_debit' && $currency !== 'EUR') {
            throw new HttpException('O débito direto SEPA está disponível apenas para pagamentos em euros.', 422, [
                'currency' => 'SEPA Direct Debit accepts EUR only.',
            ]);
        }
        $quantities = CartItems::quantities($input['items'] ?? null);
        $requestHash = hash('sha256', json_encode([
            'name' => $name,
            'email' => strtolower($email),
            'address' => $address,
            'city' => $city,
            'postalCode' => $postalCode,
            'region' => $region,
            'country' => $country,
            'delivery' => $delivery,
            'shippingTariffVersion' => $shippingTariffVersion,
            'payment' => $paymentMethod,
            'currency' => $currency,
            'items' => $quantities,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->inventory->releaseExpiredReservations();
        $existingOrder = $this->findOrder((int) $user['id'], $idempotencyKey);
        if ($existingOrder !== null) {
            $this->assertSameIdempotentRequest($existingOrder, $requestHash);
            return ['order' => $this->serializeOrder($existingOrder)];
        }
        $this->limiter->assertAllowed('create-order', Request::ip() . '|' . $user['id'], 5, 3600);
        $exchangeRate = $this->exchangeRates->currentReferenceRate();
        if (!hash_equals($exchangeRate['rateToken'], $exchangeRateToken)) {
            throw new HttpException(
                'A taxa de referência mudou desde a prévia. Confira o novo total e tente novamente.',
                409,
                ['currencyRateDate' => $exchangeRate['rateDate']],
                'exchange_rate_changed'
            );
        }

        $this->pdo->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($quantities), '?'));
            $productsStatement = $this->pdo->prepare(
                "SELECT p.id, p.slug, p.seller_id, p.name, p.price, p.stock, p.shipping_weight_grams AS shippingWeightGrams
                 FROM products p INNER JOIN sellers s ON s.id = p.seller_id
                 WHERE p.id IN ({$placeholders}) AND p.status = 'active' AND s.verified = TRUE
                 FOR UPDATE"
            );
            $productsStatement->execute(array_keys($quantities));
            $products = $productsStatement->fetchAll();
            if (count($products) !== count($quantities)) {
                throw new HttpException('Um dos produtos não está mais disponível.', 409);
            }

            $subtotalCents = 0;
            $netWeightGrams = 0;
            $unitPrices = [];
            foreach ($products as $product) {
                $quantity = $quantities[(int) $product['id']];
                if ((int) $product['stock'] < $quantity) {
                    throw new HttpException("Estoque insuficiente para {$product['name']}.", 409);
                }
                $isTestProduct = PaymentPolicy::isTestProduct((string) $product['slug']);
                $unitCents = PaymentPolicy::unitCents((string) $product['slug'], $this->cents((string) $product['price']));
                if (!$isTestProduct) {
                    $unitCents = $this->convertUsdCents($unitCents, $currency, $exchangeRate['eurPerUsd']);
                }
                $unitPrices[(int) $product['id']] = $unitCents;
                $subtotalCents += $unitCents * $quantity;
                $netWeightGrams += (int) $product['shippingWeightGrams'] * $quantity;
            }
            $shippingResponse = $this->shipping->quotesForWeight($country, $postalCode, $netWeightGrams);
            if (!hash_equals($shippingResponse['tariffVersion'], $shippingTariffVersion)) {
                throw new HttpException(
                    'A referência do frete foi atualizada. Recalcule a entrega antes de pagar.',
                    409,
                    [],
                    'shipping_tariff_changed'
                );
            }
            $shippingQuote = $this->shipping->selectedQuote($shippingResponse, $delivery);
            $shippingEurCents = (int) $shippingQuote['amountCents'];
            $shippingCents = $this->convertEurCents($shippingEurCents, $currency, $exchangeRate['usdPerEur']);
            $totalCents = $subtotalCents + $shippingCents;
            PaymentPolicy::assertOrderAmountAllowed($paymentMethod, $currency, $totalCents);
            $orderNumber = 'BE-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));

            $order = $this->pdo->prepare(
                'INSERT INTO orders
                   (order_number, idempotency_key, request_hash, user_id, customer_name, email, address_line, city, region,
                    postal_code, country_code, delivery_method, shipping_weight_grams, shipping_tariff_source,
                    shipping_tariff_reviewed_at, shipping_tariff_valid_through, fx_usd_per_eur, fx_rate_date, fx_rate_source,
                    payment_method, payment_status, status, reservation_expires_at, subtotal, shipping_amount, total, currency)
                 VALUES
                   (:number, :idempotencyKey, :requestHash, :userId, :name, :email, :address, :city, :region,
                    :postalCode, :countryCode, :delivery, :shippingWeight, :shippingSource,
                    :shippingReviewedAt, :shippingValidThrough, :usdPerEur, :fxRateDate, :fxRateSource,
                    :paymentMethod, \'pending\', \'awaiting_payment\', CURRENT_TIMESTAMP + INTERVAL 1 HOUR,
                    :subtotal, :shipping, :total, :currency)'
            );
            $order->execute([
                ':number' => $orderNumber,
                ':idempotencyKey' => $idempotencyKey,
                ':requestHash' => $requestHash,
                ':userId' => $user['id'],
                ':name' => $name,
                ':email' => $email,
                ':address' => $address,
                ':city' => $city,
                ':region' => $region,
                ':postalCode' => $postalCode,
                ':countryCode' => $shippingResponse['destination']['countryCode'],
                ':delivery' => $delivery,
                ':shippingWeight' => $shippingResponse['parcel']['weightGrams'],
                ':shippingSource' => $shippingResponse['source'],
                ':shippingReviewedAt' => $shippingResponse['tariffReviewedAt'],
                ':shippingValidThrough' => $shippingResponse['tariffValidThrough'],
                ':usdPerEur' => number_format($exchangeRate['usdPerEur'], 10, '.', ''),
                ':fxRateDate' => $exchangeRate['rateDate'],
                ':fxRateSource' => $exchangeRate['source'],
                ':paymentMethod' => $paymentMethod,
                ':subtotal' => $this->decimal($subtotalCents),
                ':shipping' => $this->decimal($shippingCents),
                ':total' => $this->decimal($totalCents),
                ':currency' => $currency,
            ]);
            $orderId = (int) $this->pdo->lastInsertId();

            $itemStatement = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, seller_id, product_name, unit_price, quantity, line_total)
                 VALUES (:orderId, :productId, :sellerId, :name, :unitPrice, :quantity, :lineTotal)'
            );
            $stockStatement = $this->pdo->prepare('UPDATE products SET stock = stock - :quantity WHERE id = :id');
            foreach ($products as $product) {
                $quantity = $quantities[(int) $product['id']];
                $unitCents = $unitPrices[(int) $product['id']];
                $itemStatement->execute([
                    ':orderId' => $orderId,
                    ':productId' => $product['id'],
                    ':sellerId' => $product['seller_id'],
                    ':name' => $product['name'],
                    ':unitPrice' => $this->decimal($unitCents),
                    ':quantity' => $quantity,
                    ':lineTotal' => $this->decimal($unitCents * $quantity),
                ]);
                $stockStatement->execute([':quantity' => $quantity, ':id' => $product['id']]);
            }
            $this->pdo->commit();

            $createdOrder = $this->findOrder((int) $user['id'], $idempotencyKey);
            if ($createdOrder === null) {
                throw new RuntimeException('O pedido criado não pôde ser recuperado.');
            }
            return ['order' => $this->serializeOrder($createdOrder), '_status' => 201];
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                $existingOrder = $this->findOrder((int) $user['id'], $idempotencyKey);
                if ($existingOrder !== null) {
                    $this->assertSameIdempotentRequest($existingOrder, $requestHash);
                    return ['order' => $this->serializeOrder($existingOrder)];
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** Send the payment-ready message only after the provider session is attached and usable. */
    public function notifyPaymentReady(int $orderId, string $checkoutUrl): void
    {
        try {
            $statement = $this->pdo->prepare(
                "SELECT order_number, email, total, currency
                 FROM orders
                 WHERE id = :id AND status = 'awaiting_payment' AND payment_status = 'pending'
                       AND payment_provider = 'stripe' AND payment_session_id IS NOT NULL
                       AND payment_ready_email_sent_at IS NULL
                 LIMIT 1"
            );
            $statement->execute([':id' => $orderId]);
            $order = $statement->fetch();
            if (!$order) {
                return;
            }

            $currency = (string) $order['currency'];
            $currencySymbol = $currency === 'EUR' ? '€' : '$';
            $total = number_format((float) $order['total'], 2, '.', '');
            $this->mailer->send(
                (string) $order['email'],
                "Pagamento do pedido {$order['order_number']}",
                "Seu pedido {$order['order_number']} está pronto para pagamento.\n\n"
                    . "Total: {$currencySymbol} {$total} {$currency}\n"
                    . "Conclua o pagamento nesta página segura da Stripe: {$checkoutUrl}\n\n"
                    . 'O pedido e o estoque ficam reservados enquanto esta sessão de pagamento estiver ativa.'
            );

            $markSent = $this->pdo->prepare(
                "UPDATE orders SET payment_ready_email_sent_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status = 'awaiting_payment' AND payment_status = 'pending'
                       AND payment_provider = 'stripe' AND payment_session_id IS NOT NULL
                       AND payment_ready_email_sent_at IS NULL"
            );
            $markSent->execute([':id' => $orderId]);
        } catch (Throwable $exception) {
            // Email is best-effort and must never turn a valid payment session into a failed checkout.
            error_log("Payment-ready email for order {$orderId} could not be sent or recorded: {$exception->getMessage()}");
        }
    }

    public function listForUser(array $user, array $query = []): array
    {
        Validation::allowedFields($query, ['page', 'limit']);
        $page = Validation::queryInteger($query, 'page', 1, 1, 1_000_000);
        $limit = Validation::queryInteger($query, 'limit', 50, 1, 100);
        $offset = ($page - 1) * $limit;
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = :userId');
        $count->execute([':userId' => $user['id']]);
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            "SELECT order_number AS number, status, payment_status AS paymentStatus,
                    subtotal, shipping_amount AS shipping, total, currency, test_payment AS testPayment,
                    fx_usd_per_eur AS usdPerEur, DATE_FORMAT(fx_rate_date, '%Y-%m-%d') AS fxRateDate,
                    fx_rate_source AS fxRateSource,
                    DATE_FORMAT(shipping_tariff_reviewed_at, '%Y-%m-%d') AS shippingTariffReviewedAt,
                    DATE_FORMAT(shipping_tariff_valid_through, '%Y-%m-%d') AS shippingTariffValidThrough,
                    payment_refunded_cents AS refundedTotalCents,
                    DATE_FORMAT(reservation_expires_at, '%Y-%m-%dT%H:%i:%sZ') AS reservationExpiresAt,
                    DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
                    (SELECT JSON_OBJECT('id', r.id, 'number', r.request_number, 'status', r.status, 'type', r.request_type)
                     FROM withdrawal_requests r WHERE r.order_id = orders.id ORDER BY r.id DESC LIMIT 1) AS withdrawalRequest
             FROM orders WHERE user_id = :userId ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset"
        );
        $statement->bindValue(':userId', (int) $user['id'], PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $orders = array_map(fn (array $order): array => $this->serializeOrder($order), $statement->fetchAll());
        return [
            'data' => $orders,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'hasMore' => $offset + $limit < $total,
        ];
    }

    private function findOrder(int $userId, string $idempotencyKey): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT order_number AS number, request_hash AS requestHash, status, payment_status AS paymentStatus,
                    subtotal, shipping_amount AS shipping, total, currency, test_payment AS testPayment,
                    fx_usd_per_eur AS usdPerEur, DATE_FORMAT(fx_rate_date, '%Y-%m-%d') AS fxRateDate,
                    fx_rate_source AS fxRateSource,
                    DATE_FORMAT(shipping_tariff_reviewed_at, '%Y-%m-%d') AS shippingTariffReviewedAt,
                    DATE_FORMAT(shipping_tariff_valid_through, '%Y-%m-%d') AS shippingTariffValidThrough,
                    DATE_FORMAT(reservation_expires_at, '%Y-%m-%dT%H:%i:%sZ') AS reservationExpiresAt,
                    DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt
             FROM orders
             WHERE user_id = :userId AND idempotency_key = :idempotencyKey
             LIMIT 1"
        );
        $statement->execute([':userId' => $userId, ':idempotencyKey' => $idempotencyKey]);
        $order = $statement->fetch();
        return $order ?: null;
    }

    private function serializeOrder(array $order): array
    {
        unset($order['requestHash']);
        $withdrawal = $order['withdrawalRequest'] ?? null;
        $withdrawal = is_string($withdrawal) ? json_decode($withdrawal, true) : null;
        unset($order['withdrawalRequest']);
        return [
            ...$order,
            'subtotal' => (float) $order['subtotal'],
            'shipping' => (float) $order['shipping'],
            'total' => (float) $order['total'],
            'usdPerEur' => isset($order['usdPerEur']) ? (float) $order['usdPerEur'] : null,
            'fxRateDate' => $order['fxRateDate'] ?? null,
            'fxRateSource' => $order['fxRateSource'] ?? null,
            'shippingTariffReviewedAt' => $order['shippingTariffReviewedAt'] ?? null,
            'shippingTariffValidThrough' => $order['shippingTariffValidThrough'] ?? null,
            'testPayment' => (bool) ($order['testPayment'] ?? false),
            'refundedTotalCents' => (int) ($order['refundedTotalCents'] ?? 0),
            'reservationExpiresAt' => $order['reservationExpiresAt'] ?: null,
            'withdrawalRequestId' => isset($withdrawal['id']) ? (int) $withdrawal['id'] : null,
            'withdrawalRequestNumber' => $withdrawal['number'] ?? null,
            'withdrawalStatus' => $withdrawal['status'] ?? null,
            'withdrawalType' => $withdrawal['type'] ?? null,
        ];
    }

    private function assertSameIdempotentRequest(array $order, string $requestHash): void
    {
        $storedHash = $order['requestHash'] ?? null;
        if (is_string($storedHash) && $storedHash !== '' && !hash_equals($storedHash, $requestHash)) {
            throw new HttpException(
                'Esta tentativa já está vinculada a um pedido com outros dados. Retome ou encerre esse pedido antes de alterar a compra.',
                409,
                ['orderNumber' => (string) ($order['number'] ?? '')],
                'idempotency_conflict'
            );
        }
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function cents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new RuntimeException('Preço inválido armazenado no catálogo.');
        }
        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function convertUsdCents(int $usdCents, string $currency, float $eurPerUsd): int
    {
        return $currency === 'EUR'
            ? (int) round($usdCents * $eurPerUsd)
            : $usdCents;
    }

    private function convertEurCents(int $eurCents, string $currency, float $usdPerEur): int
    {
        return $currency === 'USD'
            ? (int) round($eurCents * $usdPerEur)
            : $eurCents;
    }
}
