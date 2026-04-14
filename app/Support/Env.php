<?php

declare(strict_types=1);

namespace App\Support;

final class Env
{
    private static bool $loaded = false;
    private static ?string $loadedFilePath = null;

    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }

        $envFile = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
        self::$loadedFilePath = $envFile;

        if (!is_file($envFile)) {
            self::logLoadDiagnostics($envFile, false);
            self::$loaded = true;
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            self::logLoadDiagnostics($envFile, true);
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

        self::logLoadDiagnostics($envFile, true);
        self::$loaded = true;
    }

    public static function loadedFilePath(): ?string
    {
        return self::$loadedFilePath;
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

    private static function logLoadDiagnostics(string $envFile, bool $fileFound): void
    {
        $metaAccessToken = self::get('META_ACCESS_TOKEN');
        $instagramAccessToken = self::get('INSTAGRAM_ACCESS_TOKEN');
        $metaAppSecret = self::get('META_APP_SECRET');
        $instagramAppSecret = self::get('INSTAGRAM_APP_SECRET');
        $appSecretSource = $instagramAppSecret !== ''
            ? 'INSTAGRAM_APP_SECRET'
            : ($metaAppSecret !== '' ? 'META_APP_SECRET' : 'none');

        error_log(sprintf(
            'Env load diagnostics: path=%s, found=%s, META_ACCESS_TOKEN present: %s, prefix: %s, INSTAGRAM_ACCESS_TOKEN present: %s, prefix: %s, META_APP_SECRET present: %s, INSTAGRAM_APP_SECRET present: %s, app secret source: %s',
            $envFile,
            $fileFound ? 'yes' : 'no',
            $metaAccessToken !== '' ? 'yes' : 'no',
            self::secretPrefix($metaAccessToken),
            $instagramAccessToken !== '' ? 'yes' : 'no',
            self::secretPrefix($instagramAccessToken),
            $metaAppSecret !== '' ? 'yes' : 'no',
            $instagramAppSecret !== '' ? 'yes' : 'no',
            $appSecretSource
        ));
    }

    private static function secretPrefix(string $value): string
    {
        if ($value === '') {
            return 'n/a';
        }

        return substr($value, 0, 6);
    }
}
