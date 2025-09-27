<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Font\Cache;

final class SystemFontCache
{
    private string $cacheFile;

    public function __construct(?string $cacheFile = null)
    {
        $this->cacheFile = $cacheFile ?? $this->defaultCacheFile();
    }

    /**
     * @return array{signature:string, fonts:array<int, array<string, mixed>>}|null
     */
    public function load(): ?array
    {
        if (!is_file($this->cacheFile) || !is_readable($this->cacheFile)) {
            return null;
        }
        $raw = @file_get_contents($this->cacheFile);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['signature']) || !isset($decoded['fonts'])) {
            return null;
        }
        if (!is_array($decoded['fonts'])) {
            return null;
        }
        return [
            'signature' => (string)$decoded['signature'],
            'fonts' => $decoded['fonts'],
        ];
    }

    /**
     * @param array{signature:string, fonts:array<int, array<string, mixed>>} $payload
     */
    public function store(array $payload): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return;
        }
        @file_put_contents($this->cacheFile, $json);
    }

    public function clear(): void
    {
        if (is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    private function defaultCacheFile(): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        return $base . DIRECTORY_SEPARATOR . 'pagyra-font-cache-v1.json';
    }
}
