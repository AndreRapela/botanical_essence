<?php

declare(strict_types=1);

$source = dirname(__DIR__) . '/src';
require_once $source . '/Http.php';
require_once $source . '/Validation.php';
require_once $source . '/Security.php';
require_once $source . '/CartItems.php';
require_once $source . '/ShippingTariffCalculator.php';
require_once $source . '/PaymentPolicy.php';
require_once $source . '/RuntimeConfiguration.php';
require_once $source . '/PaymentWorkerHeartbeat.php';
require_once $source . '/ExchangeRateService.php';
require_once $source . '/StripeGateway.php';
require_once $source . '/PaymentOrderLock.php';
require_once $source . '/PaymentNotificationPolicy.php';
require_once $source . '/AdminPaymentNotificationService.php';
require_once $source . '/Mailer.php';
require_once $source . '/AdminService.php';
require_once $source . '/AuthService.php';
require_once $source . '/AdminNotificationService.php';
require_once $source . '/MarketplaceService.php';
require_once $source . '/MessageService.php';
require_once $source . '/WithdrawalService.php';
require_once $source . '/ReviewService.php';
require_once $source . '/InventoryService.php';
require_once $source . '/ShippingService.php';
require_once $source . '/OrderService.php';
require_once $source . '/CheckoutReconciliationService.php';
require_once $source . '/PaymentOperationsService.php';
require_once $source . '/DisputeBackfillService.php';
require_once $source . '/DisputeBackfillSchema.php';
require_once $source . '/UnattachedCheckoutResolver.php';
require_once $source . '/PaymentWebhookActions.php';
require_once $source . '/RefundAdminResultActions.php';
require_once $source . '/RefundAuditActions.php';
require_once $source . '/RefundAdminActions.php';
require_once $source . '/RefundStatePolicy.php';
require_once $source . '/WithdrawalPolicy.php';
require_once $source . '/PartialRefundFailureActions.php';
require_once $source . '/PartialRefundActions.php';

final class FailingPaymentNotificationMailer implements MailTransport
{
    public function send(string $recipient, string $subject, string $text): void
    {
        throw new RuntimeException('SMTP password=secret-value rejected');
    }
}

final class PartialRefundActionsTestHarness
{
    use PartialRefundActions;

    public function __construct(private readonly PDO $pdo) {}

    public function apply(array $attempt, array $refund): void
    {
        $this->savePartialRefundProviderState($attempt, $refund, null);
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

}

final class FullRefundWebhookTestHarness
{
    use PaymentWebhookActions;
    use RefundAdminActions;
    use PartialRefundActions;

    public function __construct(private readonly PDO $pdo) {}

    public function apply(array $refund): void
    {
        $this->applyRefundEvent($refund);
    }

    public function startFullAttempt(array $order, int $adminId): array
    {
        return $this->startAdminRefundAttempt($order, $adminId);
    }

    public function resolveFullAttempt(array $order, int $adminId, string $resolution, string $reason): array
    {
        return $this->resolveAdminRefund($order, $adminId, $resolution, $reason);
    }

    public function performAdminRefundAttempt(array $order, string $paymentIntentId, int $attempt, int $adminId, ?int $withdrawalRequestId = null): array
    {
        return ['attempt' => $attempt, 'paymentIntentId' => $paymentIntentId, 'message' => 'Stripe call intentionally stubbed in this test.'];
    }

    private function orderForAdmin(string $number): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM orders WHERE order_number = :number LIMIT 1');
        $query->execute([':number' => $number]);
        $order = $query->fetch(PDO::FETCH_ASSOC);
        return $order ?: null;
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function restoreStock(int $orderId): void
    {
        $items = $this->pdo->prepare('SELECT product_id, SUM(quantity) AS quantity FROM order_items WHERE order_id = :orderId GROUP BY product_id');
        $items->execute([':orderId' => $orderId]);
        $restore = $this->pdo->prepare('UPDATE products SET stock = stock + :quantity WHERE id = :productId');
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $restore->execute([':quantity' => (int) $item['quantity'], ':productId' => (int) $item['product_id']]);
        }
    }

    protected function queueFullRefundConfirmation(int $orderId, string $reference): void
    {
        $queue = $this->pdo->prepare("INSERT IGNORE INTO payment_notification_outbox (order_id, notification_type, reference_id) VALUES (:orderId, 'full_refund_confirmation', :reference)");
        $queue->execute([':orderId' => $orderId, ':reference' => $reference]);
    }
}

final class PaymentWebhookRecoveryTestStub
{
    public function __construct(private readonly PDO $pdo) {}

    public function orderForCheckoutSession(array $session, string $sessionId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM orders WHERE payment_session_id = :sessionId LIMIT 1');
        $query->execute([':sessionId' => $sessionId]);
        $order = $query->fetch(PDO::FETCH_ASSOC);
        return $order ?: null;
    }

    public function refundCancelledOrderPayment(array $order, array $session, string $sessionId): void
    {
        throw new RuntimeException('Unexpected cancelled-order refund in confirmation test.');
    }
}

