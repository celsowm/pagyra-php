<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Fonts;

use Pagyra\Fonts\FontRegistry;
use Pagyra\Fonts\GlyphTextMetrics;
use Pagyra\Fonts\Ttf\TtfFontMetrics;
use Pagyra\Style\ComputedStyle;
use PHPUnit\Framework\TestCase;

final class GlyphTextMetricsTest extends TestCase
{
    public function testUsesGlyphAdvancesAndKerning(): void
    {
        $font = new TtfFontMetrics(
            unitsPerEm: 1000,
            ascent: 800,
            descent: -200,
            lineGap: 0,
            advanceWidths: [0 => 500, 1 => 600, 2 => 610],
            cmap: [65 => 1, 66 => 2],
            kerning: [1 => [2 => -50]],
        );
        $registry = new FontRegistry();
        $registry->register('Test Sans', $font);
        $metrics = new GlyphTextMetrics($registry);
        $style = new ComputedStyle(['font-family' => '"Test Sans", sans-serif']);

        $measurement = $metrics->measure('AB', $style, 20.0);

        self::assertSame(23.2, $measurement->inlineSize);
        self::assertSame(24.0, $measurement->blockSize);
    }

    public function testFallsBackWhenFamilyIsMissing(): void
    {
        $metrics = new GlyphTextMetrics(new FontRegistry());
        $style = new ComputedStyle(['font-family' => 'Missing']);

        self::assertGreaterThan(0.0, $metrics->measure('hello', $style, 16.0)->inlineSize);
    }
}
