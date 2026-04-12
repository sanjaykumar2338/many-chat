<?php

declare(strict_types=1);

namespace App\Support;

final class Env
{
    private static bool $loaded = false;

    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }

        $envFile = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($envFile)) {
            self::$loaded = true;
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            self::$loaded = true;
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $trimmed, 2), 2, '');
            $name = trim($name);
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, '\'') && str_ends_with($value, '\''))
            ) {
                $value = substr($value, 1, -1);
            }

            if (array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER)) {
                continue;
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name . '=' . $value);
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default ?? '';
        }

        return (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        if ($value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }
}
