<?php

declare(strict_types=1);

$source = dirname(__DIR__) . '/src';
require_once $source . '/Database.php';
require_once $source . '/Http.php';
require_once $source . '/Security.php';
require_once $source . '/Validation.php';
require_once $source . '/CartItems.php';
require_once $source . '/ShippingTariffCalculator.php';
require_once $source . '/PaymentPolicy.php';
require_once $source . '/RuntimeConfiguration.php';
require_once $source . '/ExchangeRateService.php';
require_once $source . '/Mailer.php';
require_once $source . '/AdminNotificationService.php';
require_once $source . '/AuthService.php';
require_once $source . '/AccountService.php';
require_once $source . '/InventoryService.php';
require_once $source . '/CatalogService.php';
require_once $source . '/ShippingService.php';
require_once $source . '/OrderService.php';
require_once $source . '/StripeGateway.php';
require_once $source . '/PaymentOrderLock.php';
require_once $source . '/PaymentNotificationPolicy.php';
require_once $source . '/AdminPaymentNotificationService.php';
require_once $source . '/LatePaymentRecoveryService.php';
require_once $source . '/CheckoutSessionService.php';
require_once $source . '/CheckoutRecoveryService.php';
require_once $source . '/CheckoutReconciliationService.php';
require_once $source . '/RefundStatePolicy.php';
require_once $source . '/RefundAuditActions.php';
require_once $source . '/RefundAdminActions.php';
require_once $source . '/WithdrawalPolicy.php';
require_once $source . '/WithdrawalService.php';
require_once $source . '/PaymentOperationsService.php';
require_once $source . '/UnattachedCheckoutResolver.php';
require_once $source . '/PartialRefundFailureActions.php';
require_once $source . '/PartialRefundActions.php';
require_once $source . '/PaymentWebhookActions.php';
require_once $source . '/RefundAdminResultActions.php';
require_once $source . '/PaymentService.php';
require_once $source . '/MessageService.php';
require_once $source . '/MarketplaceService.php';
require_once $source . '/AdminService.php';
require_once $source . '/ReviewService.php';

$requestId = bin2hex(random_bytes(8));
header('X-Request-ID: ' . $requestId);
SecurityHeaders::apply();
$corsAllowed = SecurityHeaders::applyCors();

