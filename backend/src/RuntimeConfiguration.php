<?php

declare(strict_types=1);

/** Fail closed on unsafe settings before the API, migrations, or payment worker start. */
final class RuntimeConfiguration
{
    private const ENVIRONMENTS = ['development', 'test', 'staging', 'production'];

    public static function assertSafeStartup(): void
    {
        $environment = strtolower(trim((string) (getenv('APP_ENV') ?: 'production')));
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new HttpException('APP_ENV inválido. Use development, test, staging ou production.', 503, [], 'invalid_runtime_environment');
        }

        $paymentMode = PaymentPolicy::executionMode(null, $environment);
        PaymentPolicy::assertStripeKeyMatchesEnvironment(null, $environment);

        self::assertPublicAppUrl($environment);
        self::assertCorsOrigins($environment);
        self::assertTrustedProxyConfiguration($environment);
        self::assertMailConfiguration($environment);
        self::assertDemoAccountsDisabled($environment);
        self::assertDatabaseCredentials($environment);

        if (in_array($environment, ['staging', 'production'], true)
            && $paymentMode !== PaymentPolicy::MODE_LOCAL_SIMULATION) {
            self::assertStripeIntegrationConfigured($environment);
        }
    }

    private static function assertPublicAppUrl(string $environment): void
    {
        $url = trim((string) (getenv('APP_URL') ?: ''));
        $parts = $url !== '' ? parse_url($url) : false;
        $forbiddenUrlParts = is_array($parts) ? array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) : [];
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || $forbiddenUrlParts !== []
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
            || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new HttpException('Configure APP_URL como a origem pública do site, sem caminho, credenciais, consulta ou fragmento.', 503, [], 'invalid_app_url');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (in_array($environment, ['staging', 'production'], true) && $scheme !== 'https') {
            throw new HttpException('APP_URL precisa usar HTTPS em staging e production; links de login e recuperação não podem ser enviados por HTTP.', 503, [], 'insecure_app_url');
        }
        self::assertValidHostAndPort($parts, 'APP_URL', $environment);
    }

    private static function assertCorsOrigins(string $environment): void
    {
        $configured = trim((string) (getenv('CORS_ALLOWED_ORIGINS') ?: ''));
        if ($configured === '') {
            return;
        }

        foreach (explode(',', $configured) as $configuredOrigin) {
            $origin = trim($configuredOrigin);
            $parts = $origin !== '' ? parse_url($origin) : false;
            $forbiddenOriginParts = is_array($parts) ? array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) : [];
            if (!is_array($parts)
                || !isset($parts['scheme'], $parts['host'])
                || $forbiddenOriginParts !== []
                || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
                || isset($parts['path'])
                || str_contains($origin, '*')
                || preg_match('/[\x00-\x20\\\\]/', $origin)) {
                throw new HttpException('CORS_ALLOWED_ORIGINS contém uma origem inválida; informe somente origens explícitas, sem caminhos ou curingas.', 503, [], 'invalid_cors_origin');
            }

            $scheme = strtolower((string) $parts['scheme']);
            if (in_array($environment, ['staging', 'production'], true) && $scheme !== 'https') {
                throw new HttpException('CORS_ALLOWED_ORIGINS só pode conter origens HTTPS em staging e production.', 503, [], 'insecure_cors_origin');
            }
            self::assertValidHostAndPort($parts, 'CORS_ALLOWED_ORIGINS', $environment);

            $host = strtolower((string) $parts['host']);
            if (str_contains($host, ':')) {
                $host = '[' . $host . ']';
            }
            $port = isset($parts['port']) ? (int) $parts['port'] : null;
            $canonical = $scheme . '://' . $host;
            if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) {
                $canonical .= ':' . $port;
            }
            if (!hash_equals($canonical, $origin)) {
                throw new HttpException('CORS_ALLOWED_ORIGINS deve usar o formato canônico de origem, sem barra final nem porta padrão explícita.', 503, [], 'noncanonical_cors_origin');
            }
        }
    }

    private static function assertTrustedProxyConfiguration(string $environment): void
    {
        $configuredTrust = getenv('TRUST_PROXY_HEADERS');
        $trustValue = strtolower(trim($configuredTrust === false ? 'false' : $configuredTrust));
        if (!in_array($trustValue, ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true)) {
            throw new HttpException('TRUST_PROXY_HEADERS precisa ser true ou false.', 503, [], 'invalid_trusted_proxy_setting');
        }

        $trustedRanges = getenv('TRUSTED_PROXY_IPS');
        if ($trustedRanges !== false && trim($trustedRanges) !== ''
            && !TrustedProxy::configurationIsValid($trustedRanges)) {
            throw new HttpException('TRUSTED_PROXY_IPS contém um IP ou CIDR inválido.', 503, [], 'invalid_trusted_proxy_ranges');
        }

        $trustedHosts = getenv('TRUSTED_PROXY_HOSTS');
        if ($trustedHosts !== false && trim($trustedHosts) !== ''
            && !TrustedProxy::hostConfigurationIsValid($trustedHosts)) {
            throw new HttpException('TRUSTED_PROXY_HOSTS contém um nome de host inválido.', 503, [], 'invalid_trusted_proxy_hosts');
        }

        $trustEnabled = in_array($trustValue, ['true', '1', 'yes', 'on'], true);
        if ($trustEnabled && in_array($environment, ['staging', 'production'], true)
            && ($trustedRanges === false || trim($trustedRanges) === '')
            && ($trustedHosts === false || trim($trustedHosts) === '')) {
            throw new HttpException('Configure IPs/CIDRs ou hosts de proxy explícitos antes de confiar em headers encaminhados em staging/production.', 503, [], 'trusted_proxy_ranges_missing');
        }
    }

    private static function assertValidHostAndPort(array $parts, string $setting, string $environment): void
    {
        $host = (string) ($parts['host'] ?? '');
        $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
            || strtolower($host) === 'localhost'
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        $port = $parts['port'] ?? null;
        if (!$validHost || ($port !== null && ($port < 1 || $port > 65535))) {
            throw new HttpException("{$setting} contém um host ou uma porta inválida.", 503, [], 'invalid_runtime_host');
        }

        if (in_array($environment, ['staging', 'production'], true)) {
            $lowerHost = strtolower($host);
            $isLocalName = $lowerHost === 'localhost'
                || str_ends_with($lowerHost, '.localhost')
                || str_ends_with($lowerHost, '.local');
            $isPublicIp = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            if ($isLocalName || (filter_var($host, FILTER_VALIDATE_IP) !== false && !$isPublicIp)) {
                throw new HttpException("{$setting} não pode apontar para localhost ou um IP privado/reservado em staging/production.", 503, [], 'nonpublic_runtime_host');
            }
        }
    }

    private static function assertMailConfiguration(string $environment): void
    {
        $defaultTransport = in_array($environment, ['development', 'test'], true) ? 'log' : 'smtp';
        $transport = strtolower(trim((string) (getenv('MAIL_TRANSPORT') ?: $defaultTransport)));
        if (!in_array($transport, ['log', 'smtp'], true)) {
            throw new HttpException('MAIL_TRANSPORT inválido. Use smtp; log só é aceito em development ou test.', 503, [], 'invalid_mail_transport');
        }
        if ($transport === 'log' && !in_array($environment, ['development', 'test'], true)) {
            throw new HttpException('MAIL_TRANSPORT=log é proibido em staging e production porque grava links e tokens de autenticação em disco.', 503, [], 'unsafe_mail_transport');
        }

        if ($transport === 'smtp') {
            $from = trim((string) (getenv('MAIL_FROM') ?: 'no-reply@botanicalessence.be'));
            if (!filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $from)) {
                throw new HttpException('MAIL_FROM precisa ser um endereço de e-mail válido.', 503, [], 'invalid_mail_from');
            }

            $host = trim((string) (getenv('MAIL_HOST') ?: ''));
            $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
            if ($host === '' || !$validHost || preg_match('/[\x00-\x20\\\\\/]/', $host)) {
                throw new HttpException('MAIL_HOST precisa ser um hostname SMTP ou endereço IP válido.', 503, [], 'invalid_mail_host');
            }

            $portValue = getenv('MAIL_PORT');
            $port = filter_var($portValue === false ? '587' : trim($portValue), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 65535],
            ]);
            if ($port === false) {
                throw new HttpException('MAIL_PORT precisa ser um inteiro entre 1 e 65535.', 503, [], 'invalid_mail_port');
            }

            $encryption = strtolower(trim((string) (getenv('MAIL_ENCRYPTION') ?: 'tls')));
            if (!in_array($encryption, ['tls', 'smtps'], true)) {
                throw new HttpException('MAIL_ENCRYPTION precisa ser tls (STARTTLS) ou smtps (TLS implícito).', 503, [], 'invalid_mail_encryption');
            }

            $usernameValue = getenv('MAIL_USERNAME');
            $username = $usernameValue === false ? '' : trim($usernameValue);
            $passwordValue = getenv('MAIL_PASSWORD');
            $password = $passwordValue === false ? '' : $passwordValue;
            if (($username === '') !== ($password === '')
                || preg_match('/[\x00-\x1f\x7f]/', $username)
                || preg_match('/[\x00-\x1f\x7f]/', $password)) {
                throw new HttpException('MAIL_USERNAME e MAIL_PASSWORD devem estar ambos configurados ou ambos vazios, sem caracteres de controle.', 503, [], 'invalid_mail_authentication');
            }
        }
    }

    private static function assertDemoAccountsDisabled(string $environment): void
    {
        $configured = getenv('ENABLE_DEMO_ACCOUNTS');
        if ($configured === false || trim($configured) === '') {
            return;
        }
        $enabled = filter_var($configured, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new HttpException('ENABLE_DEMO_ACCOUNTS precisa ser true ou false.', 503, [], 'invalid_demo_account_setting');
        }
        if ($enabled && $environment !== 'development') {
            throw new HttpException('Contas demonstrativas só podem ser habilitadas em development.', 503, [], 'demo_accounts_outside_development');
        }
    }

    private static function assertDatabaseCredentials(string $environment): void
    {
        if (!in_array($environment, ['staging', 'production'], true)) {
            return;
        }

        $database = trim((string) (getenv('DB_DATABASE') ?: ''));
        $username = trim((string) (getenv('DB_USERNAME') ?: ''));
        $password = (string) (getenv('DB_PASSWORD') ?: '');
        $lowerPassword = strtolower($password);
        $knownPlaceholder = false;
        foreach (['replace-with', 'change-me', 'changeme', 'password', 'placeholder', 'example', 'not-a-secret'] as $marker) {
            if (str_contains($lowerPassword, $marker)) {
                $knownPlaceholder = true;
                break;
            }
        }

        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)
            || !preg_match('/^[A-Za-z0-9_]{1,32}$/D', $username)
            || in_array(strtolower($username), ['root', 'admin', 'mysql'], true)
            || strlen($password) < 32
            || count(array_unique(str_split($password))) < 12
            || preg_match('/[\x00-\x20\x7f]/', $password)
            || $knownPlaceholder
            || hash_equals($password, $username)
            || hash_equals($password, $database)) {
            throw new HttpException('Configure nome de banco/usuário dedicados e uma senha de aplicação aleatória com ao menos 32 caracteres antes de iniciar staging ou production.', 503, [], 'unsafe_database_credentials');
        }
    }

    private static function assertStripeIntegrationConfigured(string $environment): void
    {
        $expectedPrefix = $environment === 'production' ? 'sk_live_' : 'sk_test_';
        $secretKey = trim((string) (getenv('STRIPE_SECRET_KEY') ?: ''));
        $webhookSecret = trim((string) (getenv('STRIPE_WEBHOOK_SECRET') ?: ''));
        if (!str_starts_with($secretKey, $expectedPrefix)
            || !preg_match('/^whsec_[A-Za-z0-9]+$/', $webhookSecret)) {
            throw new HttpException('Configure a chave Stripe e o segredo de webhook do ambiente correto antes de iniciar staging ou production.', 503, [], 'stripe_runtime_configuration_missing');
        }

        // Every method/currency currently offered by checkout must have a
        // merchant-account-specific limit before the deployment is considered ready.
        foreach ([['card', 'EUR'], ['card', 'USD'], ['bancontact', 'EUR'], ['sepa_debit', 'EUR']] as [$method, $currency]) {
            PaymentPolicy::stripePaymentLimits($method, $currency);
        }
    }
}
