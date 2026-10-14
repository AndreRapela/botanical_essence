<?php

declare(strict_types=1);

set_time_limit(0);
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Http.php';
require_once dirname(__DIR__) . '/src/Security.php';
require_once dirname(__DIR__) . '/src/Validation.php';
require_once dirname(__DIR__) . '/src/CartItems.php';
require_once dirname(__DIR__) . '/src/ShippingTariffCalculator.php';
require_once dirname(__DIR__) . '/src/PaymentPolicy.php';
require_once dirname(__DIR__) . '/src/RuntimeConfiguration.php';
require_once dirname(__DIR__) . '/src/PaymentWorkerHeartbeat.php';
require_once dirname(__DIR__) . '/src/ExchangeRateService.php';
require_once dirname(__DIR__) . '/src/Mailer.php';
require_once dirname(__DIR__) . '/src/InventoryService.php';
require_once dirname(__DIR__) . '/src/ShippingService.php';
require_once dirname(__DIR__) . '/src/OrderService.php';
require_once dirname(__DIR__) . '/src/StripeGateway.php';
require_once dirname(__DIR__) . '/src/PaymentOrderLock.php';
require_once dirname(__DIR__) . '/src/PaymentNotificationPolicy.php';
require_once dirname(__DIR__) . '/src/LatePaymentRecoveryService.php';
require_once dirname(__DIR__) . '/src/LateRefundDispatchService.php';
require_once dirname(__DIR__) . '/src/CheckoutSessionService.php';
require_once dirname(__DIR__) . '/src/CheckoutRecoveryService.php';
require_once dirname(__DIR__) . '/src/CheckoutReconciliationService.php';
require_once dirname(__DIR__) . '/src/RefundStatePolicy.php';
require_once dirname(__DIR__) . '/src/RefundLedgerSafety.php';
require_once dirname(__DIR__) . '/src/RefundResolutionTarget.php';
require_once dirname(__DIR__) . '/src/RefundAuditActions.php';
require_once dirname(__DIR__) . '/src/RefundAdminActions.php';
require_once dirname(__DIR__) . '/src/WithdrawalPolicy.php';
require_once dirname(__DIR__) . '/src/WithdrawalSchema.php';
require_once dirname(__DIR__) . '/src/WithdrawalService.php';
require_once dirname(__DIR__) . '/src/PaymentOperationsService.php';
require_once dirname(__DIR__) . '/src/ExternalRefundService.php';
require_once dirname(__DIR__) . '/src/DisputeBackfillService.php';
require_once dirname(__DIR__) . '/src/UnattachedCheckoutResolver.php';
require_once dirname(__DIR__) . '/src/PartialRefundFailureActions.php';
require_once dirname(__DIR__) . '/src/PartialRefundActions.php';
require_once dirname(__DIR__) . '/src/PaymentWebhookActions.php';
require_once dirname(__DIR__) . '/src/RefundAdminResultActions.php';
require_once dirname(__DIR__) . '/src/PaymentService.php';

$workerHeartbeat = new PaymentWorkerHeartbeat(dirname(__DIR__) . '/storage/payment-worker-heartbeat');
// Remove an old healthy marker before validation so an invalid deployment
// cannot leave the healthcheck reporting a worker that never started.
$workerHeartbeat->resetAtStartup();
RuntimeConfiguration::assertSafeStartup();

function runPaymentOperationsBatch(): bool
{
    $batchCompleted = false;
    try {
        $pdo = Database::connect();
        $limiter = new RateLimiter($pdo);
        $mailer = new Mailer();
        $inventory = new InventoryService($pdo);
        $exchangeRates = new ExchangeRateService($pdo);
        $shipping = new ShippingService($pdo, $limiter);
        $orders = new OrderService($pdo, $limiter, $mailer, $inventory, $shipping, $exchangeRates);
        $withdrawals = new WithdrawalService($pdo, $limiter, $mailer);
        $payments = new PaymentService($pdo, $limiter, $orders, $inventory, new StripeGateway(), $withdrawals, $mailer);
        $releasedReservations = 0;
        $reservationCleanupSucceeded = true;
        try {
            $releasedReservations = $inventory->releaseExpiredReservations();
        } catch (Throwable $exception) {
            error_log('Expired inventory reservation cleanup failed: ' . $exception->getMessage());
            $reservationCleanupSucceeded = false;
        }
        $stats = $payments->runPaymentOperationsBatch(50);
        $stats['expiredReservationsReleased'] = $releasedReservations;
        error_log('Payment operations worker batch: ' . json_encode($stats, JSON_THROW_ON_ERROR));
        $batchCompleted = $reservationCleanupSucceeded;
    } catch (Throwable $exception) {
        error_log('Payment operations worker batch failed: ' . $exception->getMessage());
    } finally {
        Database::disconnect();
    }
    return $batchCompleted;
}

while (true) {
    // PaymentService callbacks capture the service itself, creating a reference cycle.
    // Collect it after the function scope ends so its PDO connection is released now,
    // rather than after PHP's automatic cycle-collection threshold is reached.
    $batchSucceeded = runPaymentOperationsBatch();
    if (!$workerHeartbeat->recordBatchResult($batchSucceeded) && $batchSucceeded) {
        error_log('Payment operations worker completed a batch but could not write its heartbeat.');
    }
    gc_collect_cycles();
    sleep(60);
}
