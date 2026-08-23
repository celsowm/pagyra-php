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
        $width = $height = $bitDepth = $colorType = null;
        $idat = '';
        $palette = null;
        $transparency = null;
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
            } elseif ($type === 'PLTE') {
                if ($chunkLength === 0 || ($chunkLength % 3) !== 0 || $chunkLength > 768) return null;
                $palette = substr($bytes, $dataStart, $chunkLength);
            } elseif ($type === 'tRNS') {
                $transparency = substr($bytes, $dataStart, $chunkLength);
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

        if ($colorType === 3 && in_array($bitDepth, [1, 2, 4, 8], true) && $palette !== null) {
            return $this->indexed($width, $height, $bitDepth, $idat, $palette, $transparency);
        }

        if ($bitDepth === 8 && in_array($colorType, [4, 6], true)) {
            return $this->splitAlpha($width, $height, $colorType, $idat);
        }

        return null;
    }

    private function indexed(
        int $width,
        int $height,
        int $bitDepth,
        string $idat,
        string $palette,
        ?string $transparency,
    ): ?PngPdfImageData {
        $entryCount = intdiv(strlen($palette), 3);
        if ($entryCount <= 0 || $entryCount > 256) return null;

        $alphaCompressed = null;
        if ($transparency !== null && $transparency !== '') {
            $alphaCompressed = $this->indexedAlpha($width, $height, $bitDepth, $idat, $transparency);
            if ($alphaCompressed === false) return null;
        }

        $colorSpace = '[/Indexed /DeviceRGB ' . ($entryCount - 1) . ' <' . strtoupper(bin2hex($palette)) . '>]';

        return new PngPdfImageData(
            width: $width,
            height: $height,
            bitsPerComponent: $bitDepth,
            colors: 1,
            colorSpace: $colorSpace,
            compressedData: $idat,
            usesPngPredictor: true,
            alphaCompressedData: is_string($alphaCompressed) ? $alphaCompressed : null,
        );
    }

    /** @return string|false|null */
    private function indexedAlpha(int $width, int $height, int $bitDepth, string $idat, string $transparency): string|false|null
    {
        $hasTransparency = false;
        for ($i = 0, $length = strlen($transparency); $i < $length; $i++) {
            if (ord($transparency[$i]) < 255) {
                $hasTransparency = true;
                break;
            }
        }
        if (!$hasTransparency) return null;

        $decoded = @gzuncompress($idat);
        if (!is_string($decoded)) return false;

        $rowBytes = intdiv($width * $bitDepth + 7, 8);
        if (strlen($decoded) !== $height * ($rowBytes + 1)) return false;

        $previous = array_fill(0, $rowBytes, 0);
        $alpha = '';
        $cursor = 0;
        for ($row = 0; $row < $height; $row++) {
            $filter = ord($decoded[$cursor++]);
            if ($filter < 0 || $filter > 4) return false;
            $raw = substr($decoded, $cursor, $rowBytes);
            $cursor += $rowBytes;
            $reconstructed = $this->unfilter($raw, $previous, 1, $filter);
            if ($reconstructed === null) return false;

            for ($x = 0; $x < $width; $x++) {
                $index = $this->packedIndex($reconstructed, $x, $bitDepth);
                $alpha .= chr($index < strlen($transparency) ? ord($transparency[$index]) : 255);
            }
            $previous = $reconstructed;
        }

        $compressed = gzcompress($alpha);
        return is_string($compressed) ? $compressed : false;
    }

    /** @param list<int> $row */
    private function packedIndex(array $row, int $pixel, int $bitDepth): int
    {
        if ($bitDepth === 8) return $row[$pixel] ?? 0;
        $pixelsPerByte = intdiv(8, $bitDepth);
        $byte = $row[intdiv($pixel, $pixelsPerByte)] ?? 0;
        $shift = (8 - $bitDepth) - (($pixel % $pixelsPerByte) * $bitDepth);
        return ($byte >> $shift) & ((1 << $bitDepth) - 1);
    }

    private function splitAlpha(int $width, int $height, int $colorType, string $idat): ?PngPdfImageData
    {
        $decoded = @gzuncompress($idat);
        if (!is_string($decoded)) return null;

        $channels = $colorType === 6 ? 4 : 2;
        $colorChannels = $colorType === 6 ? 3 : 1;
        $rowBytes = $width * $channels;
        $expected = $height * ($rowBytes + 1);
        if (strlen($decoded) !== $expected) return null;

        $previous = array_fill(0, $rowBytes, 0);
        $color = '';
        $alpha = '';
        $cursor = 0;

        for ($row = 0; $row < $height; $row++) {
            $filter = ord($decoded[$cursor++]);
            if ($filter < 0 || $filter > 4) return null;

            $raw = substr($decoded, $cursor, $rowBytes);
            $cursor += $rowBytes;
            $reconstructed = $this->unfilter($raw, $previous, $channels, $filter);
            if ($reconstructed === null) return null;

            for ($x = 0; $x < $width; $x++) {
                $base = $x * $channels;
                if ($colorType === 6) {
                    $color .= chr($reconstructed[$base])
                        . chr($reconstructed[$base + 1])
                        . chr($reconstructed[$base + 2]);
                    $alpha .= chr($reconstructed[$base + 3]);
                } else {
                    $color .= chr($reconstructed[$base]);
                    $alpha .= chr($reconstructed[$base + 1]);
                }
            }

            $previous = $reconstructed;
        }

        $colorCompressed = gzcompress($color);
        $alphaCompressed = gzcompress($alpha);
        if (!is_string($colorCompressed) || !is_string($alphaCompressed)) return null;

        return new PngPdfImageData(
            width: $width,
            height: $height,
            bitsPerComponent: 8,
            colors: $colorChannels,
            colorSpace: $colorType === 6 ? '/DeviceRGB' : '/DeviceGray',
            compressedData: $colorCompressed,
            usesPngPredictor: false,
            alphaCompressedData: $alphaCompressed,
        );
    }

    /** @param list<int> $previous @return list<int>|null */
    private function unfilter(string $raw, array $previous, int $bytesPerPixel, int $filter): ?array
    {
        $length = strlen($raw);
        $row = [];

        for ($i = 0; $i < $length; $i++) {
            $value = ord($raw[$i]);
            $left = $i >= $bytesPerPixel ? $row[$i - $bytesPerPixel] : 0;
            $up = $previous[$i] ?? 0;
            $upLeft = $i >= $bytesPerPixel ? ($previous[$i - $bytesPerPixel] ?? 0) : 0;

            $predictor = match ($filter) {
                0 => 0,
                1 => $left,
                2 => $up,
                3 => intdiv($left + $up, 2),
                4 => $this->paeth($left, $up, $upLeft),
                default => null,
            };
            if ($predictor === null) return null;
            $row[$i] = ($value + $predictor) & 0xff;
        }

        return $row;
    }

    private function paeth(int $left, int $up, int $upLeft): int
    {
        $p = $left + $up - $upLeft;
        $pa = abs($p - $left);
        $pb = abs($p - $up);
        $pc = abs($p - $upLeft);
        if ($pa <= $pb && $pa <= $pc) return $left;
        if ($pb <= $pc) return $up;
        return $upLeft;
    }

    private function u32(string $bytes, int $offset): int
    {
        $value = unpack('N', substr($bytes, $offset, 4));
        return (int) ($value[1] ?? 0);
    }
}
