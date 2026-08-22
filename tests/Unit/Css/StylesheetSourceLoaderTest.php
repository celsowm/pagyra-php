<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\StylesheetSourceLoader;
use PHPUnit\Framework\TestCase;

final class StylesheetSourceLoaderTest extends TestCase
{
    public function testRewritesRelativeUrlsAgainstStylesheetDirectory(): void
    {
        $root = $this->temporaryDirectory();
        $cssDir = $root . '/styles';
        mkdir($cssDir);
        file_put_contents(
            $cssDir . '/site.css',
            '.hero { background-image: url("../images/hero.png"); }',
        );

        try {
            $css = (new StylesheetSourceLoader($root))->load('styles/site.css');
            $expectedPath = str_replace(DIRECTORY_SEPARATOR, '/', $root . '/images/hero.png');
            if (preg_match('/^[a-zA-Z]:\//', $expectedPath) === 1) {
                $expectedPath = '/' . $expectedPath;
            }
            $expectedUri = 'file://' . implode('/', array_map('rawurlencode', explode('/', $expectedPath)));

            self::assertStringContainsString('url("' . $expectedUri . '")', $css);
        } finally {
            @unlink($cssDir . '/site.css');
            @rmdir($cssDir);
            @rmdir($root);
        }
    }

    public function testPreservesAbsoluteUrl(): void
    {
        $root = $this->temporaryDirectory();
        file_put_contents(
            $root . '/site.css',
            '.hero { background-image: url(https://example.com/hero.png); }',
        );

        try {
            $css = (new StylesheetSourceLoader($root))->load('site.css');

            self::assertStringContainsString('url(https://example.com/hero.png)', $css);
        } finally {
            @unlink($root . '/site.css');
            @rmdir($root);
        }
    }

    public function testDoesNotResolveRelativeStylesheetWithoutBaseDirectory(): void
    {
        self::assertSame('', (new StylesheetSourceLoader())->load('styles/site.css'));
    }

    private function temporaryDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/pagyra-css-loader-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            self::fail('Unable to create temporary stylesheet directory');
        }
        return $dir;
    }
}
