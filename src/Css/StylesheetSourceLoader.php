<?php

declare(strict_types=1);

namespace Pagyra\Css;

final readonly class StylesheetSourceLoader
{
    public function __construct(private ?string $resourceBaseDir = null)
    {
    }

    public function load(string $href): string
    {
        $path = $this->resolvePath($href);
        if ($path === null || !is_file($path) || !is_readable($path)) {
            return '';
        }

        $css = @file_get_contents($path);
        if (!is_string($css)) {
            return '';
        }

        return $this->rewriteRelativeUrls($css, dirname($path));
    }

    private function resolvePath(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('/^(?:https?:)?\/\//i', $href) === 1) {
            return null;
        }

        if (str_starts_with(strtolower($href), 'file://')) {
            $path = rawurldecode(substr($href, 7));
            if (preg_match('/^\/[a-zA-Z]:[\\\/]/', $path) === 1) {
                $path = substr($path, 1);
            }
            return $path;
        }

        if ($this->isAbsolutePath($href)) {
            return rawurldecode($href);
        }

        if ($this->resourceBaseDir === null) {
            return null;
        }

        $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rawurldecode($href));
        return rtrim($this->resourceBaseDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($relative, '/\\');
    }

    private function rewriteRelativeUrls(string $css, string $baseDir): string
    {
        return preg_replace_callback(
            '/url\(\s*([\'\"]?)([^\'\")]+)\1\s*\)/i',
            function (array $matches) use ($baseDir): string {
                $candidate = trim($matches[2]);
                if ($candidate === ''
                    || str_starts_with(strtolower($candidate), 'data:')
                    || preg_match('/^[a-z][a-z0-9+.-]*:/i', $candidate) === 1
                    || str_starts_with($candidate, '//')) {
                    return $matches[0];
                }

                $resolved = $this->normalizePath(
                    rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR
                    . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rawurldecode($candidate)),
                );
                $uriPath = str_replace(DIRECTORY_SEPARATOR, '/', $resolved);
                if (preg_match('/^[a-zA-Z]:\//', $uriPath) === 1) {
                    $uriPath = '/' . $uriPath;
                }
                $fileUri = 'file://' . implode('/', array_map('rawurlencode', explode('/', $uriPath)));
                $quote = $matches[1];
                return 'url(' . $quote . $fileUri . $quote . ')';
            },
            $css,
        ) ?? $css;
    }

    private function normalizePath(string $path): string
    {
        $separator = DIRECTORY_SEPARATOR;
        $prefix = '';
        if ($this->isAbsolutePath($path)) {
            if (preg_match('/^[a-zA-Z]:[\\\/]/', $path) === 1) {
                $prefix = substr($path, 0, 2);
                $path = substr($path, 2);
            } elseif (str_starts_with($path, $separator)) {
                $prefix = $separator;
            }
        }

        $parts = preg_split('/[\\\\\/]+/', $path) ?: [];
        $stack = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $part;
        }

        $joined = implode($separator, $stack);
        return $prefix === '' ? $joined : $prefix . ($prefix === $separator ? '' : $separator) . $joined;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[a-zA-Z]:[\\\/]/', $path) === 1;
    }
}
