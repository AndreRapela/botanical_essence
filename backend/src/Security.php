<?php

declare(strict_types=1);

final class TrustedProxy
{
    public static function headersTrusted(): bool
    {
        if (!filter_var(getenv('TRUST_PROXY_HEADERS') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return self::isTrustedIp($remote);
    }

    public static function isTrustedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $configured = getenv('TRUSTED_PROXY_IPS') ?: '127.0.0.1,::1';
        foreach (array_filter(array_map('trim', explode(',', $configured))) as $range) {
            if (self::contains($ip, $range)) {
                return true;
            }
        }

        $hosts = getenv('TRUSTED_PROXY_HOSTS') ?: '';
        foreach (array_filter(array_map('trim', explode(',', $hosts))) as $host) {
            $addresses = @gethostbynamel($host);
            if (is_array($addresses) && in_array($ip, $addresses, true)) {
                return true;
            }
        }
        return false;
    }

    public static function configurationIsValid(string $configured): bool
    {
        $configured = trim($configured);
        if ($configured === '') {
            return false;
        }

        $ranges = explode(',', $configured);
        if (count($ranges) > 64) {
            return false;
        }
        foreach ($ranges as $range) {
            $range = trim($range);
            if ($range === '') {
                return false;
            }
            [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
            $packedNetwork = inet_pton($network);
            if ($packedNetwork === false || filter_var($network, FILTER_VALIDATE_IP) === false) {
                return false;
            }

            $prefixBits = $prefix === null
                ? strlen($packedNetwork) * 8
                : filter_var($prefix, FILTER_VALIDATE_INT);
            $minimumPrefixBits = strlen($packedNetwork) === 4 ? 24 : 120;
            if ($prefixBits === false
                || $prefixBits < $minimumPrefixBits
                || $prefixBits > strlen($packedNetwork) * 8
                || !self::contains($network, $range)) {
                return false;
            }
        }
        return true;
    }

    public static function hostConfigurationIsValid(string $configured): bool
    {
        $configured = trim($configured);
        if ($configured === '') {
            return false;
        }

        $hosts = explode(',', $configured);
        if (count($hosts) > 64) {
            return false;
        }
        foreach ($hosts as $host) {
            $host = trim($host);
            if ($host === '' || strlen($host) > 63
                || filter_var($host, FILTER_VALIDATE_IP) !== false
                || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $host)) {
                return false;
            }
        }
        return true;
    }

    private static function contains(string $ip, string $range): bool
    {
        [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
        $packedIp = inet_pton($ip);
        $packedNetwork = inet_pton($network);
        if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
            return false;
        }
        $bits = $prefix === null ? strlen($packedIp) * 8 : filter_var($prefix, FILTER_VALIDATE_INT);
        if ($bits === false || $bits < 0 || $bits > strlen($packedIp) * 8) {
            return false;
        }
        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;
        if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }
}

final class SecurityHeaders
{
    public static function apply(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()");
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        header('Cache-Control: no-store');
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    private static function isHttps(): bool
    {
        if (strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on') {
            return true;
        }
        return TrustedProxy::headersTrusted()
            && strtolower((string) (Request::header('X-Forwarded-Proto') ?? '')) === 'https';
    }

    public static function applyCors(): bool
    {
        $origin = Request::header('Origin');
        if ($origin === null) {
            return true;
        }

        $configured = (string) (getenv('CORS_ALLOWED_ORIGINS') ?: '');
        $allowed = array_values(array_filter(array_map('trim', explode(',', $configured))));
        $appOrigin = self::originFromUrl((string) (getenv('APP_URL') ?: ''));
        if ($appOrigin !== null && !in_array($appOrigin, $allowed, true)) {
            $allowed[] = $appOrigin;
        }
        if (!in_array($origin, $allowed, true)) {
            return false;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Vary: Origin');
        return true;
    }

    private static function originFromUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $portNumber = isset($parts['port']) ? (int) $parts['port'] : null;
        $usesDefaultPort = ($scheme === 'http' && $portNumber === 80)
            || ($scheme === 'https' && $portNumber === 443);
        $port = $portNumber !== null && !$usesDefaultPort ? ':' . $portNumber : '';
        return $scheme . '://' . $host . $port;
    }
}

final class SessionManager
{
    private const STANDARD_IDLE_SECONDS = 7_200;
    private const STANDARD_ABSOLUTE_SECONDS = 43_200;
    private const REMEMBER_SECONDS = 2_592_000;
    private const ROTATION_SECONDS = 900;

    public static function requiredForRequest(string $method, string $path): bool
    {
        // These routes do not use authentication. Creating cookies here races
        // with session bootstrap when a new browser loads them concurrently.
        if (strtoupper($method) === 'GET' && in_array($path, [
            '/api', '/api/health', '/api/products', '/api/sellers', '/api/auth/demo-accounts',
        ], true)) {
            return false;
        }
        // Reviews include buyer-specific eligibility only for an existing
        // session; anonymous readers must not create another bootstrap cookie.
        if (strtoupper($method) === 'GET' && $path === '/api/product-reviews') {
            $cookieName = self::isHttps() ? '__Host-botanical_session' : 'botanical_session';
            return is_string($_COOKIE[$cookieName] ?? null) && $_COOKIE[$cookieName] !== '';
        }
        return !(strtoupper($method) === 'POST' && $path === '/api/payments/webhook');
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = self::isHttps();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
        session_name($secure ? '__Host-botanical_session' : 'botanical_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::enforceAuthenticationLifetime();
        self::rotatePeriodically();
    }

    public static function login(int $userId, int $authVersion, bool $remember): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['auth_version'] = $authVersion;
        $_SESSION['auth_issued_at'] = time();
        $_SESSION['auth_last_seen_at'] = time();
        $_SESSION['remember_login'] = $remember;
        $_SESSION['session_rotated_at'] = time();
        Csrf::rotate();
        self::refreshCookie($remember);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['session_rotated_at'] = time();
        Csrf::rotate();
        self::refreshCookie(false);
    }

