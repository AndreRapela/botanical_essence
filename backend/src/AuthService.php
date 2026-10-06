<?php

declare(strict_types=1);

final class AuthService
{
    private const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$MzFHaWVzZkVxMHgyMXloRg$bnzpc8kGAAI26FF/+YyuGVcJ2sVQGibvQaz4ot2oppg';

    public function __construct(
        private readonly PDO $pdo,
        private readonly MailTransport $mailer,
        private readonly RateLimiter $limiter,
        private readonly AdminNotificationService $adminNotifications
    ) {
    }

    public function currentUser(): ?array
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, name, email, role, auth_version AS authVersion, email_verified_at AS emailVerifiedAt
             FROM users WHERE id = :id AND status = \'active\' LIMIT 1'
        );
        $statement->execute([':id' => $userId]);
        $user = $statement->fetch();
        $sessionAuthVersion = (int) ($_SESSION['auth_version'] ?? 0);
        if (!$user || $sessionAuthVersion < 1 || $sessionAuthVersion !== (int) $user['authVersion']) {
            SessionManager::expireAuthentication();
            return null;
        }
        return $this->serializeUser($user);
    }

    public function requireUser(): array
    {
        $user = $this->currentUser();
        if ($user === null) {
            throw new HttpException('Autenticação necessária.', 401);
        }
        return $user;
    }

    public function requireRole(string ...$roles): array
    {
        $user = $this->requireUser();
        if (!in_array($user['role'], $roles, true)) {
            throw new HttpException('Você não tem permissão para acessar este recurso.', 403);
        }
        return [...$user, 'authVersion' => (int) ($_SESSION['auth_version'] ?? 0)];
    }

    public function login(array $input): array
    {
        Validation::allowedFields($input, ['email', 'password', 'rememberMe']);
        $email = Validation::email($input);
        $password = isset($input['password']) && is_string($input['password']) && strlen($input['password']) <= 128
            ? $input['password']
            : '';
        $remember = Validation::boolean($input, 'rememberMe', false);
        $ip = Request::ip();
        $this->limiter->assertAllowed('login-ip', $ip, 20, 900);
        $this->limiter->assertAllowed('login-pair', $ip . '|' . $email, 5, 900);
        $this->limiter->assertAllowed('login-account', $email, 50, 900);

        $statement = $this->pdo->prepare(
            'SELECT id, name, email, password_hash, role, status, auth_version AS authVersion,
                    email_verified_at AS emailVerifiedAt
             FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute([':email' => $email]);
        $user = $statement->fetch();

        $passwordMatches = password_verify($password, (string) ($user['password_hash'] ?? self::DUMMY_PASSWORD_HASH));
        if (!$user || !$passwordMatches) {
            throw new HttpException('E-mail ou senha inválidos.', 401);
        }
        if ($user['status'] !== 'active' || $user['emailVerifiedAt'] === null) {
            throw new HttpException('Confirme seu e-mail antes de entrar.', 403);
        }

        if (password_needs_rehash((string) $user['password_hash'], $this->passwordAlgorithm())) {
            $rehash = $this->pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $rehash->execute([':hash' => $this->hashPassword($password), ':id' => $user['id']]);
        }

        $this->limiter->clear('login-pair', $ip . '|' . $email);
        $this->limiter->clear('login-account', $email);
        SessionManager::login((int) $user['id'], (int) $user['authVersion'], $remember);
        unset($user['password_hash'], $user['status']);
        return $this->serializeUser($user);
    }

    public function demoAccounts(): array
    {
        if (!$this->demoEnabled()) {
            return ['enabled' => false, 'accounts' => []];
        }
        return [
            'enabled' => true,
            'accounts' => [
                ['role' => 'admin', 'label' => 'Entrar como administrador'],
                ['role' => 'customer', 'label' => 'Entrar como usuário'],
                ['role' => 'seller', 'label' => 'Entrar como vendedor'],
            ],
        ];
    }

    public function demoLogin(array $input): array
    {
        if (!$this->demoEnabled()) {
            throw new HttpException('Acesso de demonstração indisponível.', 404);
        }
        Validation::allowedFields($input, ['role']);
        $role = Validation::oneOf($input, 'role', ['admin', 'customer', 'seller']);
        $this->limiter->assertAllowed('demo-login-ip', Request::ip(), 30, 900);

        $statement = $this->pdo->prepare(
            'SELECT id, name, email, role, auth_version AS authVersion,
                    email_verified_at AS emailVerifiedAt
             FROM users
             WHERE role = :role AND is_demo = TRUE AND status = \'active\'
               AND email_verified_at IS NOT NULL
             ORDER BY id ASC LIMIT 1'
        );
        $statement->execute([':role' => $role]);
        $user = $statement->fetch();
        if (!$user) {
            throw new HttpException('Conta de demonstração indisponível.', 503);
        }
        SessionManager::login((int) $user['id'], (int) $user['authVersion'], false);
        return $this->serializeUser($user);
    }

    public function register(array $input): array
    {
        Validation::allowedFields($input, [
            'name', 'email', 'password', 'role', 'storeName', 'city', 'region', 'country', 'acceptTerms',
        ]);
        $this->limiter->assertAllowed('register', Request::ip(), 4, 3600);
        $name = Validation::requiredString($input, 'name', 2, 120);
        $email = Validation::email($input);
        $password = Validation::password($input);
        $role = Validation::oneOf($input, 'role', ['customer', 'seller']);
        $this->limiter->assertAllowed('register-email', $email, 8, 86_400);
        if (!Validation::boolean($input, 'acceptTerms')) {
            throw new HttpException('É necessário aceitar os termos.', 422, ['acceptTerms' => 'Aceite os termos para continuar.']);
        }

        $seller = null;
        if ($role === 'seller') {
            $seller = [
                'displayName' => Validation::requiredString($input, 'storeName', 2, 120),
                'city' => Validation::requiredString($input, 'city', 2, 100),
                'region' => Validation::requiredString($input, 'region', 2, 100),
                'country' => Validation::requiredString($input, 'country', 2, 100),
            ];
        } elseif (array_intersect(['storeName', 'city', 'region', 'country'], array_keys($input)) !== []) {
            throw new HttpException('Dados de loja são permitidos apenas para vendedores.', 422);
        }

        // Calculated before the lookup so existing and new accounts have a comparable password-processing cost.
        $passwordHash = $this->hashPassword($password);

        $existing = $this->pdo->prepare('SELECT id, name, password_hash, status FROM users WHERE email = :email LIMIT 1');
        $existing->execute([':email' => $email]);
        $existingUser = $existing->fetch();
        if ($existingUser) {
            // Public registration must never edit an existing identity or its store.
            // A pending account can be an established account awaiting an admin email change.
            $canResend = $existingUser['status'] === 'pending'
                && password_verify($password, (string) $existingUser['password_hash']);
            return [
                'created' => false,
                'developmentVerificationUrl' => $canResend
                    ? $this->issueVerification((int) $existingUser['id'], $email, (string) $existingUser['password_hash'])
                    : null,
            ];
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role, status) VALUES (:name, :email, :password, :role, \'pending\')'
            );
            $statement->execute([
                ':name' => $name,
                ':email' => $email,
                ':password' => $passwordHash,
                ':role' => $role,
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            if ($seller !== null) {
                $sellerStatement = $this->pdo->prepare(
                    'INSERT INTO sellers (user_id, display_name, slug, city, state, country, verified)
                     VALUES (:userId, :displayName, :slug, :city, :region, :country, FALSE)'
                );
                $sellerStatement->execute([
                    ':userId' => $userId,
                    ':displayName' => $seller['displayName'],
                    ':slug' => $this->uniqueSlug($seller['displayName']),
                    ':city' => $seller['city'],
                    ':region' => $seller['region'],
                    ':country' => $seller['country'],
                ]);
            }
            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                return ['created' => false, 'developmentVerificationUrl' => null];
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'created' => true,
            'developmentVerificationUrl' => $this->issueVerification($userId, $email, $passwordHash),
        ];
    }

    private function issueVerification(int $userId, string $email, string $expectedPasswordHash): ?string
    {
        $this->pdo->beginTransaction();
        try {
            $userLock = $this->pdo->prepare('SELECT name, email, status, password_hash FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
            $userLock->execute([':id' => $userId]);
            $current = $userLock->fetch();
            if (!$current || $current['status'] !== 'pending' || $current['email'] !== $email
                || !hash_equals((string) $current['password_hash'], $expectedPasswordHash)) {
                $this->pdo->commit();
                return null;
            }
            $name = (string) $current['name'];
        $this->pdo->prepare(
            'DELETE FROM email_verification_tokens
             WHERE user_id = :userId AND (used_at IS NOT NULL OR expires_at <= CURRENT_TIMESTAMP)'
        )->execute([':userId' => $userId]);

        $active = $this->pdo->prepare(
            'SELECT COUNT(*) FROM email_verification_tokens
             WHERE user_id = :userId AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
        );
        $active->execute([':userId' => $userId]);
        if ((int) $active->fetchColumn() >= 5) {
            $this->pdo->commit();
            return null;
        }

        $plainToken = bin2hex(random_bytes(32));
        $token = $this->pdo->prepare(
            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
             VALUES (:userId, :hash, CURRENT_TIMESTAMP + INTERVAL 24 HOUR)'
        );
        $token->execute([':userId' => $userId, ':hash' => hash('sha256', $plainToken)]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }

        $url = rtrim(getenv('APP_URL') ?: 'http://localhost:4300', '/') . '/verify-email#token=' . rawurlencode($plainToken);
        try {
            $this->mailer->send(
                $email,
                'Confirme sua conta Botanical Essence',
                "Olá, {$name}!\n\nConfirme sua conta: {$url}\n\nNa página de confirmação, informe a mesma senha criada no cadastro. O link expira em 24 horas. Se você não iniciou este cadastro, ignore esta mensagem."
            );
        } catch (Throwable $exception) {
            error_log('Falha ao enviar verificação de e-mail: ' . $exception->getMessage());
        }

        return $this->developmentUrl($url);
    }

    public function verifyEmail(array $input): void
    {
        Validation::allowedFields($input, ['token', 'password']);
        $this->limiter->assertAllowed('verify-email-ip', Request::ip(), 30, 3600);
        $plainToken = isset($input['token']) && is_string($input['token']) ? $input['token'] : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $plainToken)) {
            throw new HttpException('Link de verificação inválido.', 422);
        }
        $password = isset($input['password']) && is_string($input['password']) && strlen($input['password']) <= 128
            ? $input['password']
            : '';
        $this->limiter->assertAllowed('verify-email-token', hash('sha256', $plainToken), 5, 900);
        $hash = hash('sha256', $plainToken);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT token.id, token.user_id, user_account.password_hash
                 FROM email_verification_tokens token
                 INNER JOIN users user_account ON user_account.id = token.user_id
                 WHERE token.token_hash = :hash
                   AND token.used_at IS NULL
                   AND token.expires_at > CURRENT_TIMESTAMP
                   AND user_account.status = \'pending\'
                 FOR UPDATE'
            );
            $statement->execute([':hash' => $hash]);
            $token = $statement->fetch();
            if (!$token) {
                throw new HttpException('Link de verificação inválido ou expirado.', 422);
            }
            if (!password_verify($password, (string) $token['password_hash'])) {
                throw new HttpException('Link ou senha de verificação inválido.', 422);
            }
            $update = $this->pdo->prepare(
                'UPDATE users SET email_verified_at = CURRENT_TIMESTAMP, status = \'active\'
                 WHERE id = :id AND status = \'pending\''
            );
            $update->execute([':id' => $token['user_id']]);
            if ($update->rowCount() !== 1) {
                throw new HttpException('Link de verificação inválido ou expirado.', 422);
            }
            $used = $this->pdo->prepare(
                'DELETE FROM email_verification_tokens WHERE user_id = :userId'
            );
            $used->execute([':userId' => $token['user_id']]);
            $this->pdo->commit();
            $this->adminNotifications->sellerAwaitingApprovalForUser((int) $token['user_id']);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function requestPasswordReset(array $input): array
    {
        Validation::allowedFields($input, ['email']);
        $email = Validation::email($input);
        $this->limiter->assertAllowed('forgot-password-ip', Request::ip(), 10, 3600);
        $this->limiter->assertAllowed('forgot-password-account', $email, 3, 3600);
        $statement = $this->pdo->prepare(
            'SELECT id, name FROM users
             WHERE email = :email AND status = \'active\' AND email_verified_at IS NOT NULL
             LIMIT 1'
        );
        $statement->execute([':email' => $email]);
        $user = $statement->fetch();
        if (!$user) {
            return [];
        }

        $plainToken = bin2hex(random_bytes(32));
        $this->pdo->beginTransaction();
        try {
            $cleanup = $this->pdo->prepare(
                'DELETE FROM password_reset_tokens
                 WHERE user_id = :userId AND (used_at IS NOT NULL OR expires_at <= CURRENT_TIMESTAMP)'
            );
            $cleanup->execute([':userId' => $user['id']]);
            $active = $this->pdo->prepare(
                'SELECT COUNT(*) FROM password_reset_tokens
                 WHERE user_id = :userId AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
            );
            $active->execute([':userId' => $user['id']]);
            if ((int) $active->fetchColumn() >= 3) {
                $this->pdo->commit();
                return [];
            }
            $token = $this->pdo->prepare(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                 VALUES (:userId, :hash, CURRENT_TIMESTAMP + INTERVAL 30 MINUTE)'
            );
            $token->execute([':userId' => $user['id'], ':hash' => hash('sha256', $plainToken)]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $url = rtrim(getenv('APP_URL') ?: 'http://localhost:4300', '/') . '/reset-password#token=' . rawurlencode($plainToken);
        try {
            $this->mailer->send(
                $email,
                'Redefina sua senha Botanical Essence',
                "Olá, {$user['name']}!\n\nRedefina sua senha: {$url}\n\nO link expira em 30 minutos."
            );
        } catch (Throwable $exception) {
            error_log('Falha ao enviar recuperação de senha: ' . $exception->getMessage());
        }
        return ['developmentResetUrl' => $this->developmentUrl($url)];
    }

    public function resetPassword(array $input): void
    {
        Validation::allowedFields($input, ['token', 'password']);
        $plainToken = isset($input['token']) && is_string($input['token']) ? $input['token'] : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $plainToken)) {
            throw new HttpException('Link de recuperação inválido.', 422);
        }
        $this->limiter->assertAllowed('reset-password-ip', Request::ip(), 20, 3600);
        $password = Validation::password($input);
        $hash = hash('sha256', $plainToken);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT token.id, token.user_id
                 FROM password_reset_tokens token
                 INNER JOIN users user_account ON user_account.id = token.user_id
                 WHERE token.token_hash = :hash
                   AND token.used_at IS NULL
                   AND token.expires_at > CURRENT_TIMESTAMP
                   AND user_account.status = \'active\'
                 FOR UPDATE'
            );
            $statement->execute([':hash' => $hash]);
            $token = $statement->fetch();
            if (!$token) {
                throw new HttpException('Link de recuperação inválido ou expirado.', 422);
            }
            $update = $this->pdo->prepare(
                'UPDATE users
                 SET password_hash = :password, auth_version = auth_version + 1
                 WHERE id = :id AND status = \'active\''
            );
            $update->execute([':password' => $this->hashPassword($password), ':id' => $token['user_id']]);
            if ($update->rowCount() !== 1) {
                throw new HttpException('Link de recuperação inválido ou expirado.', 422);
            }
            $used = $this->pdo->prepare(
                'DELETE FROM password_reset_tokens WHERE user_id = :userId'
            );
            $used->execute([':userId' => $token['user_id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function serializeUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'emailVerifiedAt' => $user['emailVerifiedAt'] ?? null,
        ];
    }

    private function demoEnabled(): bool
    {
        return (getenv('APP_ENV') ?: 'production') === 'development'
            && filter_var(getenv('ENABLE_DEMO_ACCOUNTS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }

    private function uniqueSlug(string $name): string
    {
        $base = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-');
        $base = $base !== '' ? substr($base, 0, 120) : 'seller';
        return $base . '-' . bin2hex(random_bytes(3));
    }

    private function hashPassword(string $password): string
    {
        $hash = password_hash($password, $this->passwordAlgorithm());
        if (!is_string($hash)) {
            throw new RuntimeException('Não foi possível proteger a senha.');
        }
        return $hash;
    }

    private function passwordAlgorithm(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    private function developmentUrl(string $url): ?string
    {
        return (getenv('APP_ENV') ?: 'production') === 'development' ? $url : null;
    }
}
