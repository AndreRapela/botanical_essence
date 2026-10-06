<?php

declare(strict_types=1);

/** Serializes checkout creation and order payment mutations across app workers. */
final class PaymentOrderLock
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function acquire(int $orderId): void
    {
        // MySQL advisory locks coordinate PHP workers and app replicas through
        // the shared database without holding a transaction open during Stripe I/O.
        $this->acquireNamed($this->lockName($orderId));
    }

    public function release(int $orderId): void
    {
        $this->releaseNamed($this->lockName($orderId));
    }

    public function acquireForUser(int $userId): void
    {
        $this->acquireNamed('botanical-user-checkout-' . $userId);
    }

    public function releaseForUser(int $userId): void
    {
        $this->releaseNamed('botanical-user-checkout-' . $userId);
    }

    private function lockName(int $orderId): string
    {
        return 'botanical-payment-order-' . $orderId;
    }

    private function acquireNamed(string $lockName): void
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:lockName, 5)');
        $statement->execute([':lockName' => $lockName]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new HttpException(
                'Há outra operação de compra em andamento. Aguarde alguns segundos e tente novamente.',
                409,
                [],
                'payment_operation_in_progress'
            );
        }
    }

    private function releaseNamed(string $lockName): void
    {
        try {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:lockName)');
            $statement->execute([':lockName' => $lockName]);
            if ((int) $statement->fetchColumn() !== 1) {
                error_log('Payment advisory lock was not released explicitly: ' . $lockName . '.');
            }
        } catch (Throwable $exception) {
            error_log('Could not explicitly release payment advisory lock ' . $lockName . ': ' . $exception->getMessage());
        }
    }
}
