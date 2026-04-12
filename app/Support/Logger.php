<?php

declare(strict_types=1);

namespace App\Support;

final class Logger
{
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    private static function write(string $level, string $message, array $context = []): void
    {
        $logDirectory = dirname(__DIR__, 2) . '/storage/logs';

        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0775, true);
        }

        $contextJson = $context === []
            ? ''
            : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $line = sprintf("[%s] %s %s%s\n", gmdate('c'), $level, $message, $contextJson);
        error_log($line, 3, $logDirectory . '/instagram_bot.log');
    }
}