    public static function expireAuthentication(): void
    {
        unset(
            $_SESSION['user_id'],
            $_SESSION['auth_version'],
            $_SESSION['auth_issued_at'],
            $_SESSION['auth_last_seen_at'],
            $_SESSION['remember_login']
        );
        session_regenerate_id(true);
        $_SESSION['session_rotated_at'] = time();
        Csrf::rotate();
        self::refreshCookie(false);
    }

    private static function enforceAuthenticationLifetime(): void
    {
        if (!isset($_SESSION['user_id'])) {
            return;
        }

        $now = time();
        $issuedAt = (int) ($_SESSION['auth_issued_at'] ?? 0);
        $lastSeenAt = (int) ($_SESSION['auth_last_seen_at'] ?? 0);
        $remember = (bool) ($_SESSION['remember_login'] ?? false);
        $idleLimit = $remember ? self::REMEMBER_SECONDS : self::STANDARD_IDLE_SECONDS;
        $absoluteLimit = $remember ? self::REMEMBER_SECONDS : self::STANDARD_ABSOLUTE_SECONDS;

        if ($issuedAt < 1 || $lastSeenAt < 1 || $now - $issuedAt > $absoluteLimit || $now - $lastSeenAt > $idleLimit) {
            self::expireAuthentication();
            return;
        }

        $_SESSION['auth_last_seen_at'] = $now;
    }

    private static function rotatePeriodically(): void
    {
        $now = time();
        $rotatedAt = (int) ($_SESSION['session_rotated_at'] ?? 0);
        if ($rotatedAt < 1 || $now - $rotatedAt >= self::ROTATION_SECONDS) {
            session_regenerate_id(true);
            $_SESSION['session_rotated_at'] = $now;
            self::refreshCookie((bool) ($_SESSION['remember_login'] ?? false));
        }
    }

    private static function isHttps(): bool
    {
        if (strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on') {
            return true;
        }

        $appScheme = strtolower((string) parse_url(getenv('APP_URL') ?: '', PHP_URL_SCHEME));
        if ($appScheme === 'https') {
            return true;
        }

        return TrustedProxy::headersTrusted()
            && strtolower((string) (Request::header('X-Forwarded-Proto') ?? '')) === 'https';
    }

    private static function refreshCookie(bool $remember): void
    {
        setcookie(session_name(), session_id(), [
            'expires' => $remember ? time() + self::REMEMBER_SECONDS : 0,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            self::rotate();
        }
        return $_SESSION['csrf_token'];
    }

    public static function rotate(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    public static function assertValid(): void
    {
        $provided = Request::header('X-CSRF-Token');
        if ($provided === null || !hash_equals(self::token(), $provided)) {
            throw new HttpException('Sessão expirada. Atualize a página e tente novamente.', 403, [], 'csrf_invalid');
        }
    }
}

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertAllowed(string $action, string $subject, int $maximum, int $windowSeconds): void
    {
        if (random_int(1, 20) === 1) {
            try {
                $this->cleanupExpired();
            } catch (PDOException $exception) {
                // Expired-key cleanup is maintenance, not part of enforcing this request's limit.
                error_log('Rate-limit cleanup failed (SQLSTATE ' . $exception->getCode() . ').');
            }
        }

        $identifier = hash('sha256', $action . '|' . $subject);
        $statement = $this->pdo->prepare(
            'INSERT INTO rate_limits (action_name, identifier_hash, attempts, window_started_at)
             VALUES (:action, :identifier, 1, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
               attempts = IF(window_started_at < CURRENT_TIMESTAMP - INTERVAL :windowAttempts SECOND, 1, attempts + 1),
               window_started_at = IF(window_started_at < CURRENT_TIMESTAMP - INTERVAL :windowStarted SECOND, CURRENT_TIMESTAMP, window_started_at)'
        );
        $statement->bindValue(':action', $action);
        $statement->bindValue(':identifier', $identifier);
        $statement->bindValue(':windowAttempts', $windowSeconds, PDO::PARAM_INT);
        $statement->bindValue(':windowStarted', $windowSeconds, PDO::PARAM_INT);
        $statement->execute();

        $check = $this->pdo->prepare('SELECT attempts FROM rate_limits WHERE action_name = :action AND identifier_hash = :identifier');
        $check->execute([':action' => $action, ':identifier' => $identifier]);
        if ((int) $check->fetchColumn() > $maximum) {
            header('Retry-After: ' . $windowSeconds);
            throw new HttpException('Muitas tentativas. Aguarde e tente novamente.', 429);
        }
    }

    public function clear(string $action, string $subject): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM rate_limits WHERE action_name = :action AND identifier_hash = :identifier'
        );
        $statement->execute([
            ':action' => $action,
            ':identifier' => hash('sha256', $action . '|' . $subject),
        ]);
    }

    public function cleanupExpired(): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM rate_limits
             WHERE window_started_at < (CURRENT_TIMESTAMP - INTERVAL 2 DAY)
             ORDER BY window_started_at ASC
             LIMIT 500'
        );
        $statement->execute();
        return $statement->rowCount();
    }
}
