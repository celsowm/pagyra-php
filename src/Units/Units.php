<?php

declare(strict_types=1);

namespace Pagyra\Units;

final class Units
{
    public const DPI = 96.0;

    private function __construct()
    {
    }

    public static function cmToPx(float $cm): float
    {
        return $cm * (1 / 2.54) * self::DPI;
    }

    public static function mmToPx(float $mm): float
    {
        return ($mm / 10) * (1 / 2.54) * self::DPI;
    }

    public static function qToPx(float $q): float
    {
        return ($q / 40) * (1 / 2.54) * self::DPI;
    }

    public static function inToPx(float $inches): float
    {
        return $inches * self::DPI;
    }

    public static function pcToPx(float $pc): float
    {
        return $pc * (self::DPI / 6);
    }

    public static function ptToPx(float $pt): float
    {
        return $pt * (self::DPI / 72);
    }

    public static function pxToPt(float $px): float
    {
        return $px * (72 / self::DPI);
    }
}
