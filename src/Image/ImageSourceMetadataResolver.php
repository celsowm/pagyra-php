<?php

declare(strict_types=1);

namespace Pagyra\Image;

final class ImageSourceMetadataResolver
{
    public function __construct(
        private readonly ImageMetadataReader $reader = new ImageMetadataReader(),
        private readonly ImageSourceBytesResolver $sourceBytes = new ImageSourceBytesResolver(),
    ) {
    }

    public function resolve(?string $source): ?ImageMetadata
    {
        $bytes = $this->sourceBytes->resolve($source);
        if ($bytes === null) {
            return null;
        }

        try {
            return $this->reader->read($bytes);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
