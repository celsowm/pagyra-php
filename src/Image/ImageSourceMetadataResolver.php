<?php

declare(strict_types=1);

namespace Pagyra\Image;

final class ImageSourceMetadataResolver
{
    public function __construct(private readonly ImageMetadataReader $reader = new ImageMetadataReader())
    {
    }

    public function resolve(?string $source): ?ImageMetadata
    {
        if ($source === null || $source === '') {
            return null;
        }

        if (preg_match('/^data:image\/(png|jpeg|jpg);base64,(.+)$/is', $source, $matches) !== 1) {
            return null;
        }

        $bytes = base64_decode(preg_replace('/\s+/', '', $matches[2]) ?? '', true);
        if ($bytes === false) {
            return null;
        }

        try {
            return $this->reader->read($bytes);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
