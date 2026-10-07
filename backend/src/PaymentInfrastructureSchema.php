<?php

declare(strict_types=1);

require_once __DIR__ . '/RefundRecoverySchema.php';
require_once __DIR__ . '/CheckoutPreparationSchema.php';
require_once __DIR__ . '/CheckoutIdempotencySchema.php';
require_once __DIR__ . '/WithdrawalSchema.php';
require_once __DIR__ . '/PaymentOperationsSchema.php';
require_once __DIR__ . '/DisputeBackfillSchema.php';
require_once __DIR__ . '/ExternalRefundSchema.php';
require_once __DIR__ . '/LateRefundDispatchSchema.php';

/** Ordered payment-storage upgrades, shared by fresh and already-initialized databases. */
final class PaymentInfrastructureSchema
{
    public static function migrate(PDO $pdo): void
    {
        RefundRecoverySchema::migrate($pdo);
        CheckoutPreparationSchema::migrate($pdo);
        CheckoutIdempotencySchema::migrate($pdo);
        WithdrawalSchema::migrate($pdo);
        PaymentOperationsSchema::migrate($pdo);
        DisputeBackfillSchema::migrate($pdo);
        ExternalRefundSchema::migrate($pdo);
        LateRefundDispatchSchema::migrate($pdo);
    }
}
