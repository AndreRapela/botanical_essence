<?php

declare(strict_types=1);

final class PaymentNotificationPolicy
{
    public const MAX_CONSECUTIVE_FAILURES = 12;

    public static function safeDiagnostic(string $message): string
    {
        $message = preg_replace('/[\\x00-\\x1F\\x7F]+/u', ' ', trim($message)) ?? '';
        $message = preg_replace('/(\\bauthorization\\b\\s*[:=]\\s*)(?:(?:bearer|basic)\\s+)?[^\\s]+/i', '$1[redacted]', $message) ?? $message;
        $message = preg_replace('/\\b(bearer|basic)\\s+[A-Za-z0-9._~+\\/=-]+/i', '$1 [redacted]', $message) ?? $message;
        $message = preg_replace('/(\\b[\\w.-]*(?:password|passwd|token|secret(?:[_-]?key)?|api[_-]?key)\\b)\\s*[:=]\\s*[^\\s]+/i', '$1=[redacted]', $message) ?? $message;
        $message = preg_replace('#(\\b[a-z][a-z0-9+.-]*://[^:/@\\s]+:)[^@\\s]+@#i', '$1[redacted]@', $message) ?? $message;
        $message = preg_replace('/\\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]+/i', '[redacted]', $message) ?? $message;
        $message = preg_replace('/\\bwhsec_[A-Za-z0-9]+/i', '[redacted]', $message) ?? $message;
        return function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
    }

    /** @return array{status:'pending'|'failed',consecutiveFailures:int,delaySeconds:int} */
    public static function afterDeliveryFailure(int $consecutiveFailures): array
    {
        $failures = max(0, $consecutiveFailures) + 1;
        if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
            return [
                'status' => 'failed',
                'consecutiveFailures' => self::MAX_CONSECUTIVE_FAILURES,
                'delaySeconds' => 0,
            ];
        }

        return [
            'status' => 'pending',
            'consecutiveFailures' => $failures,
            'delaySeconds' => min(86_400, 60 * (2 ** min($failures, 10))),
        ];
    }
}
