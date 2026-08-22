<?php

declare(strict_types=1);

namespace Pagyra\Image;

final readonly class ImageMetadata
{
    public function __construct(
        public int $width,
        public int $height,
        public string $format,
        public int $channels,
        public int $bitsPerChannel,
    ) {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Image dimensions must be positive');
        }

        if (!in_array($format, ['png', 'jpeg'], true)) {
            throw new \InvalidArgumentException('Unsupported image metadata format');
        }

        if ($channels <= 0 || $bitsPerChannel <= 0) {
            throw new \InvalidArgumentException('Image channel metadata must be positive');
        }
    }
}
