<?php

declare(strict_types=1);

namespace Pagyra\Image;

final class ImageMetadataReader
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public function read(string $bytes): ImageMetadata
    {
        if (str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return $this->readPng($bytes);
        }

        if (strlen($bytes) >= 2 && ord($bytes[0]) === 0xff && ord($bytes[1]) === 0xd8) {
            return $this->readJpeg($bytes);
        }

        throw new \InvalidArgumentException('Unsupported or invalid image data');
    }

    private function readPng(string $bytes): ImageMetadata
    {
        if (strlen($bytes) < 33 || substr($bytes, 12, 4) !== 'IHDR') {
            throw new \InvalidArgumentException('Invalid PNG: missing IHDR chunk');
        }

        $width = $this->uint32be($bytes, 16);
        $height = $this->uint32be($bytes, 20);
        $bitDepth = ord($bytes[24]);
        $colorType = ord($bytes[25]);

        $channels = match ($colorType) {
            0 => 1,
            2 => 3,
            3 => 1,
            4 => 2,
            6 => 4,
            default => throw new \InvalidArgumentException('Invalid PNG color type'),
        };

        return new ImageMetadata($width, $height, 'png', $channels, $bitDepth);
    }

    private function readJpeg(string $bytes): ImageMetadata
    {
        $length = strlen($bytes);
        $offset = 2;

        while ($offset + 4 <= $length) {
            while ($offset < $length && ord($bytes[$offset]) !== 0xff) {
                $offset++;
            }
            while ($offset < $length && ord($bytes[$offset]) === 0xff) {
                $offset++;
            }
            if ($offset >= $length) {
                break;
            }

            $marker = ord($bytes[$offset++]);
            if ($marker === 0xd9 || $marker === 0xda) {
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xd0 && $marker <= 0xd7)) {
                continue;
            }
            if ($offset + 2 > $length) {
                break;
            }

            $segmentLength = $this->uint16be($bytes, $offset);
            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                throw new \InvalidArgumentException('Invalid JPEG segment length');
            }

            if ($this->isStartOfFrame($marker)) {
                if ($segmentLength < 8) {
                    throw new \InvalidArgumentException('Invalid JPEG SOF segment');
                }

                $precision = ord($bytes[$offset + 2]);
                $height = $this->uint16be($bytes, $offset + 3);
                $width = $this->uint16be($bytes, $offset + 5);
                $channels = ord($bytes[$offset + 7]);

                return new ImageMetadata($width, $height, 'jpeg', $channels, $precision);
            }

            $offset += $segmentLength;
        }

        throw new \InvalidArgumentException('Invalid JPEG: missing SOF marker');
    }

    private function isStartOfFrame(int $marker): bool
    {
        return in_array($marker, [
            0xc0, 0xc1, 0xc2, 0xc3,
            0xc5, 0xc6, 0xc7,
            0xc9, 0xca, 0xcb,
            0xcd, 0xce, 0xcf,
        ], true);
    }

    private function uint16be(string $bytes, int $offset): int
    {
        if ($offset < 0 || $offset + 2 > strlen($bytes)) {
            throw new \InvalidArgumentException('Unexpected end of image data');
        }

        return (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
    }

    private function uint32be(string $bytes, int $offset): int
    {
        if ($offset < 0 || $offset + 4 > strlen($bytes)) {
            throw new \InvalidArgumentException('Unexpected end of image data');
        }

        return (ord($bytes[$offset]) << 24)
            | (ord($bytes[$offset + 1]) << 16)
            | (ord($bytes[$offset + 2]) << 8)
            | ord($bytes[$offset + 3]);
    }
}
