<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = Env::get('DB_HOST');
        $port = Env::int('DB_PORT', 3306);
        $database = Env::get('DB_NAME');
        $username = Env::get('DB_USER');
        $password = Env::get('DB_PASS');

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('Database configuration is incomplete. Check your .env file.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

        try {
            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Could not connect to MySQL. ' . $exception->getMessage(),
                (int) $exception->getCode(),
                $exception
            );
        }

        return self::$connection;
    }
}
