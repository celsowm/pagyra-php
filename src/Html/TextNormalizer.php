<?php

declare(strict_types=1);

namespace Pagyra\Html;

final class TextNormalizer
{
    private function __construct()
    {
    }

    public static function collapse(string $text): string
    {
        return preg_replace('/[\t\n\f\r ]+/u', ' ', $text) ?? $text;
    }

    public static function isWhitespaceOnly(string $text): bool
    {
        return trim(self::collapse($text)) === '';
    }
}
