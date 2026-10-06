<?php

declare(strict_types=1);

final class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
        public readonly ?string $errorCode = null
    ) {
        parent::__construct($message);
    }
}

final class Request
{
    public static function json(int $maximumBytes = 65_536): array
    {
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') {
            throw new HttpException('Use Content-Type application/json.', 415);
        }

        $raw = self::rawBody($maximumBytes);

        try {
            $data = json_decode($raw === '' ? '{}' : $raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException('JSON inválido.', 400);
        }

        if (!$data instanceof stdClass) {
            throw new HttpException('O corpo JSON deve ser um objeto.', 400);
        }

        return self::normalizeObject($data);
    }

    public static function rawBody(int $maximumBytes = 65_536, string $tooLargeMessage = 'Corpo da requisição muito grande.'): string
    {
        if ($maximumBytes < 0) {
            throw new InvalidArgumentException('The request body limit cannot be negative.');
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maximumBytes) {
            throw new HttpException($tooLargeMessage, 413);
        }

        $stream = @fopen('php://input', 'rb');
        if ($stream === false) {
            throw new HttpException('Não foi possível ler o corpo da requisição.', 400);
        }
        try {
            return self::readBoundedBody($stream, $maximumBytes, $tooLargeMessage);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    public static function readBoundedBody($stream, int $maximumBytes, string $tooLargeMessage = 'Corpo da requisição muito grande.'): string
    {
        if (!is_resource($stream) || $maximumBytes < 0) {
            throw new InvalidArgumentException('A readable stream and non-negative body limit are required.');
        }

        // Read only one byte beyond the limit so oversized chunked/unknown-length bodies
        // are rejected without buffering the complete attacker-controlled request.
        $body = stream_get_contents($stream, $maximumBytes + 1);
        if ($body === false) {
            throw new HttpException('Não foi possível ler o corpo da requisição.', 400);
        }
        if (strlen($body) > $maximumBytes) {
            throw new HttpException($tooLargeMessage, 413);
        }
        return $body;
    }

    public static function ip(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }
        if (!TrustedProxy::headersTrusted()) {
            return $remote;
        }

        $forwarded = self::header('X-Forwarded-For');
        if ($forwarded === null || strlen($forwarded) > 4096) {
            return $remote;
        }

        $forwardedChain = explode(',', $forwarded);
        if (count($forwardedChain) > 32) {
            return $remote;
        }

        $client = $remote;
        for ($index = count($forwardedChain) - 1; $index >= 0; $index--) {
            if (!TrustedProxy::isTrustedIp($client)) {
                break;
            }

            $hop = trim($forwardedChain[$index]);
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }
            $client = $hop;
        }
        return $client;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function normalizeObject(stdClass $object): array
    {
        $normalized = [];
        foreach (get_object_vars($object) as $key => $value) {
            $normalized[$key] = self::normalizeValue($value);
        }
        return $normalized;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return self::normalizeObject($value);
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalizeValue($item), $value);
        }
        return $value;
    }
}

final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }
}

final class Router
{
    /** @var array<string, callable> */
    private array $routes = [];

    /** @var array<string, array<string, true>> */
    private array $methodsByPath = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $normalizedMethod = strtoupper($method);
        $this->routes[$normalizedMethod . ' ' . $path] = $handler;
        $this->methodsByPath[$path][$normalizedMethod] = true;
    }

    public function dispatch(string $method, string $path): never
    {
        $handler = $this->routes[strtoupper($method) . ' ' . $path] ?? null;
        if ($handler === null) {
            if (isset($this->methodsByPath[$path])) {
                $allowed = array_keys($this->methodsByPath[$path]);
                sort($allowed);
                header('Allow: ' . implode(', ', $allowed));
                throw new HttpException('Método não permitido.', 405);
            }
            throw new HttpException('Rota não encontrada.', 404);
        }

        $result = $handler();
        if (is_array($result) && array_key_exists('_status', $result)) {
            $status = (int) $result['_status'];
            unset($result['_status']);
            Response::json($result, $status);
        }

        Response::json(is_array($result) ? $result : ['data' => $result]);
    }
}
