<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\Color\ColorParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorParserTest extends TestCase
{
    /** @return iterable<string,array{string,array{float,float,float,float}}> */
    public static function colors(): iterable
    {
        yield 'extended name' => ['darkred', [139, 0, 0, 1]];
        yield 'name is case-insensitive' => ['DarkGoldenRod', [184, 134, 11, 1]];
        yield 'hex 4' => ['#f008', [255, 0, 0, 0x88 / 255]];
        yield 'rgb legacy' => ['rgb(10, 20, 30)', [10, 20, 30, 1]];
        yield 'rgba legacy' => ['rgba(255,0,0,.5)', [255, 0, 0, 0.5]];
        yield 'rgb percentages' => ['rgb(100%, 0%, 50%)', [255, 0, 127.5, 1]];
        yield 'rgb space syntax' => ['rgb(0 0 255 / 50%)', [0, 0, 255, 0.5]];
        yield 'hsl legacy' => ['hsl(120, 100%, 25%)', [0, 127.5, 0, 1]];
        yield 'hsla legacy' => ['hsla(0, 100%, 50%, 0.3)', [255, 0, 0, 0.3]];
        yield 'hsl space syntax' => ['hsl(196 100% 95% / 1)', [229.5, 248.2, 255, 1]];
        yield 'hsl turn' => ['hsl(0.5turn 50% 50%)', [63.75, 191.25, 191.25, 1]];
        yield 'hsl negative hue wraps' => ['hsl(-120, 100%, 50%)', [0, 0, 255, 1]];
        yield 'hwb' => ['hwb(0 20% 20%)', [204, 51, 51, 1]];
        yield 'hwb grey' => ['hwb(0 60% 60%)', [127.5, 127.5, 127.5, 1]];
    }

    /** @param array{float,float,float,float} $expected */
    #[DataProvider('colors')]
    public function testParses(string $value, array $expected): void
    {
        $color = ColorParser::parse($value);

        self::assertNotNull($color, $value);
        self::assertEqualsWithDelta($expected, [$color->r, $color->g, $color->b, $color->a], 0.1, $value);
    }

    public function testRejectsWhatIsNotAColor(): void
    {
        foreach (['transparent', 'notacolor', 'rgb(1,2)', 'hsl(10, 20%)', 'rgb(1 2 3 4)', '#12345', ''] as $value) {
            self::assertNull(ColorParser::parse($value), $value);
        }
    }
}
