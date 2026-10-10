<?php

declare(strict_types=1);

final class RefundStatePolicy
{
    public static function canStartNewAttempt(string $paymentStatus, string $outcome): bool
    {
        return in_array($paymentStatus, ['paid', 'partially_refunded'], true)
            && in_array($outcome, ['definitive_failure', 'manual_not_refunded'], true);
    }

    /** A terminal refund decision cannot erase amounts confirmed by other attempts. */
    public static function settledPaymentStatus(int $refundedCents, int $totalCents): string
    {
        if ($totalCents < 1 || $refundedCents < 0 || $refundedCents > $totalCents) {
            throw new HttpException('O saldo de reembolso exige conciliação antes desta ação.', 409, [], 'refund_balance_invalid');
        }
        return $refundedCents === $totalCents ? 'refunded' : ($refundedCents > 0 ? 'partially_refunded' : 'paid');
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

    /** A history replay is not a new financial transition or a new failure audit. */
    public static function isRepeatedFailure(array $attempt, array $refund, string $defaultCode): bool
    {
        $status = $refund['status'] ?? '';
        return $attempt['status'] === 'failed' && $attempt['stripe_refund_id'] === ($refund['id'] ?? null)
            && in_array($status, ['failed', 'canceled'], true)
            && $attempt['provider_error_code'] === substr((string) ($refund['failure_reason'] ?? $defaultCode), 0, 100)
            && $attempt['provider_error_message'] === 'Stripe refund status: ' . $status;
    }
}
