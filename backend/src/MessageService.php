<?php

declare(strict_types=1);

final class MessageService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $limiter,
        private readonly ?AdminNotificationService $adminNotifications = null
    ) {
    }

    public function subscribe(array $input): array
    {
        Validation::allowedFields($input, ['email', 'language']);
        $email = Validation::email($input);
        $language = Validation::oneOf($input, 'language', ['en', 'fr']);
        $this->limiter->assertAllowed('newsletter-ip', Request::ip(), 30, 3600);
        $this->limiter->assertAllowed('newsletter', Request::ip() . '|' . $email, 5, 3600);
        $statement = $this->pdo->prepare(
            'INSERT INTO newsletter_subscriptions (email, language, status)
             VALUES (:email, :language, \'subscribed\')
             ON DUPLICATE KEY UPDATE language = VALUES(language), status = \'subscribed\', updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([':email' => $email, ':language' => $language]);
        return ['message' => 'Inscrição realizada.', '_status' => 201];
    }

    public function adminContactMessages(array $query, array $admin): array
    {
        Validation::allowedFields($query, ['page', 'limit', 'status']);
        $page = Validation::queryInteger($query, 'page', 1, 1, 1_000_000);
        $limit = Validation::queryInteger($query, 'limit', 50, 1, 100);
        $status = isset($query['status'])
            ? Validation::oneOf($query, 'status', ['all', 'new', 'in_progress', 'closed'])
            : 'all';
        $offset = ($page - 1) * $limit;

        $where = $status === 'all' ? '' : ' WHERE status = :status';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM contact_messages' . $where);
        if ($status !== 'all') {
            $count->execute([':status' => $status]);
        } else {
            $count->execute();
        }
        $total = (int) $count->fetchColumn();

        $messages = $this->pdo->prepare(
            'SELECT id, name, email, subject, message, status, created_at AS createdAt
             FROM contact_messages' . $where . '
             ORDER BY CASE status WHEN \'new\' THEN 0 WHEN \'in_progress\' THEN 1 ELSE 2 END,
                      created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        if ($status !== 'all') {
            $messages->bindValue(':status', $status);
        }
        $messages->bindValue(':limit', $limit, PDO::PARAM_INT);
        $messages->bindValue(':offset', $offset, PDO::PARAM_INT);
        $messages->execute();

        return [
            'data' => array_map(static fn (array $message): array => [
                ...$message,
                'id' => (int) $message['id'],
            ], $messages->fetchAll()),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'hasMore' => $offset + $limit < $total,
        ];
    }

    public function updateContactMessage(array $input, array $admin): array
    {
        Validation::allowedFields($input, ['messageId', 'status']);
        $messageId = Validation::integer($input, 'messageId', 1, PHP_INT_MAX);
        $status = Validation::oneOf($input, 'status', ['new', 'in_progress', 'closed']);
        $this->limiter->assertAllowed('admin-write', (string) $admin['id'], 300, 3600);

        $this->pdo->beginTransaction();
        try {
            $current = $this->pdo->prepare('SELECT status FROM contact_messages WHERE id = :id LIMIT 1 FOR UPDATE');
            $current->execute([':id' => $messageId]);
            $previous = $current->fetchColumn();
            if ($previous === false) {
                throw new HttpException('Mensagem de contato não encontrada.', 404);
            }

            if ($previous !== $status) {
                $update = $this->pdo->prepare('UPDATE contact_messages SET status = :status WHERE id = :id');
                $update->execute([':status' => $status, ':id' => $messageId]);
                $audit = $this->pdo->prepare(
                    'INSERT INTO admin_audit_log
                        (admin_user_id, action_name, target_type, target_id, previous_value, new_value)
                     VALUES (:adminId, \'contact_message.status_updated\', \'contact_message\', :targetId, :previous, :new)'
                );
                $audit->execute([
                    ':adminId' => $admin['id'],
                    ':targetId' => $messageId,
                    ':previous' => $previous,
                    ':new' => $status,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['message' => 'Status da mensagem atualizado.'];
    }

    public function contact(array $input): array
    {
        Validation::allowedFields($input, ['name', 'email', 'subject', 'message']);
        $this->limiter->assertAllowed('contact', Request::ip(), 10, 3600);
        $name = Validation::requiredString($input, 'name', 2, 120);
        $email = Validation::email($input);
        $subject = Validation::oneOf($input, 'subject', ['order', 'care', 'seller', 'other']);
        $message = Validation::requiredString($input, 'message', 10, 4000);
        $statement = $this->pdo->prepare(
            'INSERT INTO contact_messages (name, email, subject, message) VALUES (:name, :email, :subject, :message)'
        );
        $statement->execute([':name' => $name, ':email' => $email, ':subject' => $subject, ':message' => $message]);
        if ($this->adminNotifications !== null) {
            $this->adminNotifications->contactMessageReceived((int) $this->pdo->lastInsertId());
        }
        return ['message' => 'Mensagem recebida.', '_status' => 201];
    }
}
