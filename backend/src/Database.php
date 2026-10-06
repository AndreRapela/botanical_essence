<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function connect(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = getenv('DB_HOST') ?: 'mysql';
        $port = getenv('DB_PORT') ?: '3306';
        $database = getenv('DB_DATABASE') ?: 'botanical_essence';
        $user = getenv('DB_USERNAME') ?: 'botanical';
        $password = getenv('DB_PASSWORD');

        if ($password === false || $password === '') {
            throw new RuntimeException('DB_PASSWORD must be configured.');
        }

        self::$connection = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_PERSISTENT => false,
            ]
        );
        self::$connection->exec("SET time_zone = '+00:00'");

        return self::$connection;
    }

    public static function disconnect(): void
    {
        self::$connection = null;
    }

    public static function assertHealthy(PDO $connection): void
    {
        $connection->query('SELECT 1');
    }
}
