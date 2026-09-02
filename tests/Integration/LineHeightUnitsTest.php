<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * `line-height` takes a unitless factor, a percentage or any CSS length, but only the first
 * three were parsed — every other unit fell through to `font-size * 1.2`. The eproc/TJRJ
 * templates declare `line-height: 11pt` 1962 times in the corpus (plus 8pt, 9pt and 15pt), so
 * blocks like `font-size: 8pt; line-height: 8pt` were laid out 20% too tall, which is most of
 * the leading gap against wkhtmltopdf.
 */
final class LineHeightUnitsTest extends TestCase
{
    private function lineHeight(string $declaration): float
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p>uma linha</p>',
            'css' => 'p { width: 500px; margin: 0; ' . $declaration . ' }',
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        return $prepared->layoutRoot->children[0]->lineBoxes[0]->height;
    }

    public function testPointsAreHonouredInsteadOfFallingBackToTheFactor(): void
    {
        // 8pt = 10.666…px. The old fallback gave 8pt-font * 1.2 = 12.8px.
        self::assertEqualsWithDelta(10.6667, $this->lineHeight('font-size: 8pt; line-height: 8pt;'), 0.01);
    }

    public function testEmResolvesAgainstTheElementsOwnFontSize(): void
    {
        self::assertEqualsWithDelta(24.0, $this->lineHeight('font-size: 20px; line-height: 1.2em;'), 0.01);
    }

    public function testInchesAndMillimetresAreLengths(): void
    {
        self::assertEqualsWithDelta(96.0, $this->lineHeight('font-size: 10px; line-height: 1in;'), 0.01);
        self::assertEqualsWithDelta(37.795, $this->lineHeight('font-size: 10px; line-height: 10mm;'), 0.01);
    }

    public function testImportantSuffixDoesNotDefeatParsing(): void
    {
        self::assertEqualsWithDelta(20.0, $this->lineHeight('font-size: 20px; line-height: 1em !important;'), 0.01);
    }

    public function testUnitlessPercentAndPixelsStillWork(): void
    {
        self::assertEqualsWithDelta(27.6, $this->lineHeight('font-size: 20px; line-height: 1.38;'), 0.01);
        self::assertEqualsWithDelta(30.0, $this->lineHeight('font-size: 20px; line-height: 150%;'), 0.01);
        self::assertEqualsWithDelta(27.0, $this->lineHeight('font-size: 20px; line-height: 27px;'), 0.01);
    }

    public function testNormalKeepsTheApproximation(): void
    {
        self::assertEqualsWithDelta(24.0, $this->lineHeight('font-size: 20px; line-height: normal;'), 0.01);
    }
}
