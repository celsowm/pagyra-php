<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\ImagePaintCommand;
use PHPUnit\Framework\TestCase;

final class JpegPdfRenderingTest extends TestCase
{
    public function testDataUrlJpegBecomesPaintCommandAndDeduplicatedPdfXObject(): void
    {
        $jpeg = $this->jpegFixture();
        $url = 'data:image/jpeg;base64,' . base64_encode($jpeg);
        $html = '<style>@page{size:300px 200px;margin:10px}p{margin:0}</style>'
            . '<p><img src="' . $url . '" style="width:40px"><img src="' . $url . '" style="width:20px"></p>';

        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $images = array_values(array_filter(
            $prepared->displayList?->pages[0]->commands ?? [],
            static fn ($command): bool => $command instanceof ImagePaintCommand,
        ));
        self::assertCount(2, $images);
        self::assertSame(40.0, $images[0]->width);
        self::assertSame(20.0, $images[0]->height);
        self::assertSame(20.0, $images[1]->width);
        self::assertSame(10.0, $images[1]->height);

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => $html,
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        self::assertSame(1, substr_count($pdf, '/Subtype /Image'));
        self::assertStringContainsString('/Width 200 /Height 100', $pdf);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $pdf);
        self::assertStringContainsString('/BitsPerComponent 8', $pdf);
        self::assertStringContainsString('/Filter /DCTDecode', $pdf);
        self::assertSame(2, substr_count($pdf, '/Im1 Do'));
        self::assertStringContainsString("30 0 0 15 ", $pdf);
        self::assertStringContainsString("15 0 0 7.5 ", $pdf);
    }

    private function jpegFixture(): string
    {
        return "\xff\xd8"
            . "\xff\xe0" . pack('n', 4) . "\x00\x00"
            . "\xff\xc0" . pack('n', 17)
            . "\x08" . pack('n', 100) . pack('n', 200) . "\x03"
            . str_repeat("\x00", 9)
            . "\xff\xd9";
    }
}
