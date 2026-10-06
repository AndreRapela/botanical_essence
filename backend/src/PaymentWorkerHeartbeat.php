<?php

declare(strict_types=1);

final class PaymentWorkerHeartbeat
{
    public function __construct(private readonly string $path)
    {
    }

    public function resetAtStartup(): void
    {
        if (is_file($this->path) && !unlink($this->path)) {
            throw new RuntimeException('Could not clear the previous payment worker heartbeat.');
        }
    }

    public function recordBatchResult(bool $successful, ?int $completedAt = null): bool
    {
        if (!$successful) {
            return false;
        }

        return file_put_contents($this->path, (string) ($completedAt ?? time()), LOCK_EX) !== false;
    }
}
