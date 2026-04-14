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
        $logFile = $logDirectory . '/instagram_bot.log';

        $contextJson = $context === []
            ? ''
            : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $line = sprintf("[%s] %s %s%s\n", gmdate('c'), $level, $message, $contextJson);

        try {
            if (!is_dir($logDirectory) && !@mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)) {
                error_log($line);
                return;
            }

            if (!is_writable(dirname($logFile))) {
                error_log($line);
                return;
            }

            @error_log($line, 3, $logFile);
        } catch (\Throwable) {
            error_log($line);
        }
    }
}
