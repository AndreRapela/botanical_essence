<?php

declare(strict_types=1);

final class AdminPaymentNotificationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $limiter
    ) {
    }

    public function issues(array $query = []): array
    {
        Validation::allowedFields($query, ['page', 'limit']);
        $page = Validation::queryInteger($query, 'page', 1, 1, 1_000_000);
        $limit = Validation::queryInteger($query, 'limit', 50, 1, 100);
        $offset = ($page - 1) * $limit;
        $where = "(n.status = 'failed' OR n.last_error IS NOT NULL
            OR (n.status = 'processing' AND (n.locked_until IS NULL OR n.locked_until <= CURRENT_TIMESTAMP)))";

        $count = $this->pdo->query("SELECT COUNT(*) FROM payment_notification_outbox n WHERE {$where}");
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            "SELECT n.id, n.order_id AS orderId, o.order_number AS orderNumber,
                    o.customer_name AS customerName, o.email, n.notification_type AS notificationType,
                    n.reference_id AS referenceId, n.status, n.attempts,
                    n.consecutive_failures AS consecutiveFailures,
                    DATE_FORMAT(n.created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt,
                    DATE_FORMAT(n.available_at, '%Y-%m-%dT%H:%i:%sZ') AS availableAt,
                    DATE_FORMAT(n.locked_until, '%Y-%m-%dT%H:%i:%sZ') AS lockedUntil,
                    n.last_error AS lastError,
                    (n.status <> 'processing' OR n.locked_until IS NULL OR n.locked_until <= CURRENT_TIMESTAMP) AS retryAvailable
             FROM payment_notification_outbox n
             INNER JOIN orders o ON o.id = n.order_id
             WHERE {$where}
             ORDER BY CASE WHEN n.status = 'failed' THEN 0 ELSE 1 END, n.id DESC
             LIMIT :limit OFFSET :offset"
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $data = array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['orderId'] = (int) $row['orderId'];
            $row['attempts'] = (int) $row['attempts'];
            $row['consecutiveFailures'] = (int) $row['consecutiveFailures'];
            $row['retryAvailable'] = (bool) $row['retryAvailable'];
            $row['lastError'] = is_string($row['lastError']) ? self::safeDiagnostic($row['lastError']) : null;
            return $row;
        }, $statement->fetchAll());

        return ['data' => $data, 'page' => $page, 'limit' => $limit, 'total' => $total, 'hasMore' => $offset + $limit < $total];
    }

    public function retry(array $input, array $admin): array
    {
        Validation::allowedFields($input, ['id', 'reason']);
        $id = Validation::integer($input, 'id', 1, PHP_INT_MAX);
        $reason = Validation::requiredString($input, 'reason', 10, 500);
        $adminId = Validation::integer($admin, 'id', 1, PHP_INT_MAX);
        $this->limiter->assertAllowed('admin-write', (string) $adminId, 300, 3600);

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT status, attempts, last_error,
                        (locked_until IS NOT NULL AND locked_until > CURRENT_TIMESTAMP) AS lockActive
                 FROM payment_notification_outbox WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $select->execute([':id' => $id]);
            $current = $select->fetch();
            if (!$current) {
                throw new HttpException('Notificação de pagamento não encontrada.', 404);
            }
            if ($current['status'] === 'sent') {
                throw new HttpException('Esta notificação já foi enviada e não pode ser repetida por esta fila.', 409, [], 'notification_already_sent');
            }
            if ((bool) $current['lockActive']) {
                throw new HttpException('O worker ainda está processando esta notificação. Aguarde o fim da tentativa antes de reenviar.', 409, [], 'notification_delivery_in_progress');
            }
            if (!in_array($current['status'], ['failed', 'pending', 'processing'], true)
                || ($current['status'] === 'pending' && $current['last_error'] === null)) {
                throw new HttpException('Somente notificações com erro registrado podem ser reenviadas manualmente.', 409, [], 'notification_retry_not_allowed');
            }

            $update = $this->pdo->prepare(
                "UPDATE payment_notification_outbox
                 SET status = 'pending', available_at = CURRENT_TIMESTAMP,
                     locked_until = NULL, consecutive_failures = 0, last_error = :lastError
                 WHERE id = :id"
            );
            $update->execute([
                ':id' => $id,
                // Older rows may predate redaction. Scrub them before persisting
                // again so a manual retry does not preserve leaked credentials.
                ':lastError' => is_string($current['last_error'])
                    ? PaymentNotificationPolicy::safeDiagnostic($current['last_error'])
                    : null,
            ]);

            $audit = $this->pdo->prepare(
                "INSERT INTO admin_audit_log
                    (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                 VALUES (:adminId, 'payment_notification.manual_retry', 'payment_notification', :targetId, :previous, :new)"
            );
            $audit->execute([
                ':adminId' => $adminId,
                ':targetId' => $id,
                ':previous' => (string) $current['status'] . '; total_attempts=' . (int) $current['attempts'],
                ':new' => 'pending; consecutive_failures=0; reason=' . self::safeDiagnostic($reason),
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['message' => 'A notificação foi colocada novamente na fila. A entrega ocorrerá pelo worker.'];
    }

    private static function safeDiagnostic(mixed $value): ?string
    {
        return is_string($value) ? PaymentNotificationPolicy::safeDiagnostic($value) : null;
    }
}
