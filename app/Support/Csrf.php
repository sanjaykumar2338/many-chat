<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::SESSION_KEY];
    }

    public static function validate(?string $token): void
    {
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';

        if (!is_string($token) || $token === '' || !hash_equals((string) $sessionToken, $token)) {
            throw new RuntimeException('The form security token is invalid. Please refresh the page and try again.');
        }
    }
}
