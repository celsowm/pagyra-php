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
        if ($source === null || trim($source) === '') {
            return null;
        }

        $source = trim($source);
        $bytes = $this->dataUrlBytes($source) ?? $this->localFileBytes($source);
        if ($bytes === null) {
            return null;
        }

        try {
            return $this->reader->read($bytes);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function dataUrlBytes(string $source): ?string
    {
        if (preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/is', $source, $matches) !== 1) {
            return null;
        }

        $bytes = base64_decode(preg_replace('/\s+/', '', $matches[2]) ?? '', true);
        return $bytes === false ? null : $bytes;
    }

    private function localFileBytes(string $source): ?string
    {
        $path = $source;
        if (str_starts_with(strtolower($source), 'file://')) {
            $path = rawurldecode(substr($source, 7));
            if (preg_match('/^\/[a-zA-Z]:[\\\/]/', $path) === 1) {
                $path = substr($path, 1);
            }
        } elseif (!$this->isAbsolutePath($source)) {
            return null;
        }

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);
        return is_string($bytes) ? $bytes : null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[a-zA-Z]:[\\\/]/', $path) === 1;
    }
}
