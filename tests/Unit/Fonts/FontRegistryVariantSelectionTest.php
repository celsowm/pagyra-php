<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Fonts;

use Pagyra\Fonts\FontRegistry;
use Pagyra\Fonts\Ttf\TtfFontMetrics;
use PHPUnit\Framework\TestCase;

final class FontRegistryVariantSelectionTest extends TestCase
{
    public function testExactWeightAndStyleWins(): void
    {
        $registry = new FontRegistry();
        $regular = $this->metrics(400);
        $bold = $this->metrics(700);
        $registry->register('Fixture', $regular, 400, 'normal');
        $registry->register('Fixture', $bold, 700, 'normal');

        self::assertSame($bold, $registry->resolve('Fixture', 700, 'normal'));
    }

    public function testNearestWeightWinsWithinRequestedStyle(): void
    {
        $registry = new FontRegistry();
        $regular = $this->metrics(400);
        $bold = $this->metrics(700);
        $registry->register('Fixture', $regular, 400, 'normal');
        $registry->register('Fixture', $bold, 700, 'normal');

        self::assertSame($bold, $registry->resolve('Fixture', 650, 'normal'));
        self::assertSame($regular, $registry->resolve('Fixture', 500, 'normal'));
    }

    public function testRequestedStyleIsPreferredBeforeWeightDistance(): void
    {
        $registry = new FontRegistry();
        $regular = $this->metrics(400);
        $boldItalic = $this->metrics(700);
        $registry->register('Fixture', $regular, 400, 'normal');
        $registry->register('Fixture', $boldItalic, 700, 'italic');

        self::assertSame($boldItalic, $registry->resolve('Fixture', 400, 'italic'));
    }

    public function testFallsBackToOtherStyleWhenRequestedStyleIsUnavailable(): void
    {
        $registry = new FontRegistry();
        $regular = $this->metrics(400);
        $bold = $this->metrics(700);
        $registry->register('Fixture', $regular, 400, 'normal');
        $registry->register('Fixture', $bold, 700, 'normal');

        self::assertSame($bold, $registry->resolve('Fixture', 650, 'italic'));
    }

    public function testObliqueNormalizesToItalic(): void
    {
        $registry = new FontRegistry();
        $italic = $this->metrics(400);
        $registry->register('Fixture', $italic, 400, 'oblique');

        self::assertSame($italic, $registry->resolve('Fixture', 400, 'italic'));
        self::assertSame($italic, $registry->resolve('Fixture', 400, 'oblique'));
    }

    public function testFamilyStackFallsThroughToRegisteredFamily(): void
    {
        $registry = new FontRegistry();
        $metrics = $this->metrics(400);
        $registry->register('Fixture', $metrics, 400, 'normal');

        self::assertSame($metrics, $registry->resolve('Missing, "Fixture", serif', 400, 'normal'));
    }

    private function metrics(int $marker): TtfFontMetrics
    {
        return new TtfFontMetrics(
            unitsPerEm: 1000,
            ascent: $marker,
            descent: -200,
            lineGap: 0,
            advanceWidths: [0 => 500],
            cmap: [],
        );
    }
}
