<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\StylesheetParser;
use PHPUnit\Framework\TestCase;

final class MediaQueryParityTest extends TestCase
{
    public function testIncludesPrintAndExcludesScreenByDefault(): void
    {
        self::assertSame(
            ['black', 'red'],
            $this->colorsForCard('
                .card { color: black; }
                @media screen { .card { color: blue; } }
                @media print { .card { color: red; } }
            '),
        );
    }

    public function testCanExplicitlyEvaluateScreenMedia(): void
    {
        self::assertSame(
            ['blue'],
            $this->colorsForCard('
                @media print { .card { color: red; } }
                @media screen { .card { color: blue; } }
            ', 'screen'),
        );
    }

    public function testSupportsAllNotAndCommaSeparatedLists(): void
    {
        self::assertSame(
            ['black', 'red', 'green'],
            $this->colorsForCard('
                @media all { .card { color: black; } }
                @media not screen { .card { color: red; } }
                @media screen, print { .card { color: green; } }
            '),
        );
    }

    public function testPreservesSourceOrderAroundMatchingMediaBlocks(): void
    {
        $rules = (new StylesheetParser())->parse('
            .card { color: black; }
            @media print { .card { color: red; } }
            .card { color: green; }
        ');

        self::assertSame([0, 1, 2], array_map(static fn($rule): int => $rule->sourceOrder, $rules));
        self::assertSame(['black', 'red', 'green'], array_map(static fn($rule): string => $rule->declarations['color'], $rules));
    }

    public function testEvaluatesDimensionsAndOrientationAgainstViewport(): void
    {
        self::assertSame(
            ['red', 'green'],
            $this->colorsForCard('
                @media print and (min-width: 700px) { .card { color: red; } }
                @media print and (max-width: 600px) { .card { color: blue; } }
                @media print and (orientation: landscape) { .card { color: green; } }
                @media print and (orientation: portrait) { .card { color: purple; } }
            ', 'print', 800, 600),
        );
    }

    public function testSupportsPhysicalLengthUnits(): void
    {
        self::assertSame(
            ['red'],
            $this->colorsForCard('
                @media print and (min-width: 7in) { .card { color: red; } }
                @media print and (max-width: 150mm) { .card { color: blue; } }
            ', 'print', 700, 900),
        );
    }

    public function testUnsupportedMediaFeatureDoesNotApply(): void
    {
        self::assertSame(
            [],
            $this->colorsForCard('
                @media print and (prefers-color-scheme: dark) {
                    .card { color: white; }
                }
            ', 'print', 800, 600),
        );
    }

    /** @return list<string> */
    private function colorsForCard(
        string $css,
        string $mediaType = 'print',
        ?float $viewportWidth = null,
        ?float $viewportHeight = null,
    ): array {
        $colors = [];
        foreach ((new StylesheetParser())->parse($css, $mediaType, $viewportWidth, $viewportHeight) as $rule) {
            if ($rule->selector === '.card' && isset($rule->declarations['color'])) {
                $colors[] = $rule->declarations['color'];
            }
        }
        return $colors;
    }
}
