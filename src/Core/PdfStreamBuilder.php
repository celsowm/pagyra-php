<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;

final class PdfStreamBuilder
{
    public static function streamObj(string $data): string
    {
        return "<< /Length " . strlen($data) . " >>\nstream\n{$data}\nendstream";
    }

    public static function streamObjWithDict(string $dict, string $data): string
    {
        $L = strlen($data);
        $d = trim($dict);
        if ($d === '' || !str_starts_with($d, '<<')) {
            $d = '<< ' . $d . ' >>';
        }
        $d = rtrim($d);
        if (substr($d, -2) === '>>') {
            $d = substr($d, 0, -2) . " /Length {$L} >>";
        }
        return "{$d}\nstream\n{$data}\nendstream";
    }
}