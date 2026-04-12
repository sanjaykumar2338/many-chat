<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/Env.php';
require_once __DIR__ . '/Support/helpers.php';

use App\Support\Env;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

Env::load(dirname(__DIR__));

$timezone = Env::get('APP_TIMEZONE', 'UTC');
date_default_timezone_set($timezone !== '' ? $timezone : 'UTC');

$isDebug = Env::bool('APP_DEBUG', false);
error_reporting(E_ALL);
ini_set('display_errors', $isDebug ? '1' : '0');

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    session_name(Env::get('SESSION_NAME', 'randy_ig_bot'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