if (!$corsAllowed) {
    Response::json(['error' => 'Origem não permitida.', 'requestId' => $requestId], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    $requestedMethod = strtoupper((string) (Request::header('Access-Control-Request-Method') ?? ''));
    if ($requestedMethod !== '' && !in_array($requestedMethod, ['GET', 'POST'], true)) {
        header('Allow: GET, POST, OPTIONS');
        Response::json(['error' => 'Método não permitido.', 'requestId' => $requestId], 405);
    }
    Response::noContent();
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = '/' . trim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');

try {
    // Fail closed before serving traffic when URLs, mail, demo accounts, or PSP
    // settings could expose authentication tokens or accept unsafe payments.
    RuntimeConfiguration::assertSafeStartup();
    $pdo = Database::connect();
    if ($method === 'GET' && $path === '/api/health') {
        Database::assertHealthy($pdo);
        Response::json(['status' => 'ok', 'service' => 'Botanical Essence API']);
    }

    if (SessionManager::requiredForRequest($method, $path)) {
        SessionManager::start();
    }
    $limiter = new RateLimiter($pdo);
    $inventory = new InventoryService($pdo);
    $exchangeRates = new ExchangeRateService($pdo);
    $mailer = new Mailer();
    $adminNotifications = new AdminNotificationService($pdo, $mailer);
    $auth = new AuthService($pdo, $mailer, $limiter, $adminNotifications);
    $accounts = new AccountService($pdo, $limiter);
    $catalog = new CatalogService($pdo, $exchangeRates);
    $shipping = new ShippingService($pdo, $limiter);
    $orders = new OrderService($pdo, $limiter, $mailer, $inventory, $shipping, $exchangeRates);
    $withdrawals = new WithdrawalService($pdo, $limiter, $mailer);
    $payments = new PaymentService($pdo, $limiter, $orders, $inventory, new StripeGateway(), $withdrawals, $mailer);
    $messages = new MessageService($pdo, $limiter, $adminNotifications);
    $marketplace = new MarketplaceService($pdo, $limiter, $adminNotifications);
    $admin = new AdminService($pdo, $limiter, $mailer);
    $adminPaymentNotifications = new AdminPaymentNotificationService($pdo, $limiter);
    $reviews = new ReviewService($pdo, $limiter);
    $router = new Router();

    $router->add('GET', '/api', static fn (): array => ['status' => 'ok', 'service' => 'Botanical Essence API']);
    $router->add('GET', '/api/products', static fn (): array => $catalog->products($_GET));
    $router->add('GET', '/api/sellers', static fn (): array => $catalog->sellers($_GET));
    $router->add('GET', '/api/product-reviews', static fn (): array => $reviews->list($_GET, $auth->currentUser()));
    $router->add('POST', '/api/product-reviews', static fn (): array => $reviews->save(Request::json(), $auth->requireUser()));
    $router->add('POST', '/api/shipping/quotes', static fn (): array => $shipping->quote(Request::json()));

    $router->add('GET', '/api/auth/session', static fn (): array => [
        'user' => $auth->currentUser(),
        'csrfToken' => Csrf::token(),
    ]);
    $router->add('GET', '/api/auth/demo-accounts', static fn (): array => $auth->demoAccounts());
    $router->add('POST', '/api/auth/login', static function () use ($auth): array {
        $user = $auth->login(Request::json());
        return ['message' => 'Login realizado.', 'user' => $user, 'csrfToken' => Csrf::token()];
    });
    $router->add('POST', '/api/auth/demo-login', static function () use ($auth): array {
        $user = $auth->demoLogin(Request::json());
        return ['message' => 'Acesso de demonstração iniciado.', 'user' => $user, 'csrfToken' => Csrf::token()];
    });
    $router->add('POST', '/api/auth/logout', static function (): array {
        SessionManager::logout();
        return ['message' => 'Sessão encerrada.', 'csrfToken' => Csrf::token()];
    });
    $router->add('POST', '/api/auth/register', static function () use ($auth): array {
        $result = $auth->register(Request::json());
        return [
            'message' => 'Se os dados puderem ser cadastrados, enviaremos a confirmação para o e-mail informado.',
            'developmentVerificationUrl' => $result['developmentVerificationUrl'] ?? null,
            '_status' => 202,
        ];
    });
    $router->add('POST', '/api/auth/verify-email', static function () use ($auth): array {
        $auth->verifyEmail(Request::json());
        return ['message' => 'E-mail confirmado. Agora você pode entrar.'];
    });
    $router->add('POST', '/api/auth/forgot-password', static function () use ($auth): array {
        $result = $auth->requestPasswordReset(Request::json());
        return [
            'message' => 'Se o e-mail estiver cadastrado, enviaremos as instruções de recuperação.',
            'developmentResetUrl' => $result['developmentResetUrl'] ?? null,
        ];
    });
    $router->add('POST', '/api/auth/reset-password', static function () use ($auth): array {
        $auth->resetPassword(Request::json());
        return ['message' => 'Senha redefinida.'];
    });

    $router->add('POST', '/api/orders', static fn (): array => $payments->startCheckout(Request::json(), $auth->requireUser()));
    $router->add('GET', '/api/orders', static fn (): array => $orders->listForUser($auth->requireUser(), $_GET));
    $router->add('POST', '/api/orders/withdrawal', static fn (): array => $withdrawals->submit(Request::json(), $auth->requireUser()));
    $router->add('POST', '/api/payments/session-status', static fn (): array => $payments->sessionStatus(Request::json(), $auth->requireUser()));
    $router->add('POST', '/api/payments/order-status', static fn (): array => $payments->checkoutOrderStatus(Request::json(), $auth->requireUser()));
    $router->add('POST', '/api/payments/resume-checkout', static fn (): array => $payments->resumeCheckout(Request::json(), $auth->requireUser()));
    $router->add('POST', '/api/payments/cancel-checkout', static fn (): array => $payments->cancelCheckoutOrder(Request::json(), $auth->requireUser()));
    $router->add('POST', '/api/payments/webhook', static function () use ($payments): array {
        $payload = Request::rawBody(1_048_576, 'Evento de pagamento muito grande.');
        return $payments->handleWebhook($payload, Request::header('Stripe-Signature') ?? '');
    });
    $router->add('GET', '/api/account/profile', static fn (): array => $accounts->profile($auth->requireUser()));
    $router->add('POST', '/api/account/profile', static fn (): array => $accounts->updateProfile(Request::json(), $auth->requireUser()));
    $router->add('POST', '/api/newsletter', static fn (): array => $messages->subscribe(Request::json()));
    $router->add('POST', '/api/contact', static fn (): array => $messages->contact(Request::json()));

    $router->add('GET', '/api/seller/dashboard', static fn (): array => $marketplace->dashboard($auth->requireRole('customer', 'seller')));
    $router->add('POST', '/api/seller/store', static fn (): array => $marketplace->updateStore(Request::json(), $auth->requireRole('customer', 'seller')));
    $router->add('POST', '/api/seller/products', static fn (): array => $marketplace->saveProduct(Request::json(), $auth->requireRole('customer', 'seller')));
    $router->add('POST', '/api/seller/products/archive', static fn (): array => $marketplace->archiveProduct(Request::json(), $auth->requireRole('customer', 'seller')));
    $router->add('GET', '/api/admin/sellers', static function () use ($marketplace, $auth): array {
        $auth->requireRole('admin');
        return $marketplace->adminSellers($_GET);
    });
    $router->add('POST', '/api/admin/sellers/verification', static fn (): array => $marketplace->setSellerVerification(Request::json(), $auth->requireRole('admin')));
    $router->add('GET', '/api/admin/overview', static function () use ($admin, $auth, $reviews): array {
        $auth->requireRole('admin');
        $query = $_GET;
        $overview = $admin->overview($query);
        $reviewPage = $reviews->adminReviews($query);
        return [
            ...$overview,
            'reviews' => $reviewPage['data'],
            'reviewsTotal' => $reviewPage['total'],
            'reviewsHasMore' => $reviewPage['hasMore'],
        ];
    });
    $router->add('GET', '/api/admin/orders', static function () use ($payments, $auth): array {
        $auth->requireRole('admin');
        return $payments->adminOrders($_GET);
    });
    $router->add('GET', '/api/admin/audit-log', static function () use ($admin, $auth): array {
        $auth->requireRole('admin');
        return $admin->auditLog($_GET);
    });
    $router->add('GET', '/api/admin/payment-disputes', static function () use ($payments, $auth): array {
        $auth->requireRole('admin');
        return $payments->adminDisputes($_GET);
    });
    $router->add('GET', '/api/admin/payment-notifications', static function () use ($adminPaymentNotifications, $auth): array {
        $auth->requireRole('admin');
        return $adminPaymentNotifications->issues($_GET);
    });
    $router->add('POST', '/api/admin/payment-notifications/retry', static fn (): array => $adminPaymentNotifications->retry(Request::json(), $auth->requireRole('admin')));
    $router->add('POST', '/api/admin/orders/refund', static fn (): array => $payments->partialRefundForAdmin(Request::json(), $auth->requireRole('admin')));
    $router->add('POST', '/api/admin/orders/cancel', static fn (): array => $payments->cancelForAdmin(Request::json(), $auth->requireRole('admin')));
    $router->add('GET', '/api/admin/withdrawals', static function () use ($withdrawals, $auth): array {
        $auth->requireRole('admin');
        return $withdrawals->adminList($_GET);
    });
    $router->add('POST', '/api/admin/withdrawals/action', static fn (): array => $payments->adminWithdrawalAction(Request::json(), $auth->requireRole('admin')));
    $router->add('POST', '/api/admin/users', static fn (): array => $admin->updateUser(Request::json(), $auth->requireRole('admin')));
    $router->add('POST', '/api/admin/products', static fn (): array => $admin->updateProduct(Request::json(), $auth->requireRole('admin')));
    $router->add('POST', '/api/admin/stores', static fn (): array => $admin->updateStore(Request::json(), $auth->requireRole('admin')));
    $router->add('POST', '/api/admin/reviews/moderate', static fn (): array => $reviews->moderate(Request::json(), $auth->requireRole('admin')));
    $router->add('GET', '/api/admin/contact-messages', static fn (): array => $messages->adminContactMessages($_GET, $auth->requireRole('admin')));
    $router->add('POST', '/api/admin/contact-messages/status', static fn (): array => $messages->updateContactMessage(Request::json(), $auth->requireRole('admin')));

    if ($method === 'POST' && $path !== '/api/payments/webhook') {
        Csrf::assertValid();
    }
    $router->dispatch($method, $path);
} catch (HttpException $exception) {
    $payload = ['error' => $exception->getMessage(), 'requestId' => $requestId];
    if ($exception->details !== []) {
        $payload['details'] = $exception->details;
    }
    if ($exception->errorCode !== null) {
        $payload['code'] = $exception->errorCode;
    }
    Response::json($payload, $exception->status);
} catch (PDOException $exception) {
    error_log("[{$requestId}] Database error {$exception->getCode()}: {$exception->getMessage()}");
    $status = (int) $exception->getCode() === 23000 ? 409 : 503;
    Response::json([
        'error' => $status === 409 ? 'Este registro já existe.' : 'Serviço temporariamente indisponível.',
        'requestId' => $requestId,
    ], $status);
} catch (Throwable $exception) {
    error_log("[{$requestId}] " . get_class($exception) . ": {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}");
    Response::json(['error' => 'Erro interno da aplicação.', 'requestId' => $requestId], 500);
}
