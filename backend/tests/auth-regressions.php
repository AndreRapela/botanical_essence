<?php

declare(strict_types=1);

final class RegistrationTestMailer implements MailTransport
{
    public array $messages = [];

    public function send(string $recipient, string $subject, string $text): void
    {
        $this->messages[] = compact('recipient', 'subject', 'text');
    }
}

function authRegressionDatabase(): ?PDO
{
    $connection = [getenv('DB_HOST'), getenv('DB_DATABASE'), getenv('DB_USERNAME')];
    if (!mysqlIntegrationTestsCanMutateDatabase($connection)) {
        fwrite(STDOUT, "SKIP  Auth regression requires an explicitly disposable MySQL database.\n");
        return null;
    }
    $pdo = new PDO(
        'mysql:host=' . $connection[0] . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $connection[1] . ';charset=utf8mb4',
        $connection[2],
        (string) (getenv('DB_PASSWORD') ?: ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    foreach (['users', 'sellers', 'products', 'email_verification_tokens', 'password_reset_tokens', 'rate_limits', 'admin_audit_log'] as $table) {
        // MySQL rejects CREATE TEMPORARY TABLE users LIKE users because the
        // source and destination resolve to the same table alias. Copy through
        // a uniquely named temporary template so the fixture remains isolated.
        $template = "auth_regression_{$table}_template";
        $pdo->exec("CREATE TEMPORARY TABLE `{$template}` LIKE `{$table}`");
        $pdo->exec("CREATE TEMPORARY TABLE `{$table}` LIKE `{$template}`");
        $pdo->exec("DROP TEMPORARY TABLE `{$template}`");
    }
    return $pdo;
}

return [
    'public duplicate registration preserves pending established accounts, stores and tokens' => static function (): void {
        $pdo = authRegressionDatabase();
        if ($pdo === null) return;
        $password = 'Original-owner-password-2026!';
        $insert = $pdo->prepare("INSERT INTO users (id, name, email, password_hash, role, status, auth_version)
            VALUES (1, 'Existing Seller', 'seller@example.test', :password, 'seller', 'pending', 4),
                   (2, 'Pending Admin', 'admin@example.test', :adminPassword, 'admin', 'pending', 6)");
        $insert->execute([':password' => password_hash($password, PASSWORD_DEFAULT), ':adminPassword' => password_hash($password, PASSWORD_DEFAULT)]);
        $pdo->exec("INSERT INTO sellers (id, user_id, display_name, slug, city, state, country)
            VALUES (1, 1, 'Established Store', 'established-store', 'Brussels', 'Brussels-Capital', 'Belgium')");
        $pdo->exec("INSERT INTO products (id, seller_id, name, slug, description, category, price, stock)
            VALUES (1, 1, 'Existing plant', 'existing-plant', 'Existing description', 'Plantas', 10, 2)");
        $tokenHash = hash('sha256', 'existing-admin-email-change-token');
        $pdo->prepare('INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (1, :hash, CURRENT_TIMESTAMP + INTERVAL 24 HOUR)')
            ->execute([':hash' => $tokenHash]);
        $before = $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll();
        $store = $pdo->query('SELECT * FROM sellers')->fetchAll();
        $products = $pdo->query('SELECT * FROM products')->fetchAll();
        $mailer = new RegistrationTestMailer();
        $service = new AuthService($pdo, $mailer, new RateLimiter($pdo), new AdminNotificationService($pdo, new Mailer()));
        foreach (['seller@example.test', 'admin@example.test'] as $email) {
            $result = $service->register(['name' => 'Attacker Name', 'email' => $email, 'password' => 'Attacker-password-2026!', 'role' => 'customer', 'acceptTerms' => true]);
            assertSameValue(false, $result['created']);
            assertSameValue(null, $result['developmentVerificationUrl']);
        }
        assertSameValue($before, $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll());
        assertSameValue($store, $pdo->query('SELECT * FROM sellers')->fetchAll());
        assertSameValue($products, $pdo->query('SELECT * FROM products')->fetchAll());
        assertSameValue([$tokenHash], $pdo->query('SELECT token_hash FROM email_verification_tokens')->fetchAll(PDO::FETCH_COLUMN));
        assertSameValue([], $mailer->messages);

        // Even the existing password only permits a resend, never role/profile replacement.
        $service->register(['name' => 'Changed Name', 'email' => 'seller@example.test', 'password' => $password, 'role' => 'customer', 'acceptTerms' => true]);
        assertSameValue($before, $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll());
        assertSameValue($store, $pdo->query('SELECT * FROM sellers')->fetchAll());
        assertSameValue(1, count($mailer->messages));
        assertTrueValue(str_contains($mailer->messages[0]['text'], 'Existing Seller'));
        assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM email_verification_tokens')->fetchColumn());
    },
    'membership updates reject revoked actors and obsolete session versions before mutations' => static function (): void {
        $pdo = authRegressionDatabase();
        if ($pdo === null) return;
        $insert = $pdo->prepare("INSERT INTO users (id, name, email, password_hash, role, status, auth_version, email_verified_at)
            VALUES (1, 'Revoked Admin', 'revoked@example.test', :hash, 'customer', 'disabled', 2, CURRENT_TIMESTAMP)");
        $insert->execute([':hash' => password_hash('Owner-password-2026!', PASSWORD_DEFAULT)]);
        $service = new AdminService($pdo, new RateLimiter($pdo));
        $input = ['id' => 1, 'name' => 'Revoked Admin', 'email' => 'revoked@example.test', 'role' => 'admin', 'status' => 'active', 'emailVerified' => true];
        assertHttpException(static fn () => $service->updateUser($input, ['id' => 1, 'authVersion' => 1]), 403);
        assertSameValue('customer', $pdo->query('SELECT role FROM users WHERE id = 1')->fetchColumn());
        assertSameValue('disabled', $pdo->query('SELECT status FROM users WHERE id = 1')->fetchColumn());
        $pdo->exec("UPDATE users SET role = 'admin', status = 'active' WHERE id = 1");
        assertHttpException(static fn () => $service->updateUser($input, ['id' => 1, 'authVersion' => 1]), 403);
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn());
        assertSameValue(2, (int) $pdo->query('SELECT auth_version FROM users WHERE id = 1')->fetchColumn());
        // Failed authorization released the membership lock and a current actor still works.
        $result = $service->updateUser($input, ['id' => 1, 'authVersion' => 2]);
        assertSameValue('admin', $result['user']['role']);
    },
];
