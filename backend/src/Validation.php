<?php

declare(strict_types=1);

final class Validation
{
    public static function allowedFields(array $input, array $allowed): void
    {
        $unexpected = array_values(array_diff(array_keys($input), $allowed));
        if ($unexpected !== []) {
            throw new HttpException('O corpo contém campos não permitidos.', 422, [
                'fields' => implode(', ', $unexpected),
            ]);
        }
    }

    public static function requiredString(array $input, string $field, int $minimum, int $maximum): string
    {
        if (!array_key_exists($field, $input) || !is_string($input[$field])) {
            throw new HttpException("Campo inválido: {$field}.", 422, [$field => 'Informe um texto válido.']);
        }
        self::assertSafeText($input[$field], $field);
        $value = trim($input[$field]);
        $length = mb_strlen($value);
        if ($length < $minimum || $length > $maximum) {
            throw new HttpException("Campo inválido: {$field}.", 422, [$field => "Use entre {$minimum} e {$maximum} caracteres."]);
        }
        return $value;
    }

    public static function email(array $input, string $field = 'email'): string
    {
        if (!array_key_exists($field, $input) || !is_string($input[$field])) {
            throw new HttpException('E-mail inválido.', 422, [$field => 'Informe um e-mail válido.']);
        }
        $email = strtolower(trim($input[$field]));
        if (strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('E-mail inválido.', 422, [$field => 'Informe um e-mail válido.']);
        }
        return $email;
    }

    public static function password(array $input, string $field = 'password'): string
    {
        if (!array_key_exists($field, $input) || !is_string($input[$field])) {
            throw new HttpException('Senha inválida.', 422, [$field => 'Informe uma senha válida.']);
        }
        $password = $input[$field];
        $valid = strlen($password) >= 12
            && strlen($password) <= 128
            && preg_match('/[a-z]/', $password)
            && preg_match('/[A-Z]/', $password)
            && preg_match('/\d/', $password)
            && preg_match('/[^A-Za-z0-9]/', $password);

        if (!$valid) {
            throw new HttpException(
                'A senha deve ter de 12 a 128 caracteres, com maiúscula, minúscula, número e símbolo.',
                422,
                [$field => 'Use 12+ caracteres, maiúscula, minúscula, número e símbolo.']
            );
        }
        return $password;
    }

    public static function oneOf(array $input, string $field, array $allowed): string
    {
        if (!array_key_exists($field, $input) || !is_string($input[$field])) {
            throw new HttpException("Campo inválido: {$field}.", 422, [$field => 'Valor não permitido.']);
        }
        $value = $input[$field];
        if (!in_array($value, $allowed, true)) {
            throw new HttpException("Campo inválido: {$field}.", 422, [$field => 'Valor não permitido.']);
        }
        return $value;
    }

    public static function boolean(array $input, string $field, bool $default = false): bool
    {
        if (!array_key_exists($field, $input)) {
            return $default;
        }
        if (!is_bool($input[$field])) {
            throw new HttpException("Campo inválido: {$field}.", 422);
        }
        return $input[$field];
    }

    public static function optionalString(array $input, string $field, int $maximum): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            return null;
        }
        if (!is_string($input[$field])) {
            throw new HttpException("Campo inválido: {$field}.", 422, [$field => 'Informe um texto válido.']);
        }
        self::assertSafeText($input[$field], $field);
        $value = trim($input[$field]);
        if (mb_strlen($value) > $maximum) {
            throw new HttpException("Campo inválido: {$field}.", 422, [$field => "Use no máximo {$maximum} caracteres."]);
        }
        return $value === '' ? null : $value;
    }

    public static function integer(array $input, string $field, int $minimum, int $maximum): int
    {
        $value = $input[$field] ?? null;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new HttpException("Campo inválido: {$field}.", 422, [$field => "Use um número inteiro entre {$minimum} e {$maximum}."]);
        }
        return $value;
    }

    public static function money(array $input, string $field = 'price'): string
    {
        $value = $input[$field] ?? null;
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new HttpException("Campo inválido: {$field}.", 422, [$field => 'Informe um valor monetário válido.']);
        }
        $normalized = is_string($value) ? trim(str_replace(',', '.', $value)) : (string) $value;
        if (!preg_match('/^(?:0|[1-9]\d{0,5})(?:\.\d{1,2})?$/', $normalized) || (float) $normalized < 0.01) {
            throw new HttpException("Campo inválido: {$field}.", 422, [$field => 'Use um valor entre 0,01 e 999999,99.']);
        }
        return number_format((float) $normalized, 2, '.', '');
    }

    public static function queryInteger(array $query, string $field, int $default, int $minimum, int $maximum): int
    {
        if (!array_key_exists($field, $query)) {
            return $default;
        }
        $value = $query[$field];
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(?:0|[1-9]\d*)$/D', $value)) {
            $integer = (int) $value;
        } else {
            throw new HttpException("Parâmetro inválido: {$field}.", 422);
        }
        if ($integer < $minimum || $integer > $maximum) {
            throw new HttpException("Parâmetro inválido: {$field}.", 422);
        }
        return $integer;
    }

    public static function queryBoolean(array $query, string $field): ?bool
    {
        if (!array_key_exists($field, $query)) {
            return null;
        }
        $value = $query[$field];
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
            return false;
        }
        throw new HttpException("Parâmetro inválido: {$field}.", 422);
    }

    public static function querySlug(array $query, string $field): ?string
    {
        if (!array_key_exists($field, $query)) {
            return null;
        }
        $value = $query[$field];
        if (!is_string($value) || strlen($value) > 160 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value)) {
            throw new HttpException("Invalid query parameter: {$field}.", 422);
        }
        return $value;
    }

    private static function assertSafeText(string $value, string $field): void
    {
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            throw new HttpException("Campo inválido: {$field}.", 422, [
                $field => 'O texto contém caracteres de controle não permitidos.',
            ]);
        }
    }
}
