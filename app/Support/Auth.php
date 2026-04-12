<?php

declare(strict_types=1);

namespace App\Support;

final class Auth
{
    private const SESSION_KEY = 'admin_authenticated';

    public static function check(): bool
    {
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    public static function attempt(string $username, string $password): bool
    {
        $expectedUsername = Env::get('ADMIN_USERNAME');
        $expectedHash = Env::get('ADMIN_PASSWORD_HASH');
        $expectedPassword = Env::get('ADMIN_PASSWORD');

        if ($expectedUsername === '' || $username !== $expectedUsername) {
            return false;
        }

        $isValid = false;

        if ($expectedHash !== '') {
            $isValid = password_verify($password, $expectedHash);
        } elseif ($expectedPassword !== '') {
            $isValid = hash_equals($expectedPassword, $password);
        }

        if (!$isValid) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = true;
        $_SESSION['admin_username'] = $expectedUsername;

        return true;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            flash('error', 'Please log in to continue.');
            redirect('login.php');
        }
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION['admin_username']);
        session_regenerate_id(true);
    }
}
