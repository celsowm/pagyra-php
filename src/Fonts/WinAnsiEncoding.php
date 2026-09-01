<?php

declare(strict_types=1);

namespace Pagyra\Fonts;

/**
 * The WinAnsi (CP1252) codepage used for Base14 text in the PDF output.
 *
 * Shared on purpose: the serializer encodes with it and the Base14 metrics measure with it, so
 * a run is measured with the width of the very byte that ends up being written. Keeping two
 * copies of this mapping is how measured and drawn text drift apart.
 */
final class WinAnsiEncoding
{
    /**
     * Byte 0x80-0x9F overrides; every other byte 0x00-0xFF maps to the identical Unicode code
     * point. Mirrors pagyra-js's WIN_ANSI_UNICODE table.
     */
    public const OVERRIDES = [
        0x80=>0x20AC,0x82=>0x201A,0x83=>0x0192,0x84=>0x201E,0x85=>0x2026,0x86=>0x2020,0x87=>0x2021,0x88=>0x02C6,
        0x89=>0x2030,0x8A=>0x0160,0x8B=>0x2039,0x8C=>0x0152,0x8E=>0x017D,0x91=>0x2018,0x92=>0x2019,0x93=>0x201C,
        0x94=>0x201D,0x95=>0x2022,0x96=>0x2013,0x97=>0x2014,0x98=>0x02DC,0x99=>0x2122,0x9A=>0x0161,0x9B=>0x203A,
        0x9C=>0x0153,0x9E=>0x017E,0x9F=>0x0178,
    ];

    /** Replacement byte for a code point outside the codepage: a literal `?`. */
    public const REPLACEMENT = 0x3F;

    /** @var array<int,int>|null */
    private static ?array $unicodeToByte = null;

    /** @return array<int,int> Unicode code point => WinAnsi byte, built once and cached. */
    public static function unicodeToByte(): array
    {
        if (self::$unicodeToByte !== null) return self::$unicodeToByte;
        $map = [];
        for ($byte = 0x00; $byte <= 0xFF; $byte++) {
            $unicode = self::OVERRIDES[$byte] ?? $byte;
            $map[$unicode] ??= $byte;
        }
        return self::$unicodeToByte = $map;
    }

    /** WinAnsi byte for a code point, or null when the codepage has no room for it. */
    public static function byteFor(int $codePoint): ?int
    {
        return self::unicodeToByte()[$codePoint] ?? null;
    }
}