final class PaymentWebhookActionsTestHarness
{
    use PaymentWebhookActions;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PaymentWebhookRecoveryTestStub $latePaymentRecovery
    ) {}

    public function confirm(array $session): void
    {
        $this->applyCheckoutEvent($session, true);
    }

    private function orderForAdmin(string $number): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM orders WHERE order_number = :number LIMIT 1');
        $query->execute([':number' => $number]);
        $order = $query->fetch(PDO::FETCH_ASSOC);
        return $order ?: null;
    }

    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    protected function queuePaymentConfirmation(int $orderId): void
    {
        $queue = $this->pdo->prepare(
            "INSERT OR IGNORE INTO payment_notification_outbox (order_id, notification_type)
             VALUES (:orderId, 'payment_confirmation')"
        );
        $queue->execute([':orderId' => $orderId]);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : sprintf(
            'Expected %s, got %s.',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrueValue(bool $condition, string $message = 'Expected condition to be true.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertHttpException(callable $operation, int $status, ?string $detail = null): void
{
    try {
        $operation();
    } catch (HttpException $exception) {
        assertSameValue($status, $exception->status, sprintf('Unexpected HTTP status: expected %d, got %d.', $status, $exception->status));
        if ($detail !== null) {
            assertTrueValue(array_key_exists($detail, $exception->details), "Missing error detail: {$detail}.");
        }
        return;
    }
    throw new RuntimeException('Expected an HttpException.');
}

function withEnvironment(array $values, callable $operation): void
{
    $previous = [];
    foreach ($values as $name => $value) {
        $previous[$name] = getenv($name);
        $value === null ? putenv($name) : putenv($name . '=' . $value);
    }
    try {
        $operation();
    } finally {
        foreach ($previous as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
    }
}

function integrationDatabaseIsExplicitlyDisposable(?string $databaseName, ?string $allowDisposableTests): bool
{
    return strtolower(trim((string) $allowDisposableTests)) === 'true'
        && is_string($databaseName)
        && preg_match('/(?:^|_)(?:ci|test|e2e)(?:_|$)/iD', $databaseName) === 1;
}

function mysqlIntegrationTestsCanMutateDatabase(array $connection): bool
{
    $databaseName = getenv('DB_DATABASE');
    $allowDisposableTests = getenv('ALLOW_DISPOSABLE_DB_TESTS');
    return in_array('mysql', PDO::getAvailableDrivers(), true)
        && !in_array(false, $connection, true)
        && !in_array('', $connection, true)
        && integrationDatabaseIsExplicitlyDisposable(
            $databaseName === false ? null : $databaseName,
            $allowDisposableTests === false ? null : $allowDisposableTests
        );
}

$calculator = new ShippingTariffCalculator();
$tests = [
    'request body limits stop stream reads at one byte over the configured maximum' => static function (): void {
        $withinLimit = fopen('php://memory', 'r+');
        fwrite($withinLimit, '1234');
        rewind($withinLimit);
        assertSameValue('1234', Request::readBoundedBody($withinLimit, 4));
        assertSameValue(4, ftell($withinLimit));
        fclose($withinLimit);

        $overLimit = fopen('php://memory', 'r+');
        fwrite($overLimit, str_repeat('x', 100_000));
        rewind($overLimit);
        try {
            Request::readBoundedBody($overLimit, 4, 'Evento de pagamento muito grande.');
            throw new RuntimeException('An oversized streamed request must be rejected.');
        } catch (HttpException $exception) {
            assertSameValue(413, $exception->status);
            assertSameValue('Evento de pagamento muito grande.', $exception->getMessage());
        }
        assertSameValue(5, ftell($overLimit), 'The reader must stop after the first byte that proves the limit was exceeded.');
        fclose($overLimit);
    },
    'MySQL integration tests require explicit permission and a disposable database name' => static function (): void {
        assertSameValue(true, integrationDatabaseIsExplicitlyDisposable('botanical_essence_ci', 'true'));
        assertSameValue(true, integrationDatabaseIsExplicitlyDisposable('botanical_e2e', 'true'));
        assertSameValue(true, integrationDatabaseIsExplicitlyDisposable('botanical_test_1', 'true'));
        assertSameValue(false, integrationDatabaseIsExplicitlyDisposable('botanical_essence', 'true'));
        assertSameValue(false, integrationDatabaseIsExplicitlyDisposable('botanical_essence_ci', 'false'));
        assertSameValue(false, integrationDatabaseIsExplicitlyDisposable('botanical_contest', 'true'));
    },
    'expired rate-limit cleanup deletes only a bounded oldest batch' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  Rate-limit cleanup integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("CREATE TEMPORARY TABLE rate_limits (
            action_name VARCHAR(60) NOT NULL, identifier_hash CHAR(64) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 1, window_started_at TIMESTAMP NOT NULL,
            PRIMARY KEY (action_name, identifier_hash), INDEX idx_rate_window (window_started_at)
        ) ENGINE=InnoDB");
        $insert = $pdo->prepare(
            "INSERT INTO rate_limits (action_name, identifier_hash, window_started_at)
             VALUES ('integration', :identifier, CURRENT_TIMESTAMP - INTERVAL 3 DAY)"
        );
        for ($index = 1; $index <= 700; $index++) {
            $insert->execute([':identifier' => hash('sha256', (string) $index)]);
        }
        $pdo->exec(
            "INSERT INTO rate_limits (action_name, identifier_hash, window_started_at)
             VALUES ('integration', '" . hash('sha256', 'current') . "', CURRENT_TIMESTAMP)"
        );

        $limiter = new RateLimiter($pdo);
        assertSameValue(500, $limiter->cleanupExpired());
        assertSameValue(200, (int) $pdo->query('SELECT COUNT(*) FROM rate_limits WHERE window_started_at < CURRENT_TIMESTAMP - INTERVAL 2 DAY')->fetchColumn());
        assertSameValue(200, $limiter->cleanupExpired());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn());
        assertSameValue(0, $limiter->cleanupExpired());
    },
    'seller writes cannot republish products after approval is revoked' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  Seller approval integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("CREATE TEMPORARY TABLE users (
            id BIGINT UNSIGNED PRIMARY KEY, role VARCHAR(16) NOT NULL,
            status VARCHAR(16) NOT NULL, email_verified_at DATETIME NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE sellers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
            display_name VARCHAR(120) NOT NULL, slug VARCHAR(160) NOT NULL, bio VARCHAR(1000) NULL,
            city VARCHAR(100) NOT NULL, state VARCHAR(100) NOT NULL, country VARCHAR(100) NOT NULL,
            rating DECIMAL(3,2) NOT NULL DEFAULT 0, verified BOOLEAN NOT NULL DEFAULT FALSE
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE products (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, seller_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(140) NOT NULL, slug VARCHAR(160) NOT NULL, description TEXT NOT NULL,
            origin VARCHAR(120) NULL, dimensions VARCHAR(120) NULL, materials VARCHAR(200) NULL,
            care_instructions TEXT NULL, category VARCHAR(100) NOT NULL, price DECIMAL(10,2) NOT NULL,
            shipping_weight_grams INT UNSIGNED NOT NULL, stock INT UNSIGNED NOT NULL,
            image_position TINYINT UNSIGNED NOT NULL, badge VARCHAR(40) NULL,
            is_marketplace BOOLEAN NOT NULL, featured BOOLEAN NOT NULL, status VARCHAR(16) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE admin_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_user_id BIGINT UNSIGNED NULL,
            action_name VARCHAR(100) NOT NULL, target_type VARCHAR(32) NOT NULL, target_id BIGINT UNSIGNED NOT NULL,
            previous_value TEXT NULL, new_value TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE rate_limits (
            action_name VARCHAR(80) NOT NULL, identifier_hash CHAR(64) NOT NULL, attempts INT NOT NULL,
            window_started_at DATETIME NOT NULL, PRIMARY KEY (action_name, identifier_hash)
        ) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO users (id, role, status, email_verified_at)
            VALUES (1, 'admin', 'active', CURRENT_TIMESTAMP), (2, 'customer', 'active', CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO sellers (id, user_id, display_name, slug, city, state, country, verified)
            VALUES (1, 2, 'Fern House', 'fern-house', 'Brussels', 'Brussels-Capital', 'Belgium', TRUE)");

        $service = new MarketplaceService($pdo, new RateLimiter($pdo), new AdminNotificationService($pdo, new Mailer()));
        $seller = ['id' => 2];
        $activeProduct = [
            'name' => 'Boston fern', 'description' => 'Healthy established fern in a nursery pot.',
            'origin' => 'Belgium', 'dimensions' => '25 cm', 'materials' => '', 'careInstructions' => 'Keep evenly moist.',
            'category' => 'plants', 'price' => 12.50, 'shippingWeightGrams' => 750,
            'stock' => 5, 'sprite' => 1, 'badge' => '', 'status' => 'active',
        ];
        $created = $service->saveProduct($activeProduct, $seller)['product'];
        assertSameValue('active', $created['status']);

        $service->setSellerVerification(['sellerId' => 1, 'verified' => false], ['id' => 1]);
        assertSameValue('draft', $pdo->query('SELECT status FROM products WHERE id = ' . (int) $created['id'])->fetchColumn());

        // Simulate a stale seller tab/direct request that still submits `active`.
        $updated = $service->saveProduct([...$activeProduct, 'id' => $created['id']], $seller)['product'];
        assertSameValue('draft', $updated['status']);

        $second = $service->saveProduct([...$activeProduct, 'name' => 'New fern listing'], $seller)['product'];
        assertSameValue('draft', $second['status']);

        $service->setSellerVerification(['sellerId' => 1, 'verified' => true], ['id' => 1]);
        $statuses = $pdo->query('SELECT status FROM products ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        assertSameValue(['draft', 'draft'], $statuses, 'Approval must never automatically republish listings saved during a revoked period.');
    },
    'payment notification delivery uses bounded backoff and a recoverable dead-letter state' => static function (): void {
        assertSameValue('SMTP password=[redacted] failed', PaymentNotificationPolicy::safeDiagnostic("SMTP password=secret-value\nfailed"));
        assertSameValue('Authorization: [redacted]', PaymentNotificationPolicy::safeDiagnostic('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature'));
        assertSameValue('Authorization: [redacted]', PaymentNotificationPolicy::safeDiagnostic('Authorization: Basic dXNlcjpwYXNz'));
        assertSameValue('Config MAIL_PASSWORD=[redacted] STRIPE_SECRET_KEY=[redacted]', PaymentNotificationPolicy::safeDiagnostic('Config MAIL_PASSWORD=super-secret STRIPE_SECRET_KEY=sk_live_123456789'));
        assertSameValue('DB_PASSWORD=[redacted] rejected', PaymentNotificationPolicy::safeDiagnostic('DB_PASSWORD=comma,secret;part rejected'));
        assertSameValue('Relay smtp://service:[redacted]@mail.example.test refused the connection', PaymentNotificationPolicy::safeDiagnostic('Relay smtp://service:super-secret@mail.example.test refused the connection'));
        assertSameValue('Stripe [redacted] webhook [redacted]', PaymentNotificationPolicy::safeDiagnostic('Stripe sk_live_123456789 webhook whsec_123456789'));
        assertSameValue(
            ['status' => 'pending', 'consecutiveFailures' => 1, 'delaySeconds' => 120],
            PaymentNotificationPolicy::afterDeliveryFailure(0)
        );
        assertSameValue(61_440, PaymentNotificationPolicy::afterDeliveryFailure(10)['delaySeconds']);
        assertSameValue(
            ['status' => 'failed', 'consecutiveFailures' => 12, 'delaySeconds' => 0],
            PaymentNotificationPolicy::afterDeliveryFailure(11)
        );
    },
    'payment worker heartbeat only advances after successful batches and resets on startup' => static function (): void {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'botanical-payment-worker-' . bin2hex(random_bytes(8));
        file_put_contents($path, 'stale');
        $heartbeat = new PaymentWorkerHeartbeat($path);
        try {
            $heartbeat->resetAtStartup();
            assertSameValue(false, file_exists($path));
            assertSameValue(false, $heartbeat->recordBatchResult(false, 100));
            assertSameValue(false, file_exists($path));
            assertSameValue(true, $heartbeat->recordBatchResult(true, 100));
            assertSameValue('100', file_get_contents($path));
            assertSameValue(false, $heartbeat->recordBatchResult(false, 200));
            assertSameValue('100', file_get_contents($path));
            assertSameValue(true, $heartbeat->recordBatchResult(true, 200));
            assertSameValue('200', file_get_contents($path));
        } finally {
            if (file_exists($path)) unlink($path);
        }
    },
    'scheduled inventory cleanup releases expired stock in bounded batches and is idempotent' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  Inventory cleanup integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("CREATE TEMPORARY TABLE orders (
            id BIGINT UNSIGNED PRIMARY KEY, status VARCHAR(32) NOT NULL, payment_status VARCHAR(32) NOT NULL,
            reservation_expires_at DATETIME NULL, payment_provider VARCHAR(24) NULL,
            payment_session_id VARCHAR(128) NULL
        ) ENGINE=InnoDB");
        $pdo->exec('CREATE TEMPORARY TABLE products (id BIGINT UNSIGNED PRIMARY KEY, stock INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TEMPORARY TABLE order_items (order_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO products (id, stock) VALUES (1, 0)');
        $insertOrder = $pdo->prepare(
            "INSERT INTO orders (id, status, payment_status, reservation_expires_at, payment_provider, payment_session_id)
             VALUES (:id, 'awaiting_payment', 'pending', :expiresAt, :provider, :sessionId)"
        );
        $insertItem = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity) VALUES (:orderId, 1, 1)');
        for ($id = 1; $id <= 205; $id++) {
            $insertOrder->execute([
                ':id' => $id, ':expiresAt' => gmdate('Y-m-d H:i:s', time() - 60),
                ':provider' => 'local_test', ':sessionId' => null,
            ]);
            $insertItem->execute([':orderId' => $id]);
        }
        $insertOrder->execute([
            ':id' => 1000, ':expiresAt' => gmdate('Y-m-d H:i:s', time() + 3600),
            ':provider' => 'local_test', ':sessionId' => null,
        ]);
        foreach ([1001 => 'cs_test_async_pending', 1002 => null] as $id => $sessionId) {
            $insertOrder->execute([
                ':id' => $id, ':expiresAt' => gmdate('Y-m-d H:i:s', time() - 60),
                ':provider' => 'stripe', ':sessionId' => $sessionId,
            ]);
            $insertItem->execute([':orderId' => $id]);
        }
        $inventory = new InventoryService($pdo);

        $releasedBatches = [];
        $releasedTotal = 0;
        for ($attempt = 0; $attempt < 30 && $releasedTotal < 205; $attempt++) {
            $released = $inventory->releaseExpiredReservations();
            if ($released > 0) {
                $releasedBatches[] = $released;
                $releasedTotal += $released;
            } else {
                usleep(50_000);
            }
        }
        assertSameValue([100, 100, 5], $releasedBatches);
        assertSameValue(205, $releasedTotal);
        assertSameValue(0, $inventory->releaseExpiredReservations());
        assertSameValue(205, (int) $pdo->query('SELECT stock FROM products WHERE id = 1')->fetchColumn());
        assertSameValue(205, (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'cancelled' AND payment_status = 'failed'")->fetchColumn());
        assertSameValue('awaiting_payment', $pdo->query('SELECT status FROM orders WHERE id = 1000')->fetchColumn());
        assertSameValue('awaiting_payment', $pdo->query('SELECT status FROM orders WHERE id = 1001')->fetchColumn());
        assertSameValue('awaiting_payment', $pdo->query('SELECT status FROM orders WHERE id = 1002')->fetchColumn());
    },
    'manual checkout reconciliation is delayed, audited and restores inventory exactly once' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  Manual checkout reconciliation integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("CREATE TEMPORARY TABLE orders (
            id BIGINT UNSIGNED PRIMARY KEY, order_number VARCHAR(40) NOT NULL, status VARCHAR(32) NOT NULL,
            payment_status VARCHAR(32) NOT NULL, payment_provider VARCHAR(24) NOT NULL, payment_session_id VARCHAR(128) NULL,
            payment_session_attempted_at DATETIME NULL, reservation_expires_at DATETIME NULL, created_at DATETIME NOT NULL
        ) ENGINE=InnoDB");
        $pdo->exec('CREATE TEMPORARY TABLE products (id BIGINT UNSIGNED PRIMARY KEY, stock INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TEMPORARY TABLE order_items (order_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $pdo->exec("CREATE TEMPORARY TABLE admin_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_user_id BIGINT UNSIGNED NOT NULL,
            action_name VARCHAR(100) NOT NULL, target_type VARCHAR(60) NOT NULL, target_id BIGINT UNSIGNED NOT NULL,
            previous_value VARCHAR(500) NULL, new_value VARCHAR(500) NULL
        ) ENGINE=InnoDB");
        $pdo->exec('INSERT INTO products (id, stock) VALUES (1, 0)');
        $pdo->exec("INSERT INTO orders
            (id, order_number, status, payment_status, payment_provider, payment_session_attempted_at, reservation_expires_at, created_at)
            VALUES (1, 'BE-OLD-STRIPE-1', 'awaiting_payment', 'pending', 'stripe',
                    CURRENT_TIMESTAMP - INTERVAL 24 HOUR, CURRENT_TIMESTAMP - INTERVAL 23 HOUR, CURRENT_TIMESTAMP - INTERVAL 24 HOUR)");
        $pdo->exec('INSERT INTO order_items (order_id, product_id, quantity) VALUES (1, 1, 2)');
        $resolver = new UnattachedCheckoutResolver($pdo);
        $order = $pdo->query('SELECT * FROM orders WHERE id = 1')->fetch();
        assertTrueValue(is_array($order));

        $recent = [...$order, 'payment_session_attempted_at' => gmdate('Y-m-d H:i:s')];
        assertHttpException(static fn () => $resolver->resolve($recent, ['id' => 4], 'Verified no Stripe payment in dashboard.'), 409);
        assertSameValue(0, (int) $pdo->query('SELECT stock FROM products WHERE id = 1')->fetchColumn());

        $result = $resolver->resolve($order, ['id' => 4], 'Checked the order reference in Stripe; no session or payment exists.');
        assertTrueValue(str_contains($result['message'], 'estoque foi liberado'));
        $resolved = $pdo->query('SELECT status, payment_status, reservation_expires_at FROM orders WHERE id = 1')->fetch();
        assertSameValue('cancelled', $resolved['status']);
        assertSameValue('failed', $resolved['payment_status']);
        assertSameValue(null, $resolved['reservation_expires_at']);
        assertSameValue(2, (int) $pdo->query('SELECT stock FROM products WHERE id = 1')->fetchColumn());
        $audit = $pdo->query('SELECT action_name, new_value FROM admin_audit_log WHERE target_id = 1')->fetch();
        assertSameValue('order.checkout_attempt_resolved', $audit['action_name']);
        assertTrueValue(str_contains($audit['new_value'], 'no session or payment exists'));
        assertHttpException(static fn () => $resolver->resolve($order, ['id' => 4], 'Checked the order reference in Stripe; no session or payment exists.'), 409);
        assertSameValue(2, (int) $pdo->query('SELECT stock FROM products WHERE id = 1')->fetchColumn());
    },
    'customer order history pagination includes every historical order in stable order' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  Customer order history integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("CREATE TEMPORARY TABLE orders (
            id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, order_number VARCHAR(40) NOT NULL,
            status VARCHAR(32) NOT NULL, payment_status VARCHAR(32) NOT NULL, subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
            shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0, total DECIMAL(10,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'EUR', test_payment BOOLEAN NOT NULL DEFAULT FALSE,
            fx_usd_per_eur DECIMAL(12,8) NULL, fx_rate_date DATE NULL, fx_rate_source VARCHAR(80) NULL,
            shipping_tariff_reviewed_at DATE NULL, shipping_tariff_valid_through DATE NULL,
            payment_refunded_cents BIGINT UNSIGNED NOT NULL DEFAULT 0, reservation_expires_at DATETIME NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE withdrawal_requests (
            id BIGINT UNSIGNED PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, request_number VARCHAR(40) NOT NULL,
            status VARCHAR(32) NOT NULL, request_type VARCHAR(24) NOT NULL
        ) ENGINE=InnoDB");
        $insert = $pdo->prepare(
            'INSERT INTO orders (id, user_id, order_number, status, payment_status, created_at)
             VALUES (:id, :userId, :number, \'confirmed\', \'paid\', :createdAt)'
        );
        for ($id = 1; $id <= 55; $id++) {
            $insert->execute([
                ':id' => $id,
                ':userId' => 7,
                ':number' => sprintf('HISTORY-%03d', $id),
                ':createdAt' => gmdate('Y-m-d H:i:s', time() - $id),
            ]);
        }
        $pdo->exec("INSERT INTO withdrawal_requests (id, order_id, request_number, status, request_type)
                    VALUES (1, 1, 'RET-TEST-001', 'submitted', 'withdrawal')");
        $insert->execute([':id' => 56, ':userId' => 8, ':number' => 'OTHER-CUSTOMER', ':createdAt' => gmdate('Y-m-d H:i:s')]);
        $limiter = new RateLimiter($pdo);
        $service = new OrderService(
            $pdo,
            $limiter,
            new Mailer(),
            new InventoryService($pdo),
            new ShippingService($pdo, $limiter),
            new ExchangeRateService($pdo)
        );

        $firstPage = $service->listForUser(['id' => 7]);
        assertSameValue(55, $firstPage['total']);
        assertSameValue(50, count($firstPage['data']));
        assertSameValue('HISTORY-001', $firstPage['data'][0]['number']);
        assertSameValue(1, $firstPage['data'][0]['withdrawalRequestId']);
        assertSameValue('RET-TEST-001', $firstPage['data'][0]['withdrawalRequestNumber']);
        assertSameValue('submitted', $firstPage['data'][0]['withdrawalStatus']);
        assertSameValue('HISTORY-050', $firstPage['data'][49]['number']);
        assertSameValue(true, $firstPage['hasMore']);

        $lastPage = $service->listForUser(['id' => 7], ['page' => '2']);
        assertSameValue(5, count($lastPage['data']));
        assertSameValue('HISTORY-051', $lastPage['data'][0]['number']);
        assertSameValue('HISTORY-055', $lastPage['data'][4]['number']);
        assertSameValue(false, $lastPage['hasMore']);
        assertHttpException(static fn () => $service->listForUser(['id' => 7], ['page' => '0']), 422);
    },
    'admin email changes revoke verification and create one replaceable confirmation token' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  Admin account verification integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE TEMPORARY TABLE users (
            id BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL,
            role VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, is_demo BOOLEAN NOT NULL DEFAULT FALSE,
            email_verified_at DATETIME NULL, auth_version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE email_verification_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE password_reset_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE sellers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
            display_name VARCHAR(120) NOT NULL, bio VARCHAR(1000) NULL, city VARCHAR(100) NOT NULL,
            state VARCHAR(100) NOT NULL, country VARCHAR(100) NOT NULL, verified BOOLEAN NOT NULL DEFAULT FALSE
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE products (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, seller_id BIGINT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE admin_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_user_id BIGINT UNSIGNED NULL,
            action_name VARCHAR(100) NOT NULL, target_type VARCHAR(32) NOT NULL, target_id BIGINT UNSIGNED NOT NULL,
            previous_value TEXT NULL, new_value TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE rate_limits (
            action_name VARCHAR(80) NOT NULL, identifier_hash CHAR(64) NOT NULL, attempts INT NOT NULL,
            window_started_at DATETIME NOT NULL, PRIMARY KEY (action_name, identifier_hash)
        ) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO users (id, name, email, role, status, email_verified_at)
            VALUES (1, 'Admin Test', 'admin@example.test', 'admin', 'active', CURRENT_TIMESTAMP),
                   (2, 'Customer Test', 'customer@example.test', 'customer', 'active', CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO sellers (id, user_id, display_name, bio, city, state, country)
            VALUES (1, 2, 'Old Store', 'Old bio', 'Brussels', 'Brussels-Capital', 'Belgium')");

        withEnvironment(['MAIL_TRANSPORT' => null], static function () use ($pdo): void {
            $sentVerificationEmails = [];
            $service = new AdminService(
                $pdo,
                new RateLimiter($pdo),
                null,
                static function (string $email, string $name, string $url) use (&$sentVerificationEmails): void {
                    $sentVerificationEmails[] = compact('email', 'name', 'url');
                }
            );
            $input = [
                'id' => 2,
                'name' => 'Customer Test',
                'email' => 'new-customer@example.test',
                'role' => 'customer',
                'status' => 'active',
                'emailVerified' => true,
            ];
            $result = $service->updateUser($input, ['id' => 1, 'authVersion' => 1]);
            assertSameValue('pending', $result['user']['status']);
            assertSameValue(false, $result['user']['emailVerified']);
            assertSameValue(null, $result['user']['emailVerifiedAt']);
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM email_verification_tokens WHERE user_id = 2')->fetchColumn());
            $firstHash = (string) $pdo->query('SELECT token_hash FROM email_verification_tokens WHERE user_id = 2')->fetchColumn();

            $input['status'] = 'pending';
            $input['emailVerified'] = false;
            $service->updateUser($input, ['id' => 1, 'authVersion' => 1]);
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM email_verification_tokens WHERE user_id = 2')->fetchColumn());
            $secondHash = (string) $pdo->query('SELECT token_hash FROM email_verification_tokens WHERE user_id = 2')->fetchColumn();
            assertTrueValue($firstHash !== $secondHash, 'An administrator retry must rotate rather than duplicate the confirmation token.');
            assertSameValue(2, (int) $pdo->query("SELECT COUNT(*) FROM admin_audit_log WHERE action_name = 'user.updated'")->fetchColumn());
            assertSameValue(2, count($sentVerificationEmails));
            assertTrueValue(str_contains($sentVerificationEmails[0]['url'], '/verify-email#token='));
            $firstAudit = $pdo->query("SELECT previous_value, new_value FROM admin_audit_log WHERE action_name = 'user.updated' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            assertSameValue('customer@example.test', json_decode($firstAudit['previous_value'], true, 512, JSON_THROW_ON_ERROR)['email']);
            assertSameValue('new-customer@example.test', json_decode($firstAudit['new_value'], true, 512, JSON_THROW_ON_ERROR)['email']);
            $auditPage = $service->auditLog(['page' => '1', 'limit' => '1']);
            assertSameValue(1, count($auditPage['data']));
            assertSameValue(2, $auditPage['total']);
            assertSameValue(true, $auditPage['hasMore']);

            $service->updateStore([
                'id' => 1, 'displayName' => 'New Store', 'bio' => 'Updated bio',
                'city' => 'Antwerp', 'region' => 'Antwerp', 'country' => 'Belgium',
            ], ['id' => 1]);
            $storeAudit = $pdo->query("SELECT previous_value, new_value FROM admin_audit_log WHERE action_name = 'seller.updated'")->fetch(PDO::FETCH_ASSOC);
            assertSameValue('Old Store', json_decode($storeAudit['previous_value'], true, 512, JSON_THROW_ON_ERROR)['displayName']);
            assertSameValue('New Store', json_decode($storeAudit['new_value'], true, 512, JSON_THROW_ON_ERROR)['displayName']);
        });
    },
    'contact inbox paginates and audits status changes exactly once' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  Contact inbox integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE TEMPORARY TABLE contact_messages (
            id BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL,
            subject VARCHAR(20) NOT NULL, message TEXT NOT NULL, status VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE admin_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_user_id BIGINT UNSIGNED NULL,
            action_name VARCHAR(100) NOT NULL, target_type VARCHAR(32) NOT NULL, target_id BIGINT UNSIGNED NOT NULL,
            previous_value TEXT NULL, new_value TEXT NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE rate_limits (
            action_name VARCHAR(80) NOT NULL, identifier_hash CHAR(64) NOT NULL, attempts INT NOT NULL,
            window_started_at DATETIME NOT NULL, PRIMARY KEY (action_name, identifier_hash)
        ) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO contact_messages (id, name, email, subject, message, status)
            VALUES (1, 'Customer One', 'one@example.test', 'order', 'Please check my order.', 'new'),
                   (2, 'Customer Two', 'two@example.test', 'care', 'I need plant care advice.', 'new'),
                   (3, 'Customer Three', 'three@example.test', 'other', 'A longer customer message.', 'closed')");
        $service = new MessageService($pdo, new RateLimiter($pdo));
        $page = $service->adminContactMessages(['page' => '1', 'limit' => '1', 'status' => 'new'], ['id' => 9]);
        assertSameValue(1, count($page['data']));
        assertSameValue(2, $page['total']);
        assertSameValue(true, $page['hasMore']);

        $service->updateContactMessage(['messageId' => 1, 'status' => 'in_progress'], ['id' => 9]);
        $service->updateContactMessage(['messageId' => 1, 'status' => 'in_progress'], ['id' => 9]);
        assertSameValue('in_progress', $pdo->query('SELECT status FROM contact_messages WHERE id = 1')->fetchColumn());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
        assertHttpException(
            static fn () => $service->updateContactMessage(['messageId' => 1, 'status' => 'invalid'], ['id' => 9]),
            422
        );
    },
    'admin consumer request and dispute queues paginate every row with urgent cases first' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  Admin queue pagination integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE TEMPORARY TABLE orders (
            id BIGINT UNSIGNED PRIMARY KEY, order_number VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL,
            payment_status VARCHAR(32) NOT NULL, payment_provider VARCHAR(24) NULL, payment_refund_attempt INT NOT NULL DEFAULT 0,
            payment_refund_outcome VARCHAR(32) NULL, payment_refund_error_code VARCHAR(100) NULL,
            payment_refund_error_message VARCHAR(500) NULL, total DECIMAL(10,2) NOT NULL, currency CHAR(3) NOT NULL,
            customer_name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE order_items (
            id BIGINT UNSIGNED PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
            product_name VARCHAR(140) NOT NULL, quantity SMALLINT NOT NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE withdrawal_requests (
            id BIGINT UNSIGNED PRIMARY KEY, request_number VARCHAR(32) NOT NULL, order_id BIGINT UNSIGNED NOT NULL,
            request_type VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL, customer_message VARCHAR(2000) NULL, declared_delivery_date DATE NULL,
            admin_note VARCHAR(2000) NULL, created_at DATETIME NOT NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE payment_disputes (
            stripe_dispute_id VARCHAR(128) PRIMARY KEY, order_id BIGINT UNSIGNED NULL,
            payment_intent_id VARCHAR(128) NULL, charge_id VARCHAR(128) NULL, status VARCHAR(60) NOT NULL,
            reason VARCHAR(100) NULL, amount_cents BIGINT NOT NULL, currency CHAR(3) NOT NULL,
            evidence_due_at DATETIME NULL, livemode BOOLEAN NOT NULL DEFAULT FALSE,
            provider_created_at DATETIME NULL, last_sync_error VARCHAR(500) NULL, updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO orders (id, order_number, status, payment_status, total, currency, customer_name, email)
            VALUES (1, 'ORDER-1', 'delivered', 'paid', 12.00, 'eur', 'Customer 1', 'one@example.test'),
                   (2, 'ORDER-2', 'delivered', 'paid', 13.00, 'eur', 'Customer 2', 'two@example.test'),
                   (3, 'ORDER-3', 'delivered', 'paid', 14.00, 'eur', 'Customer 3', 'three@example.test')");
        $pdo->exec("INSERT INTO withdrawal_requests (id, request_number, order_id, request_type, status, created_at)
            VALUES (1, 'REQ-1', 1, 'withdrawal', 'refunded', '2026-01-01 00:00:00'),
                   (2, 'REQ-2', 2, 'problem', 'submitted', '2026-01-02 00:00:00'),
                   (3, 'REQ-3', 3, 'withdrawal', 'submitted', '2026-01-03 00:00:00')");
        $pdo->exec("INSERT INTO payment_disputes
            (stripe_dispute_id, order_id, status, amount_cents, currency, evidence_due_at, livemode, updated_at)
            VALUES ('dp-old', 1, 'closed', 1200, 'eur', NULL, FALSE, '2026-01-01 00:00:00'),
                   ('dp-later', 2, 'needs_response', 1300, 'eur', '2026-12-20 00:00:00', FALSE, '2026-01-02 00:00:00'),
                   ('dp-urgent', 3, 'needs_response', 1400, 'eur', '2026-12-10 00:00:00', FALSE, '2026-01-03 00:00:00')");

        $withdrawalService = new WithdrawalService($pdo, new RateLimiter($pdo), new Mailer());
        $withdrawals = $withdrawalService->adminList(['page' => '1', 'limit' => '1']);
        assertSameValue(1, count($withdrawals['data']));
        assertSameValue(3, $withdrawals['total']);
        assertSameValue('REQ-3', $withdrawals['data'][0]['requestNumber']);
        assertTrueValue($withdrawals['hasMore']);
        $lastWithdrawalPage = $withdrawalService->adminList(['page' => '3', 'limit' => '1']);
        assertSameValue('REQ-1', $lastWithdrawalPage['data'][0]['requestNumber']);
        assertSameValue(false, $lastWithdrawalPage['hasMore']);

        $operations = new PaymentOperationsService(
            $pdo,
            new StripeGateway(),
            new PaymentOrderLock($pdo),
            new Mailer(),
            static fn () => null,
            static fn () => null,
            static fn () => null
        );
        $disputes = $operations->adminDisputes(['page' => '1', 'limit' => '1']);
        assertSameValue(1, count($disputes['data']));
        assertSameValue(3, $disputes['total']);
        assertSameValue('dp-urgent', $disputes['data'][0]['id']);
        assertTrueValue($disputes['hasMore']);
        $lastDisputePage = $operations->adminDisputes(['page' => '3', 'limit' => '1']);
        assertSameValue('dp-old', $lastDisputePage['data'][0]['id']);
        assertSameValue(false, $lastDisputePage['hasMore']);
    },
    'admin can inspect failed payment emails and manually retry only eligible messages with an audit record' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  Payment notification recovery integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE TEMPORARY TABLE orders (
            id BIGINT UNSIGNED PRIMARY KEY, order_number VARCHAR(32) NOT NULL,
            customer_name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL,
            total DECIMAL(12,2) NOT NULL DEFAULT 1.00, currency CHAR(3) NOT NULL DEFAULT 'EUR',
            address_line VARCHAR(255) NULL, city VARCHAR(120) NULL, postal_code VARCHAR(32) NULL,
            region VARCHAR(120) NULL, country VARCHAR(120) NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE order_items (
            id BIGINT UNSIGNED PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
            product_name VARCHAR(180) NOT NULL, quantity SMALLINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE payment_notification_outbox (
            id BIGINT UNSIGNED PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
            notification_type VARCHAR(60) NOT NULL, reference_id VARCHAR(128) NOT NULL DEFAULT '',
            status VARCHAR(24) NOT NULL, attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, locked_until TIMESTAMP NULL,
            last_error VARCHAR(500) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at TIMESTAMP NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE admin_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_user_id BIGINT UNSIGNED NOT NULL,
            action_name VARCHAR(100) NOT NULL, target_type VARCHAR(60) NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL, previous_value TEXT NULL, new_value TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE rate_limits (
            action_name VARCHAR(60) NOT NULL, identifier_hash CHAR(64) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 1, window_started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (action_name, identifier_hash)
        ) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO orders (id, order_number, customer_name, email) VALUES
            (1, 'ORDER-FAILED', 'Failed Customer', 'failed@example.test'),
            (2, 'ORDER-RETRY', 'Retry Customer', 'retry@example.test'),
            (3, 'ORDER-QUEUED', 'Queued Customer', 'queued@example.test'),
            (4, 'ORDER-WORKING', 'Working Customer', 'working@example.test'),
            (5, 'ORDER-STALE', 'Stale Customer', 'stale@example.test')");
        $pdo->exec("INSERT INTO order_items (id, order_id, product_name, quantity) VALUES (1, 1, 'Monstera', 1)");
        $pdo->exec("INSERT INTO payment_notification_outbox
            (id, order_id, notification_type, status, attempts, consecutive_failures, last_error, locked_until) VALUES
            (1, 1, 'payment_confirmation', 'failed', 12, 12, 'SMTP password=secret-value rejected', NULL),
            (2, 2, 'refund_confirmation', 'pending', 2, 1, 'Temporary mail relay error', NULL),
            (3, 3, 'payment_confirmation', 'pending', 0, 0, NULL, NULL),
            (4, 4, 'payment_confirmation', 'processing', 4, 1, 'Previous provider timeout', CURRENT_TIMESTAMP + INTERVAL 5 MINUTE),
            (5, 5, 'payment_confirmation', 'processing', 3, 0, NULL, CURRENT_TIMESTAMP - INTERVAL 1 MINUTE)");

        $service = new AdminPaymentNotificationService($pdo, new RateLimiter($pdo));
        $page = $service->issues(['page' => '1', 'limit' => '2']);
        assertSameValue(4, $page['total']);
        assertSameValue(2, count($page['data']));
        assertSameValue('ORDER-FAILED', $page['data'][0]['orderNumber']);
        assertSameValue(true, $page['data'][0]['retryAvailable']);
        assertTrueValue(str_contains((string) $page['data'][0]['lastError'], '[redacted]'));
        assertSameValue(true, $page['hasMore']);
        $laterPage = $service->issues(['page' => '2', 'limit' => '2']);
        assertSameValue(2, count($laterPage['data']));

        $retry = $service->retry(['id' => 1, 'reason' => 'Corrected the SMTP relay credentials.'], ['id' => 9]);
        assertTrueValue(str_contains($retry['message'], 'colocada novamente'));
        $requeued = $pdo->query('SELECT status, attempts, consecutive_failures, last_error FROM payment_notification_outbox WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        assertSameValue('pending', $requeued['status']);
        assertSameValue(12, (int) $requeued['attempts']);
        assertSameValue(0, (int) $requeued['consecutive_failures']);
        assertTrueValue(str_contains((string) $requeued['last_error'], 'password=[redacted]'));
        assertSameValue(false, str_contains((string) $requeued['last_error'], 'secret-value'));
        $audit = $pdo->query('SELECT action_name, target_type, target_id, new_value FROM admin_audit_log')->fetch(PDO::FETCH_ASSOC);
        assertSameValue('payment_notification.manual_retry', $audit['action_name']);
        assertSameValue('payment_notification', $audit['target_type']);
        assertSameValue(1, (int) $audit['target_id']);
        assertTrueValue(str_contains($audit['new_value'], 'Corrected the SMTP relay credentials.'));

        $operations = new PaymentOperationsService(
            $pdo,
            new StripeGateway(),
            new PaymentOrderLock($pdo),
            new FailingPaymentNotificationMailer(),
            static fn (array $session, bool $paid): mixed => null,
            static fn (int $orderId, string $status): mixed => null,
            static fn (array $refund): mixed => null
        );
        assertSameValue(0, $operations->dispatchOutbox(1));
        $failedDelivery = $pdo->query('SELECT status, attempts, consecutive_failures, last_error FROM payment_notification_outbox WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        assertSameValue('pending', $failedDelivery['status']);
        assertSameValue(13, (int) $failedDelivery['attempts']);
        assertSameValue(1, (int) $failedDelivery['consecutive_failures']);
        assertSameValue('SMTP password=[redacted] rejected', $failedDelivery['last_error']);
        assertSameValue(false, str_contains($failedDelivery['last_error'], 'secret-value'));

        assertHttpException(static fn () => $service->retry(['id' => 4, 'reason' => 'Retry after checking the provider.'], ['id' => 9]), 409);
        assertHttpException(static fn () => $service->retry(['id' => 3, 'reason' => 'Retry after checking the provider.'], ['id' => 9]), 409);
        assertHttpException(static fn () => $service->retry(['id' => 99, 'reason' => 'Retry after checking the provider.'], ['id' => 9]), 404);
        $service->retry(['id' => 5, 'reason' => 'Recover stale worker lease after restart.'], ['id' => 9]);
        assertSameValue('pending', $pdo->query('SELECT status FROM payment_notification_outbox WHERE id = 5')->fetchColumn());
    },
    'product reviews require a paid purchase and never disclose the reviewer full name' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  Product review integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE TEMPORARY TABLE products (id BIGINT UNSIGNED PRIMARY KEY, seller_id BIGINT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE sellers (id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, verified BOOLEAN NOT NULL) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE users (id BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(120) NOT NULL) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE orders (
            id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
            payment_status VARCHAR(24) NOT NULL, status VARCHAR(24) NOT NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE order_items (order_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE product_reviews (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL, rating TINYINT UNSIGNED NOT NULL, comment VARCHAR(1000) NOT NULL,
            status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_review_user_product (product_id, user_id)
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE rate_limits (
            action_name VARCHAR(80) NOT NULL, identifier_hash CHAR(64) NOT NULL, attempts INT NOT NULL,
            window_started_at DATETIME NOT NULL, PRIMARY KEY (action_name, identifier_hash)
        ) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO products (id, seller_id, status) VALUES (22, 4, 'active')");
        $pdo->exec('INSERT INTO sellers (id, user_id, verified) VALUES (4, 40, TRUE)');
        $pdo->exec("INSERT INTO users (id, name) VALUES (9, 'Private Full Customer Name')");
        $service = new ReviewService($pdo, new RateLimiter($pdo));
        $user = ['id' => 9, 'emailVerifiedAt' => '2026-10-01 12:00:00'];
        assertHttpException(
            static fn () => $service->save(['productId' => 22, 'rating' => 5, 'comment' => 'Excellent plant quality.'], $user),
            403
        );

        $pdo->exec("INSERT INTO orders (id, user_id, payment_status, status) VALUES (91, 9, 'paid', 'delivered')");
        $pdo->exec('INSERT INTO order_items (order_id, product_id) VALUES (91, 22)');
        $service->save(['productId' => 22, 'rating' => 5, 'comment' => 'Excellent plant quality.'], $user);
        $reviews = $service->list(['productId' => '22'], $user);
        assertSameValue(true, $reviews['canReview']);
        assertSameValue('Verified customer', $reviews['data'][0]['authorName']);
        assertSameValue(true, $reviews['data'][0]['verifiedPurchase']);
        assertSameValue(1, $reviews['total']);
        assertSameValue(false, $reviews['hasMore']);
        assertTrueValue(!str_contains(json_encode($reviews, JSON_THROW_ON_ERROR), 'Private Full Customer Name'));

        $addUser = $pdo->prepare('INSERT INTO users (id, name) VALUES (:id, :name)');
        $addReview = $pdo->prepare(
            "INSERT INTO product_reviews (product_id, user_id, rating, comment, status)
             VALUES (22, :userId, :rating, 'A useful note about the product.', 'visible')"
        );
        for ($userId = 100; $userId < 205; $userId++) {
            $addUser->execute([':id' => $userId, ':name' => 'Private Reviewer ' . $userId]);
            $addReview->execute([':userId' => $userId, ':rating' => (($userId % 5) + 1)]);
        }
        $firstPage = $service->list(['productId' => '22', 'page' => '1', 'limit' => '50'], $user);
        assertSameValue(106, $firstPage['total']);
        assertSameValue(50, count($firstPage['data']));
        assertSameValue(true, $firstPage['hasMore']);
        $middlePage = $service->list(['productId' => '22', 'page' => '2', 'limit' => '50'], $user);
        assertSameValue(50, count($middlePage['data']));
        assertSameValue(true, $middlePage['hasMore']);
        $lastPage = $service->list(['productId' => '22', 'page' => '3', 'limit' => '50'], $user);
        assertSameValue(6, count($lastPage['data']));
        assertSameValue(false, $lastPage['hasMore']);
        assertSameValue(106, $lastPage['summary']['count']);
        assertHttpException(static fn () => $service->list(['productId' => '22', 'page' => '0'], $user), 422);
    },
    'ECB reference feed parses the latest usable USD per EUR observation' => static function (): void {
        $csv = "TIME_PERIOD,OBS_VALUE\n2026-10-01,1.1140\n2026-10-02,1.1225\n";
        $rate = ExchangeRateService::parseObservation($csv, new DateTimeImmutable('2026-10-04T12:00:00Z'));
        assertSameValue('2026-10-02', $rate['rateDate']);
        assertSameValue(1.1225, $rate['usdPerEur']);
        assertSameValue('ecb-daily-reference', $rate['source']);
        assertSameValue(64, strlen(ExchangeRateService::rateToken($rate['usdPerEur'], $rate['rateDate'])));
    },
    'ECB reference feed rejects stale and future observations' => static function (): void {
        $now = new DateTimeImmutable('2026-10-10T12:00:00Z');
        foreach (["TIME_PERIOD,OBS_VALUE\n2026-10-01,1.1225\n", "TIME_PERIOD,OBS_VALUE\n2026-10-11,1.1225\n"] as $csv) {
            try {
                ExchangeRateService::parseObservation($csv, $now);
                throw new RuntimeException('Expected a stale/future ECB observation to be rejected.');
            } catch (RuntimeException $exception) {
                assertTrueValue(str_contains($exception->getMessage(), 'ECB CSV'));
            }
        }
    },
    'ECB refresh is single-flight across requests and serves only a still-usable cache while busy' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  ECB refresh lock integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $dsn = 'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $database[2], (string) (getenv('DB_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $lockPdo = new PDO($dsn, $database[2], (string) (getenv('DB_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("SET time_zone = '+00:00'");
        $lockPdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("CREATE TEMPORARY TABLE commerce_exchange_rates (
            pair VARCHAR(16) PRIMARY KEY, usd_per_eur DECIMAL(12,8) NULL, rate_date DATE NULL,
            source VARCHAR(80) NULL, checked_at DATETIME NOT NULL, failure_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_error_code VARCHAR(80) NULL
        ) ENGINE=InnoDB");
        $previousDate = gmdate('Y-m-d', time() - 86_400);
        $cached = $pdo->prepare(
            "INSERT INTO commerce_exchange_rates (pair, usd_per_eur, rate_date, source, checked_at, failure_count)
             VALUES ('USD/EUR', 1.10000000, :rateDate, 'ecb-daily-reference', UTC_TIMESTAMP() - INTERVAL 1 HOUR, 0)"
        );
        $cached->execute([':rateDate' => $previousDate]);
        $lockName = 'botanical.exchange-rate-refresh.' . substr(hash('sha256', (string) $database[1]), 0, 24);
        $lock = $lockPdo->prepare('SELECT GET_LOCK(:lockName, 0)');
        $lock->execute([':lockName' => $lockName]);
        assertSameValue(1, (int) $lock->fetchColumn());

        $fetchCount = 0;
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $fetcher = static function () use (&$fetchCount, $previousDate): string {
            $fetchCount++;
            return "TIME_PERIOD,OBS_VALUE\n{$previousDate},1.1111\n";
        };
        try {
            $service = new ExchangeRateService($pdo, $fetcher, $clock);
            $duringRefresh = $service->currentReferenceRate();
            assertSameValue('refresh_in_progress', $duringRefresh['status']);
            assertSameValue(1.0 / 1.1, $duringRefresh['eurPerUsd'], 'Use the last acceptable ECB observation while another worker refreshes it.');
            assertSameValue(0, $fetchCount, 'A request that does not own the refresh lock must not call the ECB.');
        } finally {
            $releaseLock = $lockPdo->prepare('SELECT RELEASE_LOCK(:lockName)');
            $releaseLock->execute([':lockName' => $lockName]);
        }

        $refreshed = $service->currentReferenceRate();
        assertSameValue('current', $refreshed['status']);
        assertSameValue(1, $fetchCount);
        assertSameValue(1.1111, $refreshed['usdPerEur']);
    },
    'created, updated and failed Stripe refunds are recognized as refund webhook events' => static function (): void {
        foreach (['refund.created', 'refund.updated', 'refund.failed'] as $type) {
            assertSameValue(true, PaymentWebhookActionsTestHarness::isRefundWebhookType($type));
        }
        assertSameValue(false, PaymentWebhookActionsTestHarness::isRefundWebhookType('charge.refunded'));
    },
    'paid order and confirmation outbox commit atomically and a repeated event repairs an old outbox gap' => static function (): void {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE orders (
                id INTEGER PRIMARY KEY, order_number TEXT NOT NULL, status TEXT NOT NULL,
                payment_status TEXT NOT NULL, payment_provider TEXT NOT NULL,
                payment_session_id TEXT NOT NULL, payment_intent_id TEXT NULL,
                test_payment INTEGER NOT NULL, currency TEXT NOT NULL, total TEXT NOT NULL,
                reservation_expires_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE payment_notification_outbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL,
                notification_type TEXT NOT NULL, reference_id TEXT NOT NULL DEFAULT "",
                UNIQUE (order_id, notification_type, reference_id)
            )'
        );
        $pdo->exec(
            "INSERT INTO orders (id, order_number, status, payment_status, payment_provider,
                    payment_session_id, test_payment, currency, total)
             VALUES (1, 'ORDER-ATOMIC-1', 'awaiting_payment', 'pending', 'stripe',
                    'cs_test_atomic123', 1, 'EUR', '10.00')"
        );
        $pdo->exec(
            "CREATE TRIGGER fail_confirmation_outbox BEFORE INSERT ON payment_notification_outbox
             BEGIN SELECT RAISE(ABORT, 'injected outbox failure'); END"
        );
        $recovery = new PaymentWebhookRecoveryTestStub($pdo);
        $service = new PaymentWebhookActionsTestHarness($pdo, $recovery);
        $session = [
            'id' => 'cs_test_atomic123', 'livemode' => false, 'currency' => 'eur',
            'amount_total' => 1000, 'payment_status' => 'paid', 'payment_intent' => 'pi_atomic_1',
        ];

        withEnvironment([
            'APP_ENV' => 'development',
            'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_STRIPE_TEST,
        ], static function () use ($pdo, $service, $session): void {
            try {
                $service->confirm($session);
                throw new RuntimeException('The injected outbox failure should abort payment confirmation.');
            } catch (PDOException $exception) {
                assertTrueValue(
                    str_contains($exception->getMessage(), 'injected outbox failure'),
                    'Unexpected database error: ' . $exception->getMessage()
                );
            }

            $pending = $pdo->query('SELECT status, payment_status, payment_intent_id FROM orders WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            assertSameValue('awaiting_payment', $pending['status']);
            assertSameValue('pending', $pending['payment_status']);
            assertSameValue(null, $pending['payment_intent_id']);
            assertSameValue(false, $pdo->inTransaction());
            assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());

            $pdo->exec('DROP TRIGGER fail_confirmation_outbox');
            $service->confirm($session);
            $confirmed = $pdo->query('SELECT status, payment_status, payment_intent_id FROM orders WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            assertSameValue('confirmed', $confirmed['status']);
            assertSameValue('paid', $confirmed['payment_status']);
            assertSameValue('pi_atomic_1', $confirmed['payment_intent_id']);
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());

            $service->confirm($session);
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
            $pdo->exec('DELETE FROM payment_notification_outbox');
            $service->confirm($session);
            assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
        });
    },
    'background worker recovers only eligible paid orders missing a confirmation email' => static function (): void {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE orders (
                id INTEGER PRIMARY KEY, payment_provider TEXT NOT NULL,
                payment_status TEXT NOT NULL, status TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE payment_notification_outbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL,
                notification_type TEXT NOT NULL, reference_id TEXT NOT NULL DEFAULT "",
                UNIQUE (order_id, notification_type, reference_id)
            )'
        );
        $pdo->exec("INSERT INTO orders VALUES (1, 'stripe', 'paid', 'confirmed')");
        $pdo->exec("INSERT INTO orders VALUES (2, 'stripe', 'paid', 'confirmed')");
        $pdo->exec("INSERT INTO orders VALUES (3, 'stripe', 'paid', 'cancelled')");
        $pdo->exec("INSERT INTO orders VALUES (4, 'local_test', 'paid', 'shipped')");
        $pdo->exec("INSERT INTO orders VALUES (5, 'stripe', 'pending', 'awaiting_payment')");
        $pdo->exec("INSERT INTO payment_notification_outbox (order_id, notification_type) VALUES (2, 'payment_confirmation')");
        $service = new PaymentOperationsService(
            $pdo,
            new StripeGateway(),
            new PaymentOrderLock($pdo),
            new Mailer(),
            static fn (array $session, bool $paid): mixed => null,
            static fn (int $orderId, string $status): mixed => null,
            static fn (array $refund): mixed => null
        );
        $recover = new ReflectionMethod(PaymentOperationsService::class, 'recoverMissingPaymentConfirmations');
        assertSameValue(2, $recover->invoke($service, 20));
        assertSameValue(3, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
        assertSameValue(0, $recover->invoke($service, 20));
        assertSameValue(3, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox')->fetchColumn());
    },
    'background worker reconciles only the exact unresolved refund attempt and throttles retries' => static function (): void {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->sqliteCreateFunction('GET_LOCK', static fn (): int => 1, 2);
        $pdo->sqliteCreateFunction('RELEASE_LOCK', static fn (): int => 1, 1);
        $pdo->exec(
            'CREATE TABLE orders (
                id INTEGER PRIMARY KEY, order_number TEXT NOT NULL, payment_provider TEXT NOT NULL,
                payment_status TEXT NOT NULL, payment_refund_outcome TEXT NOT NULL,
                payment_refund_reconciled_at TEXT NULL, payment_intent_id TEXT NOT NULL,
                payment_refund_attempt INTEGER NOT NULL, test_payment INTEGER NOT NULL,
                payment_refund_id TEXT NULL, payment_refund_error_code TEXT NULL,
                payment_refund_error_message TEXT NULL, payment_refund_error_http_status INTEGER NULL,
                created_at TEXT NOT NULL
            )'
        );
        $insert = $pdo->prepare(
            "INSERT INTO orders (id, order_number, payment_provider, payment_status, payment_refund_outcome,
                    payment_intent_id, payment_refund_attempt, test_payment, created_at)
             VALUES (:id, :number, 'stripe', 'refund_pending', :outcome, :intent, :attempt, 1, CURRENT_TIMESTAMP)"
        );
        $insert->execute([':id' => 1, ':number' => 'ORDER-REFUND-1', ':outcome' => 'unknown', ':intent' => 'pi_order_1', ':attempt' => 3]);
        $insert->execute([':id' => 2, ':number' => 'ORDER-REFUND-2', ':outcome' => 'pending', ':intent' => 'pi_order_2', ':attempt' => 4]);

        $lookups = 0;
        $apply = static function (array $refund) use ($pdo): void {
            $update = $pdo->prepare("UPDATE orders SET payment_status = 'refunded', payment_refund_outcome = 'succeeded', payment_refund_id = :id WHERE order_number = :number");
            $update->execute([':id' => $refund['id'], ':number' => $refund['metadata']['order_number']]);
        };
        $listRefunds = static function (string $paymentIntentId) use (&$lookups): array {
            $lookups++;
            if ($paymentIntentId === 'pi_order_2') return [];
            return [
                ['id' => 're_other_order', 'payment_intent' => $paymentIntentId, 'livemode' => false,
                    'metadata' => ['order_number' => 'OTHER-ORDER', 'refund_attempt' => '3']],
                ['id' => 're_old_attempt', 'payment_intent' => $paymentIntentId, 'livemode' => false,
                    'metadata' => ['order_number' => 'ORDER-REFUND-1', 'refund_attempt' => '2']],
                ['id' => 're_current', 'payment_intent' => $paymentIntentId, 'livemode' => false,
                    'metadata' => ['order_number' => 'ORDER-REFUND-1', 'refund_attempt' => '3']],
            ];
        };
        $service = new PaymentOperationsService(
            $pdo,
            new StripeGateway(),
            new PaymentOrderLock($pdo),
            new Mailer(),
            static fn (array $session, bool $paid): mixed => null,
            static fn (int $orderId, string $status): mixed => null,
            $apply,
            $listRefunds
        );

        withEnvironment([
            'APP_ENV' => 'development',
            'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_STRIPE_TEST,
            'STRIPE_SECRET_KEY' => 'sk_test_reconciliation',
        ], static function () use ($service, $pdo, &$lookups): void {
            $reconcile = new ReflectionMethod(PaymentOperationsService::class, 'reconcilePendingRefunds');
            assertSameValue(1, $reconcile->invoke($service, 20));
            $confirmed = $pdo->query('SELECT payment_status, payment_refund_outcome, payment_refund_id FROM orders WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            assertSameValue('refunded', $confirmed['payment_status']);
            assertSameValue('succeeded', $confirmed['payment_refund_outcome']);
            assertSameValue('re_current', $confirmed['payment_refund_id']);

            $unresolved = $pdo->query('SELECT payment_status, payment_refund_outcome, payment_refund_reconciled_at FROM orders WHERE id = 2')->fetch(PDO::FETCH_ASSOC);
            assertSameValue('refund_pending', $unresolved['payment_status']);
            assertSameValue('pending', $unresolved['payment_refund_outcome']);
            assertTrueValue(is_string($unresolved['payment_refund_reconciled_at']));

            assertSameValue(0, $reconcile->invoke($service, 20));
            assertSameValue(2, $lookups, 'A recent lookup should not be repeated on the next worker pass.');
        });
    },
    'background worker preserves stock through delayed payments and resolves terminal Stripe intents' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  Delayed payment reconciliation integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->exec("CREATE TEMPORARY TABLE orders (
            id BIGINT UNSIGNED PRIMARY KEY, order_number VARCHAR(40) NOT NULL, payment_provider VARCHAR(24) NOT NULL,
            payment_session_id VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, payment_status VARCHAR(32) NOT NULL,
            payment_reconciled_at DATETIME NULL, payment_intent_id VARCHAR(128) NULL, currency CHAR(3) NOT NULL,
            total DECIMAL(10,2) NOT NULL, test_payment BOOLEAN NOT NULL, reservation_expires_at DATETIME NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB");
        $insert = $pdo->prepare(
            "INSERT INTO orders
                (id, order_number, payment_provider, payment_session_id, status, payment_status, currency, total,
                 test_payment, reservation_expires_at, created_at)
             VALUES (:id, :number, 'stripe', :sessionId, 'awaiting_payment', 'pending', 'EUR', 10.00,
                     TRUE, :expiresAt, CURRENT_TIMESTAMP)"
        );
        $insert->execute([':id' => 1, ':number' => 'ORDER-SEPA-PROCESSING', ':sessionId' => 'cs_test_processing', ':expiresAt' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $insert->execute([':id' => 2, ':number' => 'ORDER-SEPA-FAILED', ':sessionId' => 'cs_test_failed', ':expiresAt' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        $insert->execute([':id' => 3, ':number' => 'ORDER-SEPA-SUCCEEDED', ':sessionId' => 'cs_test_succeeded', ':expiresAt' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $sessions = [];
        $intents = [];
        foreach ([1 => 'processing', 2 => 'canceled', 3 => 'succeeded'] as $id => $status) {
            $sessionId = [1 => 'cs_test_processing', 2 => 'cs_test_failed', 3 => 'cs_test_succeeded'][$id];
            $intentId = [1 => 'pi_processing', 2 => 'pi_failed', 3 => 'pi_succeeded'][$id];
            $orderNumber = [1 => 'ORDER-SEPA-PROCESSING', 2 => 'ORDER-SEPA-FAILED', 3 => 'ORDER-SEPA-SUCCEEDED'][$id];
            $sessions[$sessionId] = [
                'id' => $sessionId, 'livemode' => false, 'status' => 'complete', 'payment_status' => 'unpaid',
                'currency' => 'eur', 'amount_total' => 1000, 'payment_intent' => $intentId,
                'metadata' => ['order_number' => $orderNumber],
            ];
            $intents[$intentId] = [
                'id' => $intentId, 'livemode' => false, 'status' => $status, 'currency' => 'eur', 'amount' => 1000,
                'metadata' => ['order_number' => $orderNumber],
            ];
        }
        $applyCheckout = static function (array $session, bool $paid) use ($pdo): void {
            if (!$paid) throw new RuntimeException('Expected the succeeded PaymentIntent to confirm its order.');
            $update = $pdo->prepare(
                "UPDATE orders SET status = 'confirmed', payment_status = 'paid', payment_intent_id = :intentId,
                        reservation_expires_at = NULL WHERE payment_session_id = :sessionId"
            );
            $update->execute([':intentId' => $session['payment_intent'], ':sessionId' => $session['id']]);
        };
        $cancelOrder = static function (int $orderId, string $status) use ($pdo): void {
            $update = $pdo->prepare(
                "UPDATE orders SET status = 'cancelled', payment_status = :status, reservation_expires_at = NULL
                 WHERE id = :id AND status = 'awaiting_payment' AND payment_status = 'pending'"
            );
            $update->execute([':status' => $status, ':id' => $orderId]);
        };
        $service = new CheckoutReconciliationService(
            $pdo,
            new StripeGateway(),
            new PaymentOrderLock($pdo),
            $applyCheckout,
            $cancelOrder,
            static fn (string $sessionId): array => $sessions[$sessionId] ?? throw new RuntimeException('Unexpected checkout session.'),
            static fn (string $intentId): array => $intents[$intentId] ?? throw new RuntimeException('Unexpected PaymentIntent.')
        );

        withEnvironment([
            'APP_ENV' => 'development',
            'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_STRIPE_TEST,
        ], static function () use ($pdo, $service): void {
            assertSameValue(3, $service->reconcilePendingSessions(20));
            $processing = $pdo->query('SELECT status, payment_status, reservation_expires_at FROM orders WHERE id = 1')->fetch();
            assertSameValue('awaiting_payment', $processing['status']);
            assertSameValue('pending', $processing['payment_status']);
            assertSameValue(null, $processing['reservation_expires_at']);
            $failed = $pdo->query('SELECT status, payment_status FROM orders WHERE id = 2')->fetch();
            assertSameValue('cancelled', $failed['status']);
            assertSameValue('failed', $failed['payment_status']);
            $succeeded = $pdo->query('SELECT status, payment_status, payment_intent_id FROM orders WHERE id = 3')->fetch();
            assertSameValue('confirmed', $succeeded['status']);
            assertSameValue('paid', $succeeded['payment_status']);
            assertSameValue('pi_succeeded', $succeeded['payment_intent_id']);
        });
    },
    'consumer requests stay available for paid delivered plant orders without assuming an exception' => static function (): void {
        $order = [
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payment_refund_outcome' => 'none',
            'total' => '100.00',
            'refundedCents' => 0,
            'product_category' => 'plants',
        ];
        assertSameValue(true, WithdrawalPolicy::canRequest($order));
        assertSameValue(false, WithdrawalPolicy::canRequest([...$order, 'payment_status' => 'pending']));
        assertSameValue(false, WithdrawalPolicy::canRequest([...$order, 'status' => 'cancelled']));
        assertSameValue(true, WithdrawalPolicy::canRequest([...$order, 'payment_status' => 'partially_refunded', 'payment_refund_outcome' => 'succeeded', 'refundedCents' => 2500]));
        assertSameValue(false, WithdrawalPolicy::canRequest([...$order, 'payment_status' => 'refunded', 'refundedCents' => 10_000]));
    },
    'withdrawal refund can start only after return or proof and against a refundable Stripe payment' => static function (): void {
        $order = [
            'status' => 'shipped',
            'payment_status' => 'paid',
            'payment_provider' => 'stripe',
            'payment_intent_id' => 'pi_test',
            'total' => '100.00',
            'payment_refunded_cents' => 0,
        ];
        assertSameValue(true, WithdrawalPolicy::canStartRefund('return_received', $order));
        assertSameValue(true, WithdrawalPolicy::canStartRefund('refund_failed', $order));
        assertSameValue(false, WithdrawalPolicy::canStartRefund('instructions_sent', $order));
        assertSameValue(false, WithdrawalPolicy::canStartRefund('return_received', [...$order, 'payment_provider' => 'local_test']));
        assertSameValue(false, WithdrawalPolicy::canStartRefund('return_received', [...$order, 'payment_status' => 'refunded']));
        assertSameValue(false, WithdrawalPolicy::canStartRefund('return_received', [...$order, 'payment_intent_id' => '']));
        assertSameValue(true, WithdrawalPolicy::canStartRefund('return_received', [...$order, 'payment_status' => 'partially_refunded', 'payment_refunded_cents' => 2500]));
    },
    'withdrawal workflow prevents premature refund and reopening resolved decisions' => static function (): void {
        assertSameValue(true, WithdrawalPolicy::canTransition('submitted', 'reviewing'));
        assertSameValue(true, WithdrawalPolicy::canTransition('instructions_sent', 'return_received'));
        assertSameValue(true, WithdrawalPolicy::canTransition('refund_failed', 'refund_pending'));
        assertSameValue(false, WithdrawalPolicy::canTransition('submitted', 'refund_pending'));
        assertSameValue(false, WithdrawalPolicy::canTransition('not_eligible', 'instructions_sent'));
        assertSameValue(false, WithdrawalPolicy::canTransition('refunded', 'refund_pending'));
    },
    'Stripe refund errors distinguish definitive rejection from unknown outcomes' => static function (): void {
        assertTrueValue(StripeApiException::isDefinitiveHttpStatus(400));
        assertTrueValue(StripeApiException::isDefinitiveHttpStatus(429));
        assertSameValue(false, StripeApiException::isDefinitiveHttpStatus(408));
        assertSameValue(false, StripeApiException::isDefinitiveHttpStatus(409));
        assertSameValue(false, StripeApiException::isDefinitiveHttpStatus(500));
        assertSameValue(false, StripeApiException::isDefinitiveHttpStatus(null));
    },
    'checkout stock is released only after a definitive Stripe creation rejection' => static function (): void {
        assertTrueValue(CheckoutFailurePolicy::shouldReleaseReservation(new StripeApiException(400, 'invalid_request', 'invalid_request_error', 'Rejected.', true)));
        assertTrueValue(CheckoutFailurePolicy::shouldReleaseReservation(new StripeApiException(429, 'rate_limit', 'rate_limit_error', 'Rejected.', true)));
        assertSameValue(false, CheckoutFailurePolicy::shouldReleaseReservation(StripeApiException::fromTransportFailure()));
        assertSameValue(false, CheckoutFailurePolicy::shouldReleaseReservation(new StripeApiException(500, 'api_error', 'api_error', 'Unknown.', false)));
        assertSameValue(false, CheckoutFailurePolicy::shouldReleaseReservation(new RuntimeException('Unknown provider response.')));
    },
    'Stripe Checkout return URLs never expose provider session or order identifiers' => static function (): void {
        withEnvironment([
            'APP_ENV' => 'development',
            'APP_URL' => 'http://localhost:4300',
            'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_STRIPE_TEST,
            'STRIPE_SECRET_KEY' => 'sk_test_return_url_fixture',
            'STRIPE_ACCOUNT_ID' => 'acct_returnfixture123',
            'STRIPE_SETTLEMENT_CURRENCY' => 'EUR',
            'STRIPE_PAYMENT_LIMITS_JSON' => '{"settlementCurrency":"EUR","methods":{"card":{"EUR":{"minCents":50,"maxCents":100000}}}}',
        ], static function (): void {
            $observed = [];
            $gateway = new StripeGateway(static function (string $url, $context, int $maxBytes) use (&$observed): array {
                $options = stream_context_get_options($context);
                parse_str((string) ($options['http']['content'] ?? ''), $observed);
                return [
                    'body' => '{"id":"cs_test_return_fixture","url":"https://checkout.stripe.com/c/pay/cs_test_return_fixture"}',
                    'statusLine' => 'HTTP/1.1 200 OK',
                ];
            });
            $gateway->createCheckoutSession([
                'order_number' => 'BE-20261006-RETURN12345',
                'payment_method' => 'card',
                'currency' => 'EUR',
                'total' => '12.34',
                'shipping_amount' => '0.00',
                'email' => 'buyer@example.test',
                'payment_session_attempted_at' => '2026-10-06 10:00:00',
            ], [[
                'unitPrice' => '12.34',
                'productName' => 'Fixture plant',
                'quantity' => 1,
            ]]);

            assertSameValue('http://localhost:4300/payment?payment=success', $observed['success_url'] ?? null);
            assertSameValue('http://localhost:4300/payment?payment=cancelled', $observed['cancel_url'] ?? null);
            assertSameValue(false, str_contains((string) ($observed['success_url'] ?? ''), 'session_id'));
            assertSameValue(false, str_contains((string) ($observed['cancel_url'] ?? ''), 'order_number'));
        });
    },
    'Stripe gateway transport enforces TLS redirects and idempotency without real network calls' => static function (): void {
        withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_transport_fixture'], static function (): void {
            $observed = [];
            $gateway = new StripeGateway(static function (string $url, $context, int $maxBytes) use (&$observed): array {
                $options = stream_context_get_options($context);
                $observed = ['url' => $url, 'options' => $options, 'maxBytes' => $maxBytes];
                return ['body' => '{"id":"re_fixture"}', 'statusLine' => 'HTTP/1.1 200 OK'];
            });

            $refund = $gateway->refundPaymentIntent('pi_fixture', 'BE-TEST-123', 2, 250);
            assertSameValue('re_fixture', $refund['id']);
            assertSameValue('https://api.stripe.com/v1/refunds', $observed['url']);
            assertSameValue(true, $observed['options']['ssl']['verify_peer']);
            assertSameValue(true, $observed['options']['ssl']['verify_peer_name']);
            assertSameValue(0, $observed['options']['http']['follow_location']);
            assertSameValue(0, $observed['options']['http']['max_redirects']);
            assertSameValue(4_194_305, $observed['maxBytes']);
            assertTrueValue(str_contains($observed['options']['http']['header'], 'Authorization: Bearer sk_test_transport_fixture'));
            assertTrueValue(str_contains($observed['options']['http']['header'], 'Idempotency-Key: refund-BE-TEST-123-2'));
            assertTrueValue(str_contains($observed['options']['http']['content'], 'amount=250'));
        });
    },
    'Stripe gateway rejects redirects and oversized response bodies' => static function (): void {
        withEnvironment(['STRIPE_SECRET_KEY' => 'sk_test_transport_fixture'], static function (): void {
            $redirectGateway = new StripeGateway(static fn (string $url, $context, int $maxBytes): array => [
                'body' => '{"error":{"message":"Redirect not accepted."}}',
                'statusLine' => 'HTTP/1.1 302 Found',
            ]);
            try {
                $redirectGateway->retrieveAccount();
                throw new RuntimeException('Stripe API redirects must be rejected.');
            } catch (StripeApiException $exception) {
                assertSameValue(302, $exception->httpStatus);
            }

            $oversizedGateway = new StripeGateway(static fn (string $url, $context, int $maxBytes): array => [
                'body' => str_repeat('x', $maxBytes),
                'statusLine' => 'HTTP/1.1 200 OK',
            ]);
            try {
                $oversizedGateway->retrieveAccount();
                throw new RuntimeException('Oversized Stripe responses must be rejected.');
            } catch (RuntimeException $exception) {
                assertSameValue('The payment provider returned an oversized response.', $exception->getMessage());
            }
        });
    },
    'checkout redirects accept only secure hosted Stripe URLs' => static function (): void {
        assertTrueValue(PaymentPolicy::isSecureStripeCheckoutUrl('https://checkout.stripe.com/c/pay/cs_test_123'));
        assertTrueValue(PaymentPolicy::isSecureStripeCheckoutUrl('https://buy.stripe.com/test_123'));
        assertSameValue(false, PaymentPolicy::isSecureStripeCheckoutUrl('http://checkout.stripe.com/c/pay/cs_test_123'));
        assertSameValue(false, PaymentPolicy::isSecureStripeCheckoutUrl('https://example.invalid/c/pay/cs_test_123'));
        assertSameValue(false, PaymentPolicy::isSecureStripeCheckoutUrl('https://checkout.stripe.com@example.invalid/c/pay'));
    },
    'Stripe redirect configuration is validated before checkout order creation' => static function (): void {
        withEnvironment(['APP_ENV' => 'production', 'APP_URL' => 'http://shop.example.com'], static function (): void {
            try {
                (new StripeGateway())->assertCheckoutRedirectConfiguration();
            } catch (RuntimeException) {
                return;
            }
            throw new RuntimeException('An insecure production APP_URL should be rejected during preflight.');
        });
        withEnvironment(['APP_ENV' => 'development', 'APP_URL' => 'http://localhost:4300'], static function (): void {
            (new StripeGateway())->assertCheckoutRedirectConfiguration();
        });
    },
    'runtime configuration rejects insecure production URLs and token-logging mail' => static function (): void {
        $base = [
            'APP_ENV' => 'production',
            'APP_URL' => 'https://shop.example.com',
            'CORS_ALLOWED_ORIGINS' => 'https://shop.example.com',
            'ENABLE_DEMO_ACCOUNTS' => 'false',
            'MAIL_TRANSPORT' => 'smtp',
            'MAIL_FROM' => 'no-reply@example.com',
            'MAIL_HOST' => 'smtp.example.com',
            'MAIL_PORT' => '587',
            'MAIL_ENCRYPTION' => 'tls',
            'MAIL_USERNAME' => 'smtp-user',
            'MAIL_PASSWORD' => 'smtp-password',
            'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_STRIPE_LIVE,
            'STRIPE_SECRET_KEY' => 'sk_live_placeholder',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_placeholder',
            'STRIPE_ACCOUNT_ID' => 'acct_placeholder',
            'STRIPE_SETTLEMENT_CURRENCY' => 'EUR',
            'STRIPE_PAYMENT_LIMITS_JSON' => '{"settlementCurrency":"EUR","methods":{"card":{"EUR":{"minCents":50,"maxCents":99999999},"USD":{"minCents":100,"maxCents":99999999}},"bancontact":{"EUR":{"minCents":50,"maxCents":1000000}},"sepa_debit":{"EUR":{"minCents":50,"maxCents":99999999}}}}',
            'DB_DATABASE' => 'botanical_essence',
            'DB_USERNAME' => 'botanical_app',
            'DB_PASSWORD' => 'Q8v#rP3!sD6@kN9%wA2^xM5&cB7+fL0z',
        ];

        withEnvironment([...$base, 'APP_URL' => 'http://shop.example.com'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'MAIL_TRANSPORT' => 'log'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'MAIL_TRANSPORT' => 'mail'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'MAIL_HOST' => 'smtp.example.com/relay'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'MAIL_PORT' => '65536'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'MAIL_ENCRYPTION' => 'plain'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'MAIL_PASSWORD' => ''], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'ENABLE_DEMO_ACCOUNTS' => 'true'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'CORS_ALLOWED_ORIGINS' => 'https://shop.example.com/admin'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'STRIPE_SECRET_KEY' => ''], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'DB_PASSWORD' => 'replace-with-a-long-random-password'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'DB_USERNAME' => 'root'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'APP_URL' => 'https://localhost'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'TRUST_PROXY_HEADERS' => 'sometimes'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'TRUST_PROXY_HEADERS' => 'true', 'TRUSTED_PROXY_IPS' => '', 'TRUSTED_PROXY_HOSTS' => ''], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'TRUST_PROXY_HEADERS' => 'true', 'TRUSTED_PROXY_IPS' => '203.0.113.4,not-an-ip', 'TRUSTED_PROXY_HOSTS' => ''], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'TRUST_PROXY_HEADERS' => 'true', 'TRUSTED_PROXY_IPS' => '172.16.0.0/12', 'TRUSTED_PROXY_HOSTS' => ''], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'TRUST_PROXY_HEADERS' => 'true', 'TRUSTED_PROXY_HOSTS' => '*.example.com'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
        withEnvironment([...$base, 'APP_ENV' => 'staging', 'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_STRIPE_TEST, 'APP_URL' => 'http://staging.example.com', 'CORS_ALLOWED_ORIGINS' => 'http://staging.example.com'], static function (): void {
            assertHttpException(static fn () => RuntimeConfiguration::assertSafeStartup(), 503);
        });
    },
    'runtime configuration accepts complete safe production settings and local development defaults' => static function (): void {
        $production = [
            'APP_ENV' => 'production',
            'APP_URL' => 'https://shop.example.com',
            'CORS_ALLOWED_ORIGINS' => 'https://shop.example.com',
            'ENABLE_DEMO_ACCOUNTS' => 'false',
            'MAIL_TRANSPORT' => 'smtp',
            'MAIL_FROM' => 'no-reply@example.com',
            'MAIL_HOST' => 'smtp.example.com',
            'MAIL_PORT' => '587',
            'MAIL_ENCRYPTION' => 'tls',
            'MAIL_USERNAME' => 'smtp-user',
            'MAIL_PASSWORD' => 'smtp-password',
            'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_STRIPE_LIVE,
            'STRIPE_SECRET_KEY' => 'sk_live_placeholder',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_placeholder',
            'STRIPE_ACCOUNT_ID' => 'acct_placeholder',
            'STRIPE_SETTLEMENT_CURRENCY' => 'EUR',
            'STRIPE_PAYMENT_LIMITS_JSON' => '{"settlementCurrency":"EUR","methods":{"card":{"EUR":{"minCents":50,"maxCents":99999999},"USD":{"minCents":100,"maxCents":99999999}},"bancontact":{"EUR":{"minCents":50,"maxCents":1000000}},"sepa_debit":{"EUR":{"minCents":50,"maxCents":99999999}}}}',
            'DB_DATABASE' => 'botanical_essence',
            'DB_USERNAME' => 'botanical_app',
            'DB_PASSWORD' => 'Q8v#rP3!sD6@kN9%wA2^xM5&cB7+fL0z',
        ];
        withEnvironment($production, static function (): void {
            RuntimeConfiguration::assertSafeStartup();
        });
        withEnvironment([...$production, 'TRUST_PROXY_HEADERS' => 'true', 'TRUSTED_PROXY_IPS' => '', 'TRUSTED_PROXY_HOSTS' => 'frontend'], static function (): void {
            RuntimeConfiguration::assertSafeStartup();
        });
        withEnvironment([...$production, 'TRUST_PROXY_HEADERS' => 'true', 'TRUSTED_PROXY_IPS' => '172.20.16.0/24', 'TRUSTED_PROXY_HOSTS' => ''], static function (): void {
            RuntimeConfiguration::assertSafeStartup();
        });
        withEnvironment([...$production, 'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => ''], static function (): void {
            RuntimeConfiguration::assertSafeStartup();
        });

        withEnvironment(['APP_ENV' => 'production', 'APP_URL' => 'https://shop.example.com:443', 'CORS_ALLOWED_ORIGINS' => ''], static function (): void {
            $previousOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
            $_SERVER['HTTP_ORIGIN'] = 'https://shop.example.com';
            try {
                assertTrueValue(SecurityHeaders::applyCors(), 'Default HTTPS ports must normalize to the browser origin.');
            } finally {
                if ($previousOrigin === null) {
                    unset($_SERVER['HTTP_ORIGIN']);
                } else {
                    $_SERVER['HTTP_ORIGIN'] = $previousOrigin;
                }
            }
        });

        withEnvironment([
            'APP_ENV' => 'development',
            'APP_URL' => 'http://localhost:4300',
            'CORS_ALLOWED_ORIGINS' => 'http://localhost:4200,http://localhost:4300',
            'ENABLE_DEMO_ACCOUNTS' => 'true',
            'MAIL_TRANSPORT' => 'log',
            'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_LOCAL_SIMULATION,
            'STRIPE_SECRET_KEY' => '',
            'STRIPE_WEBHOOK_SECRET' => '',
        ], static function (): void {
            RuntimeConfiguration::assertSafeStartup();
        });

        withEnvironment([
            'APP_ENV' => 'test',
            'APP_URL' => 'http://localhost:4300',
            'CORS_ALLOWED_ORIGINS' => 'http://localhost:4300',
            'ENABLE_DEMO_ACCOUNTS' => 'false',
            'MAIL_TRANSPORT' => 'log',
            'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_STRIPE_TEST,
            'STRIPE_SECRET_KEY' => '',
            'STRIPE_WEBHOOK_SECRET' => '',
        ], static function (): void {
            RuntimeConfiguration::assertSafeStartup();
        });
    },
    'a new refund attempt is forbidden while its provider outcome is unresolved' => static function (): void {
        assertSameValue(false, RefundStatePolicy::canStartNewAttempt('refund_pending', 'unknown'));
        assertSameValue(false, RefundStatePolicy::canStartNewAttempt('refund_pending', 'pending'));
        assertSameValue(false, RefundStatePolicy::canStartNewAttempt('paid', 'unknown'));
        assertSameValue(true, RefundStatePolicy::canStartNewAttempt('paid', 'definitive_failure'));
        assertSameValue(true, RefundStatePolicy::canStartNewAttempt('paid', 'manual_not_refunded'));
        assertTrueValue(RefundStatePolicy::isUnresolved('refund_pending', 'unknown'));
    },
    'partial refund success counts manual resolutions exactly once' => static function (): void {
        assertSameValue(true, RefundStatePolicy::partialRefundAmountWasAlreadyCounted('manual', 'manual_refunded'));
        assertSameValue(false, RefundStatePolicy::partialRefundAmountWasAlreadyCounted('manual', 'manual_not_refunded'));
        assertSameValue(true, RefundStatePolicy::partialRefundAmountWasAlreadyCounted('succeeded', null));
        assertSameValue(false, RefundStatePolicy::partialRefundAmountWasAlreadyCounted('pending', null));
        assertSameValue(true, WithdrawalPolicy::canTransition('refund_failed', 'refunded'));
    },
    'late Stripe refund success reconciles manual decisions and older attempts idempotently' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  MySQL partial-refund reconciliation integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE TEMPORARY TABLE orders (
            id BIGINT UNSIGNED PRIMARY KEY, status VARCHAR(32) NOT NULL, payment_status VARCHAR(32) NOT NULL,
            payment_refund_attempt INT UNSIGNED NOT NULL, payment_refund_id VARCHAR(128) NULL,
            payment_refund_outcome VARCHAR(24) NOT NULL, payment_refunded_cents BIGINT UNSIGNED NOT NULL,
            payment_refund_error_code VARCHAR(100) NULL, payment_refund_error_message VARCHAR(500) NULL,
            payment_refund_error_http_status INT NULL, total DECIMAL(10,2) NOT NULL, order_number VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE payment_refund_attempts (
            order_id BIGINT UNSIGNED NOT NULL, attempt INT UNSIGNED NOT NULL, stripe_refund_id VARCHAR(128) NULL,
            attempt_kind VARCHAR(16) NOT NULL DEFAULT 'partial',
            amount_cents BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL, previous_payment_status VARCHAR(32) NOT NULL,
            previous_order_status VARCHAR(32) NOT NULL, withdrawal_request_id BIGINT UNSIGNED NULL,
            status VARCHAR(16) NOT NULL, reason VARCHAR(500) NOT NULL, provider_error_code VARCHAR(100) NULL,
            provider_error_message VARCHAR(500) NULL, requested_by_admin_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (order_id, attempt)
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE payment_notification_outbox (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
            notification_type VARCHAR(60) NOT NULL, reference_id VARCHAR(128) NOT NULL DEFAULT '',
            UNIQUE KEY uq_notification (order_id, notification_type, reference_id)
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE withdrawal_requests (id BIGINT UNSIGNED PRIMARY KEY, status VARCHAR(32) NOT NULL) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE withdrawal_request_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, request_id BIGINT UNSIGNED NOT NULL,
            actor_role VARCHAR(16) NOT NULL, event_name VARCHAR(60) NOT NULL,
            previous_status VARCHAR(32) NULL, new_status VARCHAR(32) NULL, note VARCHAR(2000) NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE admin_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_user_id BIGINT UNSIGNED NULL,
            action_name VARCHAR(100) NOT NULL, target_type VARCHAR(32) NOT NULL, target_id BIGINT UNSIGNED NOT NULL,
            previous_value VARCHAR(1000) NULL, new_value VARCHAR(1000) NULL
        ) ENGINE=InnoDB");

        $harness = new PartialRefundActionsTestHarness($pdo);
        $seedOrder = $pdo->prepare(
            'INSERT INTO orders
                (id, status, payment_status, payment_refund_attempt, payment_refund_id, payment_refund_outcome,
                 payment_refunded_cents, total, order_number)
             VALUES (:id, \'confirmed\', :paymentStatus, :currentAttempt, NULL, :outcome, :refunded, 100.00, :number)'
        );
        $seedAttempt = $pdo->prepare(
            'INSERT INTO payment_refund_attempts
                (order_id, attempt, amount_cents, currency, previous_payment_status, previous_order_status,
                 withdrawal_request_id, status, reason, provider_error_code)
             VALUES (:orderId, :attempt, :amount, \'eur\', \'paid\', \'confirmed\', :requestId, :status, \'test reason\', :resolution)'
        );

        // The admin already counted this refund and queued the confirmation. Stripe's late success
        // must promote the attempt without adding the same cents or sending a second notification.
        $seedOrder->execute([':id' => 1, ':paymentStatus' => 'partially_refunded', ':currentAttempt' => 1, ':outcome' => 'manual_refunded', ':refunded' => 2500, ':number' => 'ORDER-MANUAL-REFUNDED']);
        $seedAttempt->execute([':orderId' => 1, ':attempt' => 1, ':amount' => 2500, ':requestId' => null, ':status' => 'manual', ':resolution' => 'manual_refunded']);
        $pdo->exec("INSERT INTO payment_notification_outbox (order_id, notification_type, reference_id) VALUES (1, 'refund_confirmation', '1')");
        $row = ['order_id' => 1, 'attempt' => 1];
        $harness->apply($row, ['id' => 're_manual_1', 'status' => 'pending']);
        assertSameValue('manual', $pdo->query('SELECT status FROM payment_refund_attempts WHERE order_id = 1')->fetchColumn());
        assertSameValue('manual_refunded', $pdo->query('SELECT provider_error_code FROM payment_refund_attempts WHERE order_id = 1')->fetchColumn());
        $harness->apply($row, ['id' => 're_manual_1', 'status' => 'succeeded', 'amount' => 2500, 'currency' => 'eur']);
        $harness->apply($row, ['id' => 're_manual_1', 'status' => 'succeeded', 'amount' => 2500, 'currency' => 'eur']);
        assertSameValue(2500, (int) $pdo->query('SELECT payment_refunded_cents FROM orders WHERE id = 1')->fetchColumn());
        assertSameValue('succeeded', $pdo->query('SELECT payment_refund_outcome FROM orders WHERE id = 1')->fetchColumn());
        assertSameValue('succeeded', $pdo->query('SELECT status FROM payment_refund_attempts WHERE order_id = 1')->fetchColumn());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox WHERE order_id = 1')->fetchColumn());

        // An admin decision that no money was returned did not count the amount. A late Stripe
        // success must add it once and repair the linked return request's failed state.
        $seedOrder->execute([':id' => 2, ':paymentStatus' => 'partially_refunded', ':currentAttempt' => 1, ':outcome' => 'manual_not_refunded', ':refunded' => 1000, ':number' => 'ORDER-MANUAL-NOT-REFUNDED']);
        $seedAttempt->execute([':orderId' => 2, ':attempt' => 1, ':amount' => 2000, ':requestId' => 22, ':status' => 'manual', ':resolution' => 'manual_not_refunded']);
        $pdo->exec("INSERT INTO withdrawal_requests (id, status) VALUES (22, 'refund_failed')");
        $harness->apply(['order_id' => 2, 'attempt' => 1], ['id' => 're_manual_2', 'status' => 'succeeded', 'amount' => 2000, 'currency' => 'eur']);
        $harness->apply(['order_id' => 2, 'attempt' => 1], ['id' => 're_manual_2', 'status' => 'succeeded', 'amount' => 2000, 'currency' => 'eur']);
        assertSameValue(3000, (int) $pdo->query('SELECT payment_refunded_cents FROM orders WHERE id = 2')->fetchColumn());
        assertSameValue('partially_refunded', $pdo->query('SELECT payment_status FROM orders WHERE id = 2')->fetchColumn());
        assertSameValue('refunded', $pdo->query('SELECT status FROM withdrawal_requests WHERE id = 22')->fetchColumn());
        assertSameValue('refund_failed', $pdo->query('SELECT previous_status FROM withdrawal_request_events WHERE request_id = 22')->fetchColumn());
        assertSameValue('refund.confirmed_after_manual_resolution', $pdo->query('SELECT event_name FROM withdrawal_request_events WHERE request_id = 22')->fetchColumn());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM payment_notification_outbox WHERE order_id = 2')->fetchColumn());

        // A delayed older attempt updates the cumulative refunded amount but cannot replace the
        // newer attempt's in-flight order state.
        $seedOrder->execute([':id' => 3, ':paymentStatus' => 'refund_pending', ':currentAttempt' => 2, ':outcome' => 'pending', ':refunded' => 0, ':number' => 'ORDER-OLDER-ATTEMPT']);
        $seedAttempt->execute([':orderId' => 3, ':attempt' => 1, ':amount' => 1000, ':requestId' => null, ':status' => 'manual', ':resolution' => 'manual_not_refunded']);
        $seedAttempt->execute([':orderId' => 3, ':attempt' => 2, ':amount' => 1500, ':requestId' => null, ':status' => 'pending', ':resolution' => null]);
        $harness->apply(['order_id' => 3, 'attempt' => 1], ['id' => 're_old_attempt', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'eur']);
        $oldAttemptOrder = $pdo->query('SELECT payment_status, payment_refund_attempt, payment_refund_outcome, payment_refunded_cents FROM orders WHERE id = 3')->fetch(PDO::FETCH_ASSOC);
        assertSameValue('refund_pending', $oldAttemptOrder['payment_status']);
        assertSameValue(2, (int) $oldAttemptOrder['payment_refund_attempt']);
        assertSameValue('pending', $oldAttemptOrder['payment_refund_outcome']);
        assertSameValue(1000, (int) $oldAttemptOrder['payment_refunded_cents']);

        // Webhooks are unordered: a stale pending event must not reopen a failed partial refund.
        $seedOrder->execute([':id' => 4, ':paymentStatus' => 'paid', ':currentAttempt' => 1, ':outcome' => 'definitive_failure', ':refunded' => 0, ':number' => 'ORDER-STALE-PENDING']);
        $seedAttempt->execute([':orderId' => 4, ':attempt' => 1, ':amount' => 1000, ':requestId' => null, ':status' => 'failed', ':resolution' => 'declined']);
        $harness->apply(['order_id' => 4, 'attempt' => 1], ['id' => 're_stalepartial4', 'status' => 'pending']);
        assertSameValue('failed', $pdo->query('SELECT status FROM payment_refund_attempts WHERE order_id = 4')->fetchColumn());
        assertSameValue('paid', $pdo->query('SELECT payment_status FROM orders WHERE id = 4')->fetchColumn());
        assertSameValue('definitive_failure', $pdo->query('SELECT payment_refund_outcome FROM orders WHERE id = 4')->fetchColumn());
    },
    'full refund webhooks reconcile late manual outcomes once and release stock atomically' => static function (): void {
        $database = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
        if (!mysqlIntegrationTestsCanMutateDatabase($database)) {
            fwrite(STDOUT, "SKIP  MySQL full-refund webhook integration requires ALLOW_DISPOSABLE_DB_TESTS=true and a DB_DATABASE ending in _ci, _test or _e2e.\n");
            return;
        }
        $pdo = new PDO(
            'mysql:host=' . $database[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database[1] . ';charset=utf8mb4',
            $database[2],
            (string) (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE TEMPORARY TABLE orders (
            id BIGINT UNSIGNED PRIMARY KEY, order_number VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL,
            payment_status VARCHAR(32) NOT NULL, payment_provider VARCHAR(24) NOT NULL, payment_intent_id VARCHAR(128) NOT NULL,
            payment_refund_attempt INT UNSIGNED NOT NULL, payment_refund_id VARCHAR(128) NULL,
            payment_refund_outcome VARCHAR(24) NOT NULL, payment_refunded_cents BIGINT UNSIGNED NOT NULL,
            payment_refund_error_code VARCHAR(100) NULL, payment_refund_error_message VARCHAR(500) NULL,
            payment_refund_error_http_status INT NULL, test_payment BOOLEAN NOT NULL, total DECIMAL(10,2) NOT NULL,
            currency CHAR(3) NOT NULL, reservation_expires_at TIMESTAMP NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE payment_refund_attempts (
            order_id BIGINT UNSIGNED NOT NULL, attempt INT UNSIGNED NOT NULL, attempt_kind VARCHAR(16) NOT NULL,
            stripe_refund_id VARCHAR(128) NULL, amount_cents BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL,
            previous_payment_status VARCHAR(32) NOT NULL, previous_order_status VARCHAR(32) NOT NULL,
            withdrawal_request_id BIGINT UNSIGNED NULL, status VARCHAR(16) NOT NULL, reason VARCHAR(500) NOT NULL,
            provider_error_code VARCHAR(100) NULL, provider_error_message VARCHAR(500) NULL,
            requested_by_admin_id BIGINT UNSIGNED NULL, PRIMARY KEY (order_id, attempt)
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TEMPORARY TABLE payment_notification_outbox (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
            notification_type VARCHAR(60) NOT NULL, reference_id VARCHAR(128) NOT NULL DEFAULT '',
            UNIQUE KEY uq_notification (order_id, notification_type, reference_id)
        ) ENGINE=InnoDB");
        $pdo->exec('CREATE TEMPORARY TABLE products (id BIGINT UNSIGNED PRIMARY KEY, stock INT NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TEMPORARY TABLE order_items (order_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, quantity INT NOT NULL) ENGINE=InnoDB');
        $pdo->exec("CREATE TEMPORARY TABLE admin_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_user_id BIGINT UNSIGNED NULL,
            action_name VARCHAR(100) NOT NULL, target_type VARCHAR(32) NOT NULL, target_id BIGINT UNSIGNED NOT NULL,
            previous_value VARCHAR(1000) NULL, new_value VARCHAR(1000) NULL
        ) ENGINE=InnoDB");

        // The admin's manual_refunded resolution already returned item 2 to stock.
        $pdo->exec("INSERT INTO products VALUES (1, 2)");
        $pdo->exec("INSERT INTO orders
            (id, order_number, status, payment_status, payment_provider, payment_intent_id, payment_refund_attempt,
             payment_refund_outcome, payment_refunded_cents, test_payment, total, currency)
            VALUES (1, 'FULL-LATE-1', 'confirmed', 'paid', 'stripe', 'pi_full_late_1', 1,
                    'manual_not_refunded', 0, 1, 100.00, 'eur'),
                   (2, 'FULL-MANUAL-2', 'cancelled', 'refunded', 'stripe', 'pi_full_manual_2', 1,
                    'manual_refunded', 10000, 1, 100.00, 'eur'),
                   (3, 'FULL-STALE-3', 'confirmed', 'paid', 'stripe', 'pi_full_stale_3', 1,
                    'definitive_failure', 0, 1, 100.00, 'eur'),
                   (4, 'FULL-START-4', 'confirmed', 'paid', 'stripe', 'pi_full_start_4', 0,
                    'none', 0, 1, 100.00, 'eur'),
                   (5, 'FULL-LEGACY-5', 'confirmed', 'refund_pending', 'stripe', 'pi_full_legacy_5', 1,
                    'unknown', 0, 1, 100.00, 'eur')");
        $pdo->exec("INSERT INTO payment_refund_attempts
            (order_id, attempt, attempt_kind, amount_cents, currency, previous_payment_status, previous_order_status,
             status, reason, provider_error_code, provider_error_message)
            VALUES (1, 1, 'full', 10000, 'eur', 'paid', 'confirmed', 'manual', 'admin resolution', 'manual_not_refunded', 'provider result unknown'),
                   (2, 1, 'full', 10000, 'eur', 'paid', 'confirmed', 'manual', 'admin resolution', 'manual_refunded', 'operator confirmed refund'),
                   (3, 1, 'full', 10000, 'eur', 'paid', 'confirmed', 'failed', 'provider failure', 'declined', 'Stripe rejected refund')");
        $pdo->exec("INSERT INTO order_items VALUES (1, 1, 2), (2, 1, 2)");
        $pdo->exec("INSERT INTO payment_notification_outbox (order_id, notification_type, reference_id)
                    VALUES (2, 'full_refund_confirmation', 'manual-full-1')");
        $harness = new FullRefundWebhookTestHarness($pdo);
        $refund = static fn (string $id, string $status, string $paymentIntent, string $number): array => [
            'id' => $id, 'object' => 'refund', 'livemode' => false, 'status' => $status,
            'amount' => 10000, 'currency' => 'eur', 'payment_intent' => $paymentIntent,
            'metadata' => ['order_number' => $number, 'refund_attempt' => '1'],
        ];

        withEnvironment(['APP_ENV' => 'development', 'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_STRIPE_TEST], static function () use ($pdo, $harness, $refund): void {
            // An out-of-order pending event must not undo the admin decision.
            $harness->apply($refund('re_fulllate1', 'pending', 'pi_full_late_1', 'FULL-LATE-1'));
            assertSameValue('manual', $pdo->query('SELECT status FROM payment_refund_attempts WHERE order_id = 1')->fetchColumn());
            assertSameValue('paid', $pdo->query('SELECT payment_status FROM orders WHERE id = 1')->fetchColumn());

            // A later provider success becomes authoritative, cancels/restocks, notifies once and is replay-safe.
            $lateSuccess = $refund('re_fulllate1', 'succeeded', 'pi_full_late_1', 'FULL-LATE-1');
            $harness->apply($lateSuccess);
            $harness->apply($lateSuccess);
            $order = $pdo->query('SELECT status, payment_status, payment_refunded_cents FROM orders WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            assertSameValue('cancelled', $order['status']);
            assertSameValue('refunded', $order['payment_status']);
            assertSameValue(10000, (int) $order['payment_refunded_cents']);
            assertSameValue('succeeded', $pdo->query('SELECT status FROM payment_refund_attempts WHERE order_id = 1')->fetchColumn());
            assertSameValue(4, (int) $pdo->query('SELECT stock FROM products WHERE id = 1')->fetchColumn());
            assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM payment_notification_outbox WHERE order_id = 1 AND notification_type = 'full_refund_confirmation'")->fetchColumn());

            // If the admin already confirmed and counted the full refund, Stripe's late event only reconciles the ledger.
            $harness->apply($refund('re_fullmanual2', 'succeeded', 'pi_full_manual_2', 'FULL-MANUAL-2'));
            $harness->apply($refund('re_fullmanual2', 'succeeded', 'pi_full_manual_2', 'FULL-MANUAL-2'));
            assertSameValue(10000, (int) $pdo->query('SELECT payment_refunded_cents FROM orders WHERE id = 2')->fetchColumn());
            assertSameValue(4, (int) $pdo->query('SELECT stock FROM products WHERE id = 1')->fetchColumn());
            assertSameValue(1, (int) $pdo->query("SELECT COUNT(*) FROM payment_notification_outbox WHERE order_id = 2 AND notification_type = 'full_refund_confirmation'")->fetchColumn());

            // A stale pending event must not reopen an attempt already marked as definitively failed.
            $harness->apply($refund('re_stalefail3', 'pending', 'pi_full_stale_3', 'FULL-STALE-3'));
            assertSameValue('failed', $pdo->query('SELECT status FROM payment_refund_attempts WHERE order_id = 3')->fetchColumn());
            assertSameValue('paid', $pdo->query('SELECT payment_status FROM orders WHERE id = 3')->fetchColumn());
            assertSameValue('definitive_failure', $pdo->query('SELECT payment_refund_outcome FROM orders WHERE id = 3')->fetchColumn());

            // Starting a new full refund and resolving an old untracked attempt both create a typed ledger row.
            $startOrder = $pdo->query('SELECT * FROM orders WHERE id = 4')->fetch(PDO::FETCH_ASSOC);
            $started = $harness->startFullAttempt($startOrder, 41);
            assertSameValue(1, $started['attempt']);
            assertSameValue('refund_pending', $pdo->query('SELECT payment_status FROM orders WHERE id = 4')->fetchColumn());
            $startedAttempt = $pdo->query('SELECT attempt_kind, amount_cents, currency, status FROM payment_refund_attempts WHERE order_id = 4')->fetch(PDO::FETCH_ASSOC);
            assertSameValue('full', $startedAttempt['attempt_kind']);
            assertSameValue(10000, (int) $startedAttempt['amount_cents']);
            assertSameValue('eur', $startedAttempt['currency']);
            assertSameValue('pending', $startedAttempt['status']);

            $legacyOrder = $pdo->query('SELECT * FROM orders WHERE id = 5')->fetch(PDO::FETCH_ASSOC);
            $harness->resolveFullAttempt($legacyOrder, 42, 'not_refunded', 'Provider independently confirmed no refund.');
            $legacyAttempt = $pdo->query('SELECT attempt_kind, amount_cents, status, provider_error_code FROM payment_refund_attempts WHERE order_id = 5')->fetch(PDO::FETCH_ASSOC);
            assertSameValue('full', $legacyAttempt['attempt_kind']);
            assertSameValue(10000, (int) $legacyAttempt['amount_cents']);
            assertSameValue('manual', $legacyAttempt['status']);
            assertSameValue('manual_not_refunded', $legacyAttempt['provider_error_code']);
            assertSameValue('paid', $pdo->query('SELECT payment_status FROM orders WHERE id = 5')->fetchColumn());
        });
    },
    'strict allowlist rejects unknown fields' => static function (): void {
        assertHttpException(static fn () => Validation::allowedFields(['name' => 'Ana', 'role' => 'admin'], ['name']), 422, 'fields');
    },
    'text validation trims safe Unicode content' => static function (): void {
        assertSameValue('Élodie', Validation::requiredString(['name' => '  Élodie  '], 'name', 2, 20));
    },
    'text validation rejects control characters' => static function (): void {
        assertHttpException(static fn () => Validation::requiredString(['name' => "Ana\x00"], 'name', 2, 20), 422, 'name');
    },
    'password policy accepts a strong password' => static function (): void {
        assertSameValue('Strong-pass1!', Validation::password(['password' => 'Strong-pass1!']));
    },
    'password policy rejects weak passwords' => static function (): void {
        assertHttpException(static fn () => Validation::password(['password' => 'password']), 422, 'password');
    },
    'cart input merges duplicate products and sorts ids' => static function (): void {
        $quantities = CartItems::quantities([
            ['productId' => 8, 'quantity' => 2],
            ['productId' => 3, 'quantity' => 1],
            ['productId' => 8, 'quantity' => 3],
        ]);
        assertSameValue([3 => 1, 8 => 5], $quantities);
    },
    'cart input rejects numeric strings' => static function (): void {
        assertHttpException(static fn () => CartItems::quantities([['productId' => '1', 'quantity' => 1]]), 422, 'productId');
    },
    'cart input enforces combined quantity' => static function (): void {
        assertHttpException(static fn () => CartItems::quantities([
            ['productId' => 1, 'quantity' => 20],
            ['productId' => 1, 'quantity' => 1],
        ]), 422);
    },
    'Belgian quote normalizes localized country name' => static function () use ($calculator): void {
        $quote = $calculator->quote('belgië', '1000', 1_750);
        assertSameValue('BE', $quote['destination']['countryCode']);
        assertSameValue('belgium', $quote['destination']['zone']);
        assertSameValue(2_000, $quote['parcel']['weightGrams']);
        assertSameValue(540, $quote['quotes'][0]['amountCents']);
        assertSameValue(3, count($quote['quotes']));
        assertSameValue('estimate', $quote['quoteType']);
        assertSameValue('bpost-public-business-online-2026-reviewed-2026-10-04-v1', $quote['source']);
        assertSameValue('2026-10-04', $quote['tariffReviewedAt']);
        assertSameValue('2026-12-31', $quote['tariffValidThrough']);
        assertTrueValue(str_contains($quote['tariffBasis'], 'exclude VAT'));
        assertSameValue(false, $quote['liveCarrierQuote']);
        assertSameValue(false, $quote['contractualRate']);
        assertSameValue(false, $quote['labelCreationAvailable']);
        assertSameValue(false, $quote['trackingAvailable']);
        assertSameValue(false, array_key_exists('tracked', $quote['quotes'][0]));
    },
    'Belgian quote advances at the weight boundary' => static function () use ($calculator): void {
        $quote = $calculator->quote('BE', '1000', 1_751);
        assertSameValue(560, $quote['quotes'][0]['amountCents']);
    },
    'Belgian business schedule uses the published 20 kg and 30 kg bands' => static function () use ($calculator): void {
        $twentyKg = $calculator->quote('BE', '1000', 19_750);
        assertSameValue([839, 1_107, 1_262], array_column($twentyKg['quotes'], 'amountCents'));
        $thirtyKg = $calculator->quote('BE', '1000', 29_750);
        assertSameValue([1_058, 1_405, 1_560], array_column($thirtyKg['quotes'], 'amountCents'));
    },
    'expired public tariff blocks new delivery quotes pending review' => static function (): void {
        $expired = new ShippingTariffCalculator(new DateTimeImmutable('2027-01-01T00:00:00Z'));
        try {
            $expired->quote('BE', '1000', 1_000);
            throw new RuntimeException('An expired public tariff must not be quoted.');
        } catch (HttpException $exception) {
            assertSameValue(503, $exception->status);
            assertSameValue('shipping_tariff_review_required', $exception->errorCode);
        }
    },
    'neighbour quote normalizes Dutch postal code' => static function () use ($calculator): void {
        $quote = $calculator->quote('Netherlands', '1234 ab', 2_000);
        assertSameValue('NL', $quote['destination']['countryCode']);
        assertSameValue('1234 AB', $quote['destination']['postalCode']);
        assertSameValue('neighbour', $quote['destination']['zone']);
        assertSameValue(1_650, $quote['quotes'][0]['amountCents']);
    },
    'other EU destinations use the EU band' => static function () use ($calculator): void {
        $quote = $calculator->quote('SE', '114 55', 5_000);
        assertSameValue('eu', $quote['destination']['zone']);
        assertSameValue(5_270, $quote['quotes'][0]['amountCents']);
    },
    'postal validation is country aware' => static function () use ($calculator): void {
        assertHttpException(static fn () => $calculator->quote('BE', 'ABCDE', 1_000), 422, 'postalCode');
    },
    'unsupported destinations are rejected' => static function () use ($calculator): void {
        assertHttpException(static fn () => $calculator->quote('GB', 'SW1A 1AA', 1_000), 422, 'country');
    },
    'parcel weight includes packaging and enforces the carrier limit' => static function () use ($calculator): void {
        $quote = $calculator->quote('BE', '1000', 29_750);
        assertSameValue(30_000, $quote['parcel']['weightGrams']);
        assertHttpException(static fn () => $calculator->quote('BE', '1000', 29_751), 422, 'items');
    },
    'selected delivery must belong to the fresh quote' => static function () use ($calculator): void {
        $response = $calculator->quote('BE', '1000', 1_000);
        assertSameValue('bpost_home', $calculator->selectedQuote($response, 'bpost_home')['id']);
        assertHttpException(static fn () => $calculator->selectedQuote($response, 'forged-rate'), 422, 'delivery');
    },
    'proxy headers are trusted only for configured peers' => static function (): void {
        putenv('TRUST_PROXY_HEADERS=true');
        putenv('TRUSTED_PROXY_IPS=127.0.0.1,172.20.0.5');
        $_SERVER['REMOTE_ADDR'] = '172.20.0.5';
        assertTrueValue(TrustedProxy::headersTrusted());
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        assertSameValue(false, TrustedProxy::headersTrusted());
        putenv('TRUST_PROXY_HEADERS');
        putenv('TRUSTED_PROXY_IPS');
    },
    'trusted proxy CIDR configuration accepts exact peers and rejects malformed or universal ranges' => static function (): void {
        assertTrueValue(TrustedProxy::configurationIsValid('127.0.0.1, 2001:db8::5/128'));
        assertTrueValue(TrustedProxy::configurationIsValid('172.20.16.0/24'));
        assertSameValue(false, TrustedProxy::configurationIsValid(''));
        assertSameValue(false, TrustedProxy::configurationIsValid('203.0.113.4,not-an-ip'));
        assertSameValue(false, TrustedProxy::configurationIsValid('0.0.0.0/0'));
        assertSameValue(false, TrustedProxy::configurationIsValid('::/0'));
        assertSameValue(false, TrustedProxy::configurationIsValid('10.0.0.0/8'));
        assertSameValue(false, TrustedProxy::configurationIsValid('172.16.0.0/12'));
        assertSameValue(false, TrustedProxy::configurationIsValid('192.168.0.0/16'));
        assertSameValue(false, TrustedProxy::configurationIsValid('2001:db8::/64'));
        assertSameValue(false, TrustedProxy::configurationIsValid('127.0.0.1,'));
    },
    'trusted proxy host configuration accepts internal service names only' => static function (): void {
        assertTrueValue(TrustedProxy::hostConfigurationIsValid('frontend,edge-proxy'));
        assertSameValue(false, TrustedProxy::hostConfigurationIsValid(''));
        assertSameValue(false, TrustedProxy::hostConfigurationIsValid('*.example.com'));
        assertSameValue(false, TrustedProxy::hostConfigurationIsValid('frontend.example.com'));
        assertSameValue(false, TrustedProxy::hostConfigurationIsValid('127.0.0.1'));
    },
    'proxy trust defaults to loopback and does not trust a private network broadly' => static function (): void {
        $originalServer = $_SERVER;
        try {
            withEnvironment(['TRUST_PROXY_HEADERS' => 'true', 'TRUSTED_PROXY_IPS' => null], static function (): void {
                $_SERVER['REMOTE_ADDR'] = '172.20.0.5';
                $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2';
                assertSameValue(false, TrustedProxy::headersTrusted());
                assertSameValue('172.20.0.5', Request::ip());

                $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
                assertSameValue(true, TrustedProxy::headersTrusted());
            });
        } finally {
            $_SERVER = $originalServer;
        }
    },
    'client IP walks trusted proxy chain from right and ignores spoofed prefixes' => static function (): void {
        $originalServer = $_SERVER;
        try {
            withEnvironment([
                'TRUST_PROXY_HEADERS' => 'true',
                'TRUSTED_PROXY_IPS' => '172.20.0.5,10.2.0.3',
            ], static function (): void {
                $_SERVER['REMOTE_ADDR'] = '172.20.0.5';
                $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99, 203.0.113.7, 10.2.0.3';
                assertSameValue('203.0.113.7', Request::ip(), 'Resolve the nearest untrusted client, not the final proxy hop or a forged prefix.');

                $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99, 203.0.113.8';
                assertSameValue('203.0.113.8', Request::ip(), 'Ignore an attacker-controlled prefix before the proxy-appended client address.');

                $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.8, not-an-ip';
                assertSameValue('172.20.0.5', Request::ip(), 'Malformed nearest hop falls back to the trusted transport peer.');

                $_SERVER['HTTP_X_FORWARDED_FOR'] = implode(',', array_fill(0, 33, '203.0.113.8'));
                assertSameValue('172.20.0.5', Request::ip(), 'Overlong proxy chains fail closed to the transport peer.');

                $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
                $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2';
                assertSameValue('203.0.113.9', Request::ip(), 'Untrusted peers cannot choose their own rate-limit identity.');
            });
        } finally {
            $_SERVER = $originalServer;
        }
    },
    'public catalog requests cannot replace the authentication session cookie' => static function (): void {
        foreach (['/api', '/api/health', '/api/products', '/api/sellers', '/api/auth/demo-accounts'] as $path) {
            assertSameValue(false, SessionManager::requiredForRequest('GET', $path));
        }
        assertSameValue(false, SessionManager::requiredForRequest('POST', '/api/payments/webhook'));
        foreach (['/api/auth/session', '/api/orders', '/api/admin/orders'] as $path) {
            assertSameValue(true, SessionManager::requiredForRequest('GET', $path));
        }
        $originalCookies = $_COOKIE;
        try {
            $_COOKIE = [];
            assertSameValue(false, SessionManager::requiredForRequest('GET', '/api/product-reviews'));
            $_COOKIE = ['botanical_session' => 'existing-session', '__Host-botanical_session' => 'existing-session'];
            assertSameValue(true, SessionManager::requiredForRequest('GET', '/api/product-reviews'));
        } finally {
            $_COOKIE = $originalCookies;
        }
        foreach (['/api/auth/demo-login', '/api/auth/login', '/api/shipping/quotes', '/api/orders'] as $path) {
            assertSameValue(true, SessionManager::requiredForRequest('POST', $path));
        }
    },
    'CSRF comparison rejects a different token' => static function (): void {
        $_SESSION = ['csrf_token' => str_repeat('a', 64)];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('b', 64);
        assertHttpException(static fn () => Csrf::assertValid(), 403);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('a', 64);
        Csrf::assertValid();
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    },
    'payment test mode is restricted to explicit development configuration' => static function (): void {
        withEnvironment(['PAYMENT_EXECUTION_MODE' => null, 'APP_ENV' => 'production', 'PAYMENT_TEST_MODE' => 'true'], static function (): void {
            assertSameValue(false, PaymentPolicy::testModeEnabled());
            putenv('APP_ENV=development');
            assertSameValue(true, PaymentPolicy::testModeEnabled());
            putenv('PAYMENT_TEST_MODE=false');
            assertSameValue(false, PaymentPolicy::testModeEnabled());
        });
    },
    'local simulation and Stripe test integration are distinct payment modes' => static function (): void {
        withEnvironment([
            'APP_ENV' => 'development',
            'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_LOCAL_SIMULATION,
            'PAYMENT_TEST_MODE' => 'false',
            'PAYMENT_TEST_PRODUCT_SLUG' => 'monstera-essencial',
        ], static function (): void {
            assertSameValue(true, PaymentPolicy::localSimulationEnabled());
            assertSameValue(1, PaymentPolicy::unitCents('monstera-essencial', 1599));
            PaymentPolicy::assertOrderAmountAllowed('card', 'EUR', 1);
        });

        withEnvironment([
            'APP_ENV' => 'development',
            'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_STRIPE_TEST,
            'PAYMENT_TEST_MODE' => 'true',
            'PAYMENT_TEST_PRODUCT_SLUG' => 'monstera-essencial',
        ], static function (): void {
            assertSameValue(false, PaymentPolicy::localSimulationEnabled());
            assertSameValue(1599, PaymentPolicy::unitCents('monstera-essencial', 1599));
            assertHttpException(static fn () => PaymentPolicy::executionMode(PaymentPolicy::MODE_LOCAL_SIMULATION, 'staging'), 503);
            assertHttpException(static fn () => PaymentPolicy::executionMode(PaymentPolicy::MODE_STRIPE_LIVE, 'development'), 503);
        });
    },
    'Stripe keys must match an explicit application environment' => static function (): void {
        assertSameValue(false, PaymentPolicy::expectedStripeLivemode('development'));
        assertSameValue(false, PaymentPolicy::expectedStripeLivemode('test'));
        assertSameValue(false, PaymentPolicy::expectedStripeLivemode('staging'));
        assertSameValue(true, PaymentPolicy::expectedStripeLivemode('production'));
        PaymentPolicy::assertStripeKeyMatchesEnvironment('sk_test_placeholder', 'staging');
        PaymentPolicy::assertStripeKeyMatchesEnvironment('sk_live_placeholder', 'production');
        assertHttpException(static fn () => PaymentPolicy::assertStripeKeyMatchesEnvironment('sk_live_placeholder', 'staging'), 503);
        assertHttpException(static fn () => PaymentPolicy::assertStripeKeyMatchesEnvironment('sk_test_placeholder', 'production'), 503);
        assertHttpException(static fn () => PaymentPolicy::expectedStripeLivemode('preview'), 503);
    },
    'Stripe Checkout retries reuse stable request expiry only inside a conservative idempotency window' => static function (): void {
        $attemptedAt = '2026-10-04 11:00:00';
        $order = ['payment_session_attempted_at' => $attemptedAt];
        $attemptTime = strtotime($attemptedAt . ' UTC');
        assertTrueValue(is_int($attemptTime));
        assertSameValue(true, PaymentPolicy::canSafelyReplayCheckoutCreation($order, $attemptTime + 22 * 3600));
        assertSameValue(false, PaymentPolicy::canSafelyReplayCheckoutCreation($order, $attemptTime + 23 * 3600));
        assertSameValue(false, PaymentPolicy::canSafelyReplayCheckoutCreation($order, $attemptTime - 1));
        assertSameValue(false, PaymentPolicy::canSafelyReplayCheckoutCreation(['payment_session_attempted_at' => null], $attemptTime));
        assertSameValue(false, PaymentPolicy::requiresManualCheckoutReconciliation($order, $attemptTime + 22 * 3600));
        assertSameValue(true, PaymentPolicy::requiresManualCheckoutReconciliation($order, $attemptTime + 23 * 3600));
        assertSameValue(true, PaymentPolicy::requiresManualCheckoutReconciliation(
            ['payment_session_attempted_at' => null, 'created_at' => $attemptedAt],
            $attemptTime + 23 * 3600
        ));
        assertSameValue($attemptTime + 55 * 60, StripeGateway::checkoutExpiryForAttempt($order));
        assertSameValue(
            StripeGateway::checkoutExpiryForAttempt($order),
            StripeGateway::checkoutExpiryForAttempt(['payment_session_attempted_at' => $attemptedAt])
        );
    },
    'Stripe livemode must match both environment and order test marker' => static function (): void {
        assertSameValue(true, PaymentPolicy::stripeObjectMatchesOrder(['livemode' => false], ['test_payment' => 1], 'staging'));
        assertSameValue(false, PaymentPolicy::stripeObjectMatchesOrder(['livemode' => true], ['test_payment' => 0], 'staging'));
        assertSameValue(false, PaymentPolicy::stripeObjectMatchesOrder(['livemode' => false], ['test_payment' => 0], 'staging'));
        assertSameValue(true, PaymentPolicy::stripeObjectMatchesOrder(['livemode' => true], ['test_payment' => 0], 'production'));
        assertSameValue(false, PaymentPolicy::stripeObjectMatchesOrder([], ['test_payment' => 1], 'staging'));
        assertSameValue(true, PaymentPolicy::orderPaymentModeMatchesEnvironment(['test_payment' => 1], 'staging'));
        assertSameValue(false, PaymentPolicy::orderPaymentModeMatchesEnvironment(['test_payment' => 0], 'staging'));
        assertSameValue(true, PaymentPolicy::orderPaymentModeMatchesEnvironment(['test_payment' => 0], 'production'));
    },
    'Stripe refund responses must match the exact order attempt, amount, currency and mode' => static function (): void {
        withEnvironment(['APP_ENV' => 'staging'], static function (): void {
            $order = [
                'order_number' => 'BE-20261004-ABCDEF0123',
                'payment_provider' => 'stripe',
                'payment_intent_id' => 'pi_test123',
                'payment_refund_attempt' => 4,
                'payment_refunded_cents' => 2500,
                'total' => '100.00',
                'currency' => 'EUR',
                'test_payment' => 1,
            ];
            $refund = [
                'id' => 're_test123',
                'livemode' => false,
                'payment_intent' => 'pi_test123',
                'amount' => 7500,
                'currency' => 'eur',
                'metadata' => ['order_number' => $order['order_number'], 'refund_attempt' => '4'],
            ];

            PaymentPolicy::assertStripeRefundMatchesAttempt($refund, $order, 4, 7500);
            foreach ([
                [...$refund, 'amount' => 7000],
                [...$refund, 'currency' => 'usd'],
                [...$refund, 'payment_intent' => 'pi_other'],
                [...$refund, 'livemode' => true],
                [...$refund, 'metadata' => ['order_number' => $order['order_number'], 'refund_attempt' => '3']],
                [...$refund, 'metadata' => ['order_number' => 'BE-OTHER', 'refund_attempt' => '4']],
                [...$refund, 'metadata' => ['order_number' => $order['order_number']]],
                [...$refund, 'id' => 'not-a-refund-id'],
            ] as $unmatchedRefund) {
                try {
                    PaymentPolicy::assertStripeRefundMatchesAttempt($unmatchedRefund, $order, 4, 7500);
                } catch (HttpException $exception) {
                    assertSameValue(409, $exception->status);
                    continue;
                }
                throw new RuntimeException('A mismatched Stripe refund response must never be accepted.');
            }
        });
    },
    'one-cent product override applies only to the configured test product' => static function (): void {
        $oldEnvironment = getenv('APP_ENV');
        $oldTestMode = getenv('PAYMENT_TEST_MODE');
        $oldSlug = getenv('PAYMENT_TEST_PRODUCT_SLUG');
        putenv('APP_ENV=development');
        putenv('PAYMENT_TEST_MODE=true');
        putenv('PAYMENT_TEST_PRODUCT_SLUG=monstera-essencial');
        assertSameValue(1, PaymentPolicy::unitCents('monstera-essencial', 1599));
        assertSameValue(1599, PaymentPolicy::unitCents('ficus-lyrata', 1599));
        $oldEnvironment === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $oldEnvironment);
        $oldTestMode === false ? putenv('PAYMENT_TEST_MODE') : putenv('PAYMENT_TEST_MODE=' . $oldTestMode);
        $oldSlug === false ? putenv('PAYMENT_TEST_PRODUCT_SLUG') : putenv('PAYMENT_TEST_PRODUCT_SLUG=' . $oldSlug);
    },
    'free test shipping cannot alter production rates' => static function () use ($calculator): void {
        $quote = $calculator->quote('BE', '1000', 1_000);
        withEnvironment([
            'APP_ENV' => 'production',
            'PAYMENT_EXECUTION_MODE' => null,
            'PAYMENT_TEST_MODE' => 'true',
        ], static function () use ($quote): void {
            assertSameValue($quote['quotes'][0]['amountCents'], PaymentPolicy::freeTestShipping($quote)['quotes'][0]['amountCents']);
        });
        withEnvironment([
            'APP_ENV' => 'development',
            'PAYMENT_EXECUTION_MODE' => null,
            'PAYMENT_TEST_MODE' => 'true',
        ], static function () use ($quote): void {
            assertSameValue(0, PaymentPolicy::freeTestShipping($quote)['quotes'][0]['amountCents']);
        });
    },
    'checkout URLs require an active order attached to the exact Stripe session' => static function (): void {
        $order = [
            'status' => 'awaiting_payment',
            'payment_status' => 'pending',
            'payment_session_id' => 'cs_test_expected',
        ];
        assertSameValue(true, PaymentPolicy::checkoutSessionIsActive($order, 'cs_test_expected'));
        assertSameValue(false, PaymentPolicy::checkoutSessionIsActive($order, 'cs_test_other'));
        assertSameValue(false, PaymentPolicy::checkoutSessionIsActive([...$order, 'status' => 'cancelled'], 'cs_test_expected'));
        assertSameValue(false, PaymentPolicy::checkoutSessionIsActive([...$order, 'payment_status' => 'paid'], 'cs_test_expected'));
        assertSameValue(false, PaymentPolicy::checkoutSessionIsActive([...$order, 'payment_session_id' => null], 'cs_test_expected'));
    },
    'Stripe limits are bound to the account, settlement currency, method and presentment currency' => static function (): void {
        $configuration = json_encode([
            'settlementCurrency' => 'EUR',
            'methods' => [
                'card' => [
                    'EUR' => ['minCents' => 50, 'maxCents' => 99_999_999],
                    'USD' => ['minCents' => 100, 'maxCents' => 99_999_999],
                ],
                'bancontact' => ['EUR' => ['minCents' => 50, 'maxCents' => 1_000_000]],
                'sepa_debit' => ['EUR' => ['minCents' => 50, 'maxCents' => 99_999_999]],
            ],
        ], JSON_THROW_ON_ERROR);
        withEnvironment([
            'APP_ENV' => 'development',
            'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_STRIPE_TEST,
            'STRIPE_ACCOUNT_ID' => 'acct_123Test',
            'STRIPE_SETTLEMENT_CURRENCY' => 'EUR',
            'STRIPE_PAYMENT_LIMITS_JSON' => $configuration,
        ], static function () use ($configuration): void {
            assertSameValue(50, PaymentPolicy::stripePaymentLimits('card', 'EUR')['minCents']);
            assertSameValue(100, PaymentPolicy::stripePaymentLimits('card', 'USD')['minCents']);
            assertSameValue(1_000_000, PaymentPolicy::stripePaymentLimits('bancontact', 'EUR')['maxCents']);
            assertSameValue(50, PaymentPolicy::stripePaymentLimits('sepa_debit', 'EUR')['minCents']);
            PaymentPolicy::assertOrderAmountAllowed('card', 'EUR', 50);
            PaymentPolicy::assertOrderAmountAllowed('bancontact', 'EUR', 1_000_000);
            PaymentPolicy::assertOrderAmountAllowed('sepa_debit', 'EUR', 50);
            assertHttpException(static fn () => PaymentPolicy::assertOrderAmountAllowed('card', 'EUR', 1), 422, 'amountCents');
            assertHttpException(static fn () => PaymentPolicy::assertOrderAmountAllowed('bancontact', 'EUR', 1_000_001), 422);
            assertHttpException(static fn () => PaymentPolicy::stripePaymentLimits('bancontact', 'USD'), 422, 'payment');
            assertHttpException(static fn () => PaymentPolicy::stripePaymentLimits('sepa_debit', 'USD'), 422, 'payment');

            $limits = PaymentPolicy::stripePaymentLimits('card', 'EUR');
            PaymentPolicy::assertStripeAccountMatchesConfiguration([
                'id' => 'acct_123Test',
                'default_currency' => 'eur',
                'capabilities' => ['card_payments' => 'active'],
            ], $limits);
            $sepaLimits = PaymentPolicy::stripePaymentLimits('sepa_debit', 'EUR');
            PaymentPolicy::assertStripeAccountMatchesConfiguration([
                'id' => 'acct_123Test',
                'default_currency' => 'eur',
                'capabilities' => ['sepa_debit_payments' => 'active'],
            ], $sepaLimits);
            assertHttpException(static fn () => PaymentPolicy::assertStripeAccountMatchesConfiguration([
                'id' => 'acct_123Test',
                'default_currency' => 'eur',
                'capabilities' => ['sepa_debit_payments' => 'inactive'],
            ], $sepaLimits), 503);
            assertHttpException(static fn () => PaymentPolicy::assertStripeAccountMatchesConfiguration([
                'id' => 'acctAnotherAccount',
                'default_currency' => 'eur',
            ], $limits), 503);
            assertHttpException(static fn () => PaymentPolicy::assertStripeAccountMatchesConfiguration([
                'id' => 'acct_123Test',
                'default_currency' => 'usd',
            ], $limits), 503);
        });

        withEnvironment([
            'STRIPE_ACCOUNT_ID' => 'acct_123Test',
            'STRIPE_SETTLEMENT_CURRENCY' => 'USD',
            'STRIPE_PAYMENT_LIMITS_JSON' => $configuration,
        ], static function () use ($configuration): void {
            assertHttpException(static fn () => PaymentPolicy::stripePaymentLimits('card', 'EUR'), 503);
        });

        withEnvironment([
            'APP_ENV' => 'development',
            'PAYMENT_EXECUTION_MODE' => PaymentPolicy::MODE_STRIPE_TEST,
            'STRIPE_ACCOUNT_ID' => 'acct_123Test',
            'STRIPE_SETTLEMENT_CURRENCY' => 'EUR',
            'STRIPE_PAYMENT_LIMITS_JSON' => '',
        ], static function (): void {
            assertHttpException(static fn () => PaymentPolicy::stripePaymentLimits('card', 'EUR'), 503);
        });
    },
    'webhook timestamps and Stripe signatures are enforced' => static function (): void {
        $now = 1_800_000_000;
        $payload = '{"id":"evt_test","type":"checkout.session.completed"}';
        $signature = hash_hmac('sha256', $now . '.' . $payload, 'whsec_test');
        assertTrueValue(PaymentPolicy::validStripeSignature($payload, "t={$now},v1={$signature}", 'whsec_test', $now));
        assertSameValue(false, PaymentPolicy::validStripeSignature($payload, "t={$now},v1={$signature}", 'wrong-secret', $now));
        assertSameValue(false, PaymentPolicy::validStripeSignature($payload, "t=" . ($now - 600) . ",v1={$signature}", 'whsec_test', $now));
    },
];

$tests += require __DIR__ . '/dispute-regressions.php';
$tests += require __DIR__ . '/auth-regressions.php';

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS  {$name}\n");
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "FAIL  {$name}: {$exception->getMessage()}\n");
    }
}

$total = count($tests);
fwrite(STDOUT, "\n" . ($total - $failures) . "/{$total} backend tests passed.\n");
exit($failures === 0 ? 0 : 1);
