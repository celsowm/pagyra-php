<?php

declare(strict_types=1);

namespace Pagyra\Pdf;

final class PngPdfImageParser
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    public function parse(string $bytes): ?PngPdfImageData
    {
        if (!str_starts_with($bytes, self::SIGNATURE) || strlen($bytes) < 33) {
            return null;
        }

        $offset = 8;
        $width = $height = $bitDepth = $colorType = $interlace = null;
        $idat = '';
        $length = strlen($bytes);

        while ($offset + 12 <= $length) {
            $chunkLength = $this->u32($bytes, $offset);
            $type = substr($bytes, $offset + 4, 4);
            $dataStart = $offset + 8;
            $dataEnd = $dataStart + $chunkLength;
            if ($dataEnd + 4 > $length) return null;

            if ($type === 'IHDR') {
                if ($chunkLength !== 13) return null;
                $width = $this->u32($bytes, $dataStart);
                $height = $this->u32($bytes, $dataStart + 4);
                $bitDepth = ord($bytes[$dataStart + 8]);
                $colorType = ord($bytes[$dataStart + 9]);
                $compression = ord($bytes[$dataStart + 10]);
                $filter = ord($bytes[$dataStart + 11]);
                $interlace = ord($bytes[$dataStart + 12]);
                if ($compression !== 0 || $filter !== 0 || $interlace !== 0) return null;
            } elseif ($type === 'IDAT') {
                $idat .= substr($bytes, $dataStart, $chunkLength);
            } elseif ($type === 'IEND') {
                break;
            }

            $offset = $dataEnd + 4;
        }

        if ($width === null || $height === null || $bitDepth === null || $colorType === null || $idat === '' || $width <= 0 || $height <= 0) {
            return null;
        }

        if ($colorType === 0 && in_array($bitDepth, [1, 2, 4, 8, 16], true)) {
            return new PngPdfImageData($width, $height, $bitDepth, 1, '/DeviceGray', $idat);
        }

        if ($colorType === 2 && in_array($bitDepth, [8, 16], true)) {
            return new PngPdfImageData($width, $height, $bitDepth, 3, '/DeviceRGB', $idat);
        }

        return null;
    }

    private function u32(string $bytes, int $offset): int
    {
        $value = unpack('N', substr($bytes, $offset, 4));
        return (int) ($value[1] ?? 0);
    }
}
