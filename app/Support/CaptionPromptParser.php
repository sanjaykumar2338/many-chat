<?php

declare(strict_types=1);

namespace App\Support;

final class CaptionPromptParser
{
    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, '\'') && str_ends_with($value, '\''))
        ) {
            $value = trim(substr($value, 1, -1));
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value);
        }

        return strtolower($value);
    }

    public static function extractPromptKeyword(?string $caption): ?string
    {
        $caption = trim((string) $caption);

        if ($caption === '') {
            return null;
        }

        $patterns = [
            '/\bcomment\b\s*:?\s*"([^"]+)"/iu',
            '/\bcomment\b\s*:?\s*\'([^\']+)\'/iu',
            '/\bcomment\b\s*:?\s*([^\s"\'.,!?;:()]+)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $caption, $matches)) {
                continue;
            }

            $keyword = self::normalize($matches[1] ?? null);

            if ($keyword !== '') {
                return $keyword;
            }
        }

        return null;
    }
}
