<?php

declare(strict_types=1);

/** Consumer withdrawal requests are notices; product exceptions require case-by-case review. */
final class WithdrawalPolicy
{
    private const REQUESTABLE_ORDER_STATUSES = ['confirmed', 'preparing', 'shipped', 'delivered'];

    private const ACTIVE_REQUEST_STATUSES = [
        'submitted', 'reviewing', 'instructions_sent', 'return_received', 'refund_pending', 'refund_failed',
    ];

    public static function canRequest(array $order): bool
    {
        $paymentStatus = (string) ($order['payment_status'] ?? '');
        $remainingCents = max(0, (int) round((float) ($order['total'] ?? 0) * 100) - (int) ($order['refundedCents'] ?? $order['payment_refunded_cents'] ?? 0));
        return in_array($paymentStatus, ['paid', 'partially_refunded'], true)
            && $remainingCents > 0
            && in_array((string) ($order['status'] ?? ''), self::REQUESTABLE_ORDER_STATUSES, true)
            && !in_array((string) ($order['payment_refund_outcome'] ?? ''), ['pending', 'unknown'], true);
    }

    public static function isActiveStatus(string $status): bool
    {
        return in_array($status, self::ACTIVE_REQUEST_STATUSES, true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, match ($from) {
            'submitted' => ['reviewing', 'instructions_sent', 'not_eligible'],
            'reviewing' => ['instructions_sent', 'not_eligible'],
            'instructions_sent' => ['return_received', 'not_eligible'],
            'return_received' => ['refund_pending'],
            'refund_failed' => ['refund_pending', 'refunded'],
            'refund_pending' => ['refund_failed', 'refunded'],
            default => [],
        }, true);
    }

    public static function canStartRefund(string $requestStatus, array $order): bool
    {
        $paymentStatus = (string) ($order['payment_status'] ?? '');
        $remainingCents = max(0, (int) round((float) ($order['total'] ?? 0) * 100) - (int) ($order['payment_refunded_cents'] ?? 0));
        return in_array($requestStatus, ['return_received', 'refund_failed'], true)
            && in_array($paymentStatus, ['paid', 'partially_refunded'], true)
            && $remainingCents > 0
            && ($order['payment_provider'] ?? null) === 'stripe'
            && is_string($order['payment_intent_id'] ?? null)
            && $order['payment_intent_id'] !== ''
            && in_array((string) ($order['status'] ?? ''), self::REQUESTABLE_ORDER_STATUSES, true);
    }
}
