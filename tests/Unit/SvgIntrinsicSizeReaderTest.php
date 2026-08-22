<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit;

use Pagyra\Image\SvgIntrinsicSizeReader;
use PHPUnit\Framework\TestCase;

final class SvgIntrinsicSizeReaderTest extends TestCase
{
    public function testUsesExplicitWidthAndHeight(): void
    {
        $size = (new SvgIntrinsicSizeReader())->read(
            '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60" viewBox="0 0 400 200"/>',
        );

        self::assertNotNull($size);
        self::assertSame(120.0, $size->width);
        self::assertSame(60.0, $size->height);
    }

    public function testFallsBackToViewBoxDimensions(): void
    {
        $size = (new SvgIntrinsicSizeReader())->read(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 180"/>',
        );

        self::assertNotNull($size);
        self::assertSame(320.0, $size->width);
        self::assertSame(180.0, $size->height);
    }

    public function testUsesReferenceFallbackWhenDimensionsAreMissing(): void
    {
        $size = (new SvgIntrinsicSizeReader())->read('<svg xmlns="http://www.w3.org/2000/svg"/>');

        self::assertNotNull($size);
        self::assertSame(100.0, $size->width);
        self::assertSame(100.0, $size->height);
    }

    public function testHeightFallsBackToWidthWhenOnlyWidthExists(): void
    {
        $size = (new SvgIntrinsicSizeReader())->read(
            '<svg xmlns="http://www.w3.org/2000/svg" width="75"/>',
        );

        self::assertNotNull($size);
        self::assertSame(75.0, $size->width);
        self::assertSame(75.0, $size->height);
    }

    public function testRejectsNonSvgXml(): void
    {
        self::assertNull((new SvgIntrinsicSizeReader())->read('<html/>'));
    }
}
