<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Pdf\PngPdfImageParser;
use PHPUnit\Framework\TestCase;

final class PngDecodeCacheTest extends TestCase
{
    protected function setUp(): void
    {
        PngPdfImageParser::clearCache();
    }

    protected function tearDown(): void
    {
        PngPdfImageParser::clearCache();
    }

    public function testSameRgbaPngIsDecodedOnceAcrossParserInstances(): void
    {
        $png = $this->rgbaPng(4, 4, "\x10\x20\x30\x80");

        $first = (new PngPdfImageParser())->parse($png);
        $second = (new PngPdfImageParser())->parse($png);

        self::assertNotNull($first);
        self::assertNotNull($first->alphaCompressedData);
        self::assertSame($first, $second);
    }

    public function testPngsWithSameSizeButDifferentPixelsDoNotShareResult(): void
    {
        $opaque = (new PngPdfImageParser())->parse($this->rgbaPng(4, 4, "\x10\x20\x30\xff"));
        $translucent = (new PngPdfImageParser())->parse($this->rgbaPng(4, 4, "\x10\x20\x30\x40"));

        self::assertNotNull($opaque);
        self::assertNotNull($translucent);
        self::assertSame(str_repeat("\xff", 16), gzuncompress((string) $opaque->alphaCompressedData));
        self::assertSame(str_repeat("\x40", 16), gzuncompress((string) $translucent->alphaCompressedData));
    }

    public function testInvalidPngStaysInvalidWhenCached(): void
    {
        $broken = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 40);

        self::assertNull((new PngPdfImageParser())->parse($broken));
        self::assertNull((new PngPdfImageParser())->parse($broken));
    }

    public function testOldestEntryIsEvictedWhenCacheIsFull(): void
    {
        $first = $this->rgbaPng(2, 2, "\x00\x00\x00\x01");
        $parsedFirst = (new PngPdfImageParser())->parse($first);

        for ($i = 2; $i <= 40; $i++) {
            (new PngPdfImageParser())->parse($this->rgbaPng(2, 2, "\x00\x00\x00" . chr($i)));
        }

        $reparsed = (new PngPdfImageParser())->parse($first);
        self::assertNotNull($reparsed);
        self::assertNotSame($parsedFirst, $reparsed);
        self::assertSame($parsedFirst->alphaCompressedData, $reparsed->alphaCompressedData);
    }

    public function testDocumentsRenderedInSequenceEmbedTheSameRgbaImage(): void
    {
        $src = 'data:image/png;base64,' . base64_encode($this->rgbaPng(8, 8, "\xc0\x10\x10\x90"));
        $html = '<p style="margin:0"><img src="' . $src . '" style="width:40px;height:40px"></p>';
        $options = ['html' => $html, 'viewportWidth' => 200, 'viewportHeight' => 100];

        $firstPdf = Pagyra::renderHtmlToPdf($options);
        $secondPdf = Pagyra::renderHtmlToPdf($options);

        self::assertSame($this->imageStreams($firstPdf), $this->imageStreams($secondPdf));
        self::assertCount(2, $this->imageStreams($secondPdf));
        self::assertStringContainsString('/SMask ', $secondPdf);
    }

    /** @return list<string> */
    private function imageStreams(string $pdf): array
    {
        preg_match_all('/<< \/Type \/XObject \/Subtype \/Image.*?endstream/s', $pdf, $matches);
        return $matches[0];
    }

    private function rgbaPng(int $width, int $height, string $pixel): string
    {
        $raw = str_repeat("\x00" . str_repeat($pixel, $width), $height);
        $idat = gzcompress($raw);
        self::assertIsString($idat);

        $ihdr = pack('N', $width) . pack('N', $height) . "\x08\x06\x00\x00\x00";
        return "\x89PNG\r\n\x1a\n" . $this->chunk('IHDR', $ihdr) . $this->chunk('IDAT', $idat) . $this->chunk('IEND', '');
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . "\x00\x00\x00\x00";
    }
}
