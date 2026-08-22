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

        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return $this->readWebp($bytes);
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

    private function readWebp(string $bytes): ImageMetadata
    {
        $length = strlen($bytes);
        $offset = 12;

        while ($offset + 8 <= $length) {
            $fourCc = substr($bytes, $offset, 4);
            $chunkSize = $this->uint32le($bytes, $offset + 4);
            $dataOffset = $offset + 8;
            if ($dataOffset + $chunkSize > $length) {
                throw new \InvalidArgumentException('Invalid WebP chunk length');
            }

            if ($fourCc === 'VP8X') {
                if ($chunkSize < 10) {
                    throw new \InvalidArgumentException('Invalid WebP VP8X chunk');
                }

                $width = $this->uint24le($bytes, $dataOffset + 4) + 1;
                $height = $this->uint24le($bytes, $dataOffset + 7) + 1;
                return new ImageMetadata($width, $height, 'webp', 4, 8);
            }

            if ($fourCc === 'VP8L') {
                if ($chunkSize < 5 || ord($bytes[$dataOffset]) !== 0x2f) {
                    throw new \InvalidArgumentException('Invalid WebP VP8L chunk');
                }

                $bits = $this->uint32le($bytes, $dataOffset + 1);
                $width = ($bits & 0x3fff) + 1;
                $height = (($bits >> 14) & 0x3fff) + 1;
                $version = ($bits >> 29) & 0x07;
                if ($version !== 0) {
                    throw new \InvalidArgumentException('Unsupported WebP VP8L version');
                }

                return new ImageMetadata($width, $height, 'webp', 4, 8);
            }

            if ($fourCc === 'VP8 ') {
                if ($chunkSize < 10 || substr($bytes, $dataOffset + 3, 3) !== "\x9d\x01\x2a") {
                    throw new \InvalidArgumentException('Invalid WebP VP8 frame header');
                }

                $width = $this->uint16le($bytes, $dataOffset + 6) & 0x3fff;
                $height = $this->uint16le($bytes, $dataOffset + 8) & 0x3fff;
                if ($width === 0 || $height === 0) {
                    throw new \InvalidArgumentException('Invalid WebP VP8 dimensions');
                }

                return new ImageMetadata($width, $height, 'webp', 3, 8);
            }

            $offset = $dataOffset + $chunkSize + ($chunkSize % 2);
        }

        throw new \InvalidArgumentException('Invalid WebP: missing image chunk');
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
        $this->assertAvailable($bytes, $offset, 2);
        return (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
    }

    private function uint16le(string $bytes, int $offset): int
    {
        $this->assertAvailable($bytes, $offset, 2);
        return ord($bytes[$offset]) | (ord($bytes[$offset + 1]) << 8);
    }

    private function uint24le(string $bytes, int $offset): int
    {
        $this->assertAvailable($bytes, $offset, 3);
        return ord($bytes[$offset])
            | (ord($bytes[$offset + 1]) << 8)
            | (ord($bytes[$offset + 2]) << 16);
    }

    private function uint32be(string $bytes, int $offset): int
    {
        $this->assertAvailable($bytes, $offset, 4);
        return (ord($bytes[$offset]) << 24)
            | (ord($bytes[$offset + 1]) << 16)
            | (ord($bytes[$offset + 2]) << 8)
            | ord($bytes[$offset + 3]);
    }

    private function uint32le(string $bytes, int $offset): int
    {
        $this->assertAvailable($bytes, $offset, 4);
        return ord($bytes[$offset])
            | (ord($bytes[$offset + 1]) << 8)
            | (ord($bytes[$offset + 2]) << 16)
            | (ord($bytes[$offset + 3]) << 24);
    }

    private function assertAvailable(string $bytes, int $offset, int $length): void
    {
        if ($offset < 0 || $offset + $length > strlen($bytes)) {
            throw new \InvalidArgumentException('Unexpected end of image data');
        }
    }
}
