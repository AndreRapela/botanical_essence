<?php

declare(strict_types=1);

interface MailTransport
{
    public function send(string $recipient, string $subject, string $text): void;
}

final class Mailer implements MailTransport
{
    private readonly string $transport;

    public function __construct()
    {
        $environment = strtolower(trim((string) (getenv('APP_ENV') ?: 'production')));
        $defaultTransport = in_array($environment, ['development', 'test'], true) ? 'log' : 'smtp';
        $this->transport = strtolower(trim((string) (getenv('MAIL_TRANSPORT') ?: $defaultTransport)));
    }

    public function send(string $recipient, string $subject, string $text): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $recipient)) {
            throw new RuntimeException('Destinatário de e-mail inválido.');
        }
        if ($subject === '' || mb_strlen($subject) > 160 || preg_match('/[\r\n]/', $subject)) {
            throw new RuntimeException('Assunto de e-mail inválido.');
        }
        if (strlen($text) > 100_000 || str_contains($text, "\0")) {
            throw new RuntimeException('Conteúdo de e-mail inválido.');
        }
        if ($this->transport === 'log') {
            $directory = dirname(__DIR__) . '/storage';
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new RuntimeException('Não foi possível criar o diretório de e-mail local.');
            }
            $entry = sprintf("[%s] TO: %s\nSUBJECT: %s\n%s\n---\n", gmdate(DATE_ATOM), $recipient, $subject, $text);
            if (file_put_contents($directory . '/mail.log', $entry, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Não foi possível gravar o e-mail local.');
            }
            @chmod($directory . '/mail.log', 0660);
            return;
        }

        if ($this->transport === 'smtp') {
            $from = trim((string) (getenv('MAIL_FROM') ?: 'no-reply@botanicalessence.be'));
            if (!filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $from)) {
                throw new RuntimeException('MAIL_FROM inválido.');
            }

            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (!is_file($autoload)) {
                throw new RuntimeException('A biblioteca SMTP não está instalada no serviço.');
            }
            require_once $autoload;

            try {
                $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mailer->isSMTP();
                $mailer->Host = trim((string) (getenv('MAIL_HOST') ?: ''));
                $portValue = getenv('MAIL_PORT');
                $mailer->Port = (int) ($portValue === false ? '587' : trim($portValue));
                $mailer->SMTPSecure = strtolower(trim((string) (getenv('MAIL_ENCRYPTION') ?: 'tls'))) === 'smtps'
                    ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $usernameValue = getenv('MAIL_USERNAME');
                $mailer->Username = $usernameValue === false ? '' : trim($usernameValue);
                $mailer->SMTPAuth = $mailer->Username !== '';
                $password = getenv('MAIL_PASSWORD');
                $mailer->Password = $password === false ? '' : $password;
                $mailer->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                    ],
                ];
                $mailer->Timeout = 10;
                $mailer->SMTPDebug = 0;
                $mailer->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
                $mailer->isHTML(false);
                $mailer->setFrom($from);
                $mailer->addAddress($recipient);
                $mailer->Subject = $subject;
                $mailer->Body = $text;
                $mailer->send();
                return;
            } catch (\Throwable) {
                // Never expose server responses, credentials, or connection details to callers/logs.
                throw new RuntimeException('O provedor SMTP recusou a mensagem.');
            }
        }

        throw new RuntimeException('MAIL_TRANSPORT inválido.');
    }
}
