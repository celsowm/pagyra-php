<?php

declare(strict_types=1);

namespace Pagyra\Image;

final class ImageSourceBytesResolver
{
    public function resolve(?string $source): ?string
    {
        if ($source === null || trim($source) === '') {
            return null;
        }

        $source = trim($source);
        return $this->dataUrlBytes($source) ?? $this->localFileBytes($source);
    }

    private function dataUrlBytes(string $source): ?string
    {
        if (!str_starts_with(strtolower($source), 'data:image/')) {
            return null;
        }

        $comma = strpos($source, ',');
        if ($comma === false) {
            return null;
        }

        $metadata = strtolower(substr($source, 5, $comma - 5));
        $mime = strtolower(trim(strtok($metadata, ';') ?: ''));
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/svg+xml'], true)) {
            return null;
        }

        $payload = substr($source, $comma + 1);
        if (str_contains($metadata, ';base64')) {
            $bytes = base64_decode(preg_replace('/\s+/', '', $payload) ?? '', true);
            return $bytes === false ? null : $bytes;
        }

        return rawurldecode($payload);
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
