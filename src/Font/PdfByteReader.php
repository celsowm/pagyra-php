<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Font;

final class PdfByteReader
{
    public static function be16(string $s, int $o): int
    {
        return (ord($s[$o]) << 8) | ord($s[$o + 1]);
    }

    public static function beS16(string $s, int $o): int
    {
        $v = self::be16($s, $o);
        return ($v & 0x8000) ? ($v - 0x10000) : $v;
    }

    public static function beU32(string $s, int $o): int
    {
        return ((ord($s[$o]) << 24) | (ord($s[$o + 1]) << 16) | (ord($s[$o + 2]) << 8) | ord($s[$o + 3])) & 0xFFFFFFFF;
    }

    public static function beS32(string $s, int $o): int
    {
        $v = self::beU32($s, $o);
        return ($v & 0x80000000) ? ($v - 0x100000000) : $v;
    }
}