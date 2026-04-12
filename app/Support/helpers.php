<?php

declare(strict_types=1);

function e(string|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function is_post_request(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function flash(string $key, ?string $message = null): ?string
{
    if (!isset($_SESSION['_flash'])) {
        $_SESSION['_flash'] = [];
    }

    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }

    if (!array_key_exists($key, $_SESSION['_flash'])) {
        return null;
    }

    $value = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);

    return $value;
}

function checked(bool $condition): string
{
    return $condition ? 'checked' : '';
}

function selected(string $expected, string $actual): string
{
    return $expected === $actual ? 'selected' : '';
}

function truncate_text(?string $value, int $limit = 120): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 1) . '...';
    }

    if (strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, $limit - 3) . '...';
}
