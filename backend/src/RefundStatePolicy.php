<?php

declare(strict_types=1);

final class RefundStatePolicy
{
    public static function canStartNewAttempt(string $paymentStatus, string $outcome): bool
    {
        return $paymentStatus === 'paid'
            && in_array($outcome, ['definitive_failure', 'manual_not_refunded'], true);
    }

    public static function isUnresolved(string $paymentStatus, string $outcome): bool
    {
        return $paymentStatus === 'refund_pending'
            && in_array($outcome, ['pending', 'unknown'], true);
    }

    public static function partialRefundAmountWasAlreadyCounted(string $attemptStatus, ?string $manualResolution): bool
    {
        return $attemptStatus === 'succeeded'
            || ($attemptStatus === 'manual' && $manualResolution === 'manual_refunded');
    }
}
