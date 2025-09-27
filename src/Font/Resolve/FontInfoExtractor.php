<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Font\Resolve;

use Celsowm\PagyraPhp\Font\PdfTTFParser;

final class FontInfoExtractor
{
    private int $minBytes;

    public function __construct(int $minBytes = 256)
    {
        $this->minBytes = $minBytes;
    }

    /**
     * @return array{family:string, subfamily:?string, fullName:?string, postscriptName:?string, path:string, weight:int, italic:bool}|null
     */
    public function extract(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $size = @filesize($path);
        if (!is_int($size) || $size < $this->minBytes) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || strlen($raw) < $this->minBytes) {
            return null;
        }

        try {
            $parser = new PdfTTFParser($raw);
        } catch (\Throwable $e) {
            return null;
        }

        try {
            $records = $parser->parseNameRecords();
        } catch (\Throwable $e) {
            $records = [];
        }

        $family = $this->pickName($records, [16, 1]);
        if ($family === null) {
            return null;
        }

        $subfamily = $this->pickName($records, [17, 2]);
        $fullName = $this->pickName($records, [4]);

        try {
            $postscript = $parser->parseNamePostScript();
        } catch (\Throwable $e) {
            $postscript = null;
        }

        $weight = $this->detectWeight($subfamily, $fullName);
        $italic = $this->detectItalic($subfamily, $fullName);

        return [
            'family' => $family,
            'subfamily' => $subfamily,
            'fullName' => $fullName,
            'postscriptName' => $postscript,
            'path' => $path,
            'weight' => $weight,
            'italic' => $italic,
        ];
    }

    /**
     * @param array<int, array{platformId:int, encodingId:int, languageId:int, nameId:int, value:string}> $records
     * @param array<int, int> $nameIds
     */
    private function pickName(array $records, array $nameIds): ?string
    {
        if ($records === []) {
            return null;
        }
        $preferredPlatforms = [3, 0, 1, 2];
        foreach ($nameIds as $nameId) {
            $candidates = array_filter(
                $records,
                static fn(array $rec): bool => $rec['nameId'] === $nameId && trim($rec['value']) !== ''
            );
            if ($candidates === []) {
                continue;
            }
            usort(
                $candidates,
                static function (array $a, array $b) use ($preferredPlatforms): int {
                    $pa = array_search($a['platformId'], $preferredPlatforms, true);
                    $pb = array_search($b['platformId'], $preferredPlatforms, true);
                    $pa = $pa === false ? PHP_INT_MAX : $pa;
                    $pb = $pb === false ? PHP_INT_MAX : $pb;
                    if ($pa !== $pb) {
                        return $pa <=> $pb;
                    }
                    return $a['languageId'] <=> $b['languageId'];
                }
            );
            $value = trim($candidates[0]['value']);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function detectWeight(?string $subfamily, ?string $fullName): int
    {
        $haystack = strtolower(($subfamily ?? '') . ' ' . ($fullName ?? ''));
        $normalized = str_replace(['-', '_'], ' ', $haystack);
        $map = [
            'black' => 900,
            'heavy' => 900,
            'extra bold' => 800,
            'ultra bold' => 800,
            'bold' => 700,
            'semi bold' => 600,
            'semibold' => 600,
            'semi bold' => 600,
            'demi bold' => 600,
            'medium' => 500,
            'book' => 400,
            'regular' => 400,
            'normal' => 400,
            'light' => 300,
            'extra light' => 200,
            'ultra light' => 200,
            'thin' => 200,
            'hairline' => 100,
        ];
        foreach ($map as $needle => $weight) {
            if (str_contains($normalized, $needle)) {
                return $weight;
            }
        }
        return 400;
    }

    private function detectItalic(?string $subfamily, ?string $fullName): bool
    {
        $haystack = strtolower(($subfamily ?? '') . ' ' . ($fullName ?? ''));
        $normalized = str_replace(['-', '_'], ' ', $haystack);
        if (str_contains($normalized, 'italic')) {
            return true;
        }
        if (str_contains($normalized, 'oblique')) {
            return true;
        }
        if (str_contains($normalized, 'kursiv')) {
            return true;
        }
        if (str_contains($normalized, 'slant')) {
            return true;
        }
        return false;
    }
}
