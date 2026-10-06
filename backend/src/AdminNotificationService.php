<?php

declare(strict_types=1);

final class AdminNotificationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Mailer $mailer
    ) {
    }

    public function sellerAwaitingApprovalForUser(int $userId): void
    {
        try {
            $statement = $this->pdo->prepare(
                "SELECT s.display_name AS storeName, s.city, s.state AS region, s.country,
                        u.name AS ownerName, u.email AS ownerEmail
                 FROM sellers s
                 INNER JOIN users u ON u.id = s.user_id
                 WHERE s.user_id = :userId
                   AND s.verified = FALSE
                   AND u.status = 'active'
                   AND u.email_verified_at IS NOT NULL
                 LIMIT 1"
            );
            $statement->execute([':userId' => $userId]);
            $seller = $statement->fetch();
            if (!$seller) {
                return;
            }

            $recipients = $this->pdo->query(
                "SELECT DISTINCT email
                 FROM users
                 WHERE role = 'admin'
                   AND status = 'active'
                   AND email_verified_at IS NOT NULL"
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($recipients === []) {
                error_log('Nova loja pendente sem administrador ativo para receber a notificação.');
                return;
            }

            $adminUrl = rtrim(getenv('APP_URL') ?: 'http://localhost:4300', '/') . '/admin';
            $subject = 'Nova loja aguardando aprovação: ' . $seller['storeName'];
            $text = sprintf(
                "Uma nova loja está aguardando aprovação na Botanical Essence.\n\nLoja: %s\nResponsável: %s\nE-mail: %s\nLocalização: %s, %s · %s\n\nRevise o perfil no painel administrativo: %s",
                $seller['storeName'],
                $seller['ownerName'],
                $seller['ownerEmail'],
                $seller['city'],
                $seller['region'],
                $seller['country'],
                $adminUrl
            );

            foreach ($recipients as $recipient) {
                try {
                    $this->mailer->send((string) $recipient, $subject, $text);
                } catch (Throwable $exception) {
                    error_log('Falha ao enviar aviso de nova loja ao administrador: ' . $exception->getMessage());
                }
            }
        } catch (Throwable $exception) {
            error_log('Falha ao preparar aviso de nova loja: ' . $exception->getMessage());
        }
    }

    public function contactMessageReceived(int $messageId): void
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT name, email, subject FROM contact_messages WHERE id = :id LIMIT 1'
            );
            $statement->execute([':id' => $messageId]);
            $message = $statement->fetch();
            if (!$message) {
                return;
            }

            $recipients = $this->pdo->query(
                "SELECT DISTINCT email FROM users
                 WHERE role = 'admin' AND status = 'active' AND email_verified_at IS NOT NULL"
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($recipients === []) {
                error_log('Nova mensagem de contato sem administrador ativo para receber a notificação.');
                return;
            }

            $adminUrl = rtrim(getenv('APP_URL') ?: 'http://localhost:4300', '/') . '/admin';
            $subject = 'Nova mensagem de contato Botanical Essence';
            $text = sprintf(
                "Uma nova mensagem foi recebida no site.\n\nDe: %s\nE-mail: %s\nAssunto: %s\n\nConsulte e atualize o estado da mensagem no painel: %s",
                $message['name'],
                $message['email'],
                $message['subject'],
                $adminUrl
            );
            foreach ($recipients as $recipient) {
                try {
                    $this->mailer->send((string) $recipient, $subject, $text);
                } catch (Throwable $exception) {
                    error_log('Falha ao enviar aviso de mensagem de contato ao administrador: ' . $exception->getMessage());
                }
            }
        } catch (Throwable $exception) {
            error_log('Falha ao preparar aviso de mensagem de contato: ' . $exception->getMessage());
        }
    }
}
