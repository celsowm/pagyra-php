<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Core\RenderHtmlOptions;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class ContentScaleTest extends TestCase
{
    private const HTML = '<p style="margin:0;font-family:Arial;color:#000">Hello</p>';

    private static function options(array $extra = []): array
    {
        return $extra + [
            'html' => self::HTML,
            'viewportWidth' => 400,
            'viewportHeight' => 200,
            'pageWidth' => 400,
            'pageHeight' => 200,
            'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20],
        ];
    }

    public function testDefaultContentScaleLeavesTheStreamUntransformed(): void
    {
        $pdf = Pagyra::renderHtmlToPdf(self::options());

        self::assertStringContainsString('/MediaBox [0 0 300 150]', $pdf);
        self::assertStringNotContainsString('0.8 0 0 0.8 0 0 cm', $pdf);
    }

    public function testExplicitFullScaleMatchesTheDefault(): void
    {
        self::assertSame(
            Pagyra::renderHtmlToPdf(self::options()),
            Pagyra::renderHtmlToPdf(self::options(['contentScale' => 1.0])),
        );
    }

    public function testContentScaleKeepsThePhysicalPageSizeButShrinksTheDrawing(): void
    {
        $pdf = Pagyra::renderHtmlToPdf(self::options(['contentScale' => 0.8]));

        // page inflated to 500x250px for layout, then the sheet is scaled back by 0.8:
        // pxToPt(500) * 0.8 = 375 * 0.8 = 300; pxToPt(250) * 0.8 = 150 -> MediaBox unchanged
        self::assertStringContainsString('/MediaBox [0 0 300 150]', $pdf);
        self::assertStringContainsString("q\n0.8 0 0 0.8 0 0 cm\n", $pdf);
        self::assertStringContainsString("\nQ\n", $pdf);
    }

    public function testSmallerContentScaleFitsMoreLinesPerPage(): void
    {
        $tall = '<div>' . str_repeat('<p style="margin:0;font-size:12px">linha de teste</p>', 40) . '</div>';
        $opts = self::options(['html' => $tall]);

        $full = Pagyra::renderHtmlToPdf($opts);
        $scaled = Pagyra::renderHtmlToPdf($opts + ['contentScale' => 0.5]);

        self::assertGreaterThan(
            substr_count($scaled, '/Type /Page /Parent'),
            substr_count($full, '/Type /Page /Parent'),
        );
    }

    public function testContentScaleAlsoTransformsLinkAnnotationRectangles(): void
    {
        $opts = self::options(['html' => '<p style="margin:0"><a href="https://example.test/">link</a></p>']);

        $full = Pagyra::renderHtmlToPdf($opts);
        $scaled = Pagyra::renderHtmlToPdf($opts + ['contentScale' => 0.8]);

        self::assertStringContainsString('/Subtype /Link', $full);
        self::assertStringContainsString('/Subtype /Link', $scaled);

        self::assertSame(1, preg_match('#/Rect \[([^\]]+)\]#', $full, $fullRect));
        self::assertSame(1, preg_match('#/Rect \[([^\]]+)\]#', $scaled, $scaledRect));
        self::assertNotSame($fullRect[1], $scaledRect[1]);
    }

    public function testNonPositiveContentScaleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RenderHtmlOptions(html: '<p>x</p>', contentScale: 0.0);
    }

    public function testNegativeContentScaleFromArrayIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RenderHtmlOptions::fromArray(['html' => '<p>x</p>', 'contentScale' => -0.5]);
    }
}
