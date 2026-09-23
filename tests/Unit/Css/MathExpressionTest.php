<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\Length\LengthParser;
use Pagyra\Css\Length\LengthResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MathExpressionTest extends TestCase
{
    /** @return iterable<string,array{string,float}> */
    public static function expressions(): iterable
    {
        yield 'sum with percentage' => ['calc(100% - 100px)', 300.0];
        yield 'division' => ['calc(100% / 3)', 400.0 / 3];
        yield 'multiplication and em' => ['calc(2 * 10px + 1em)', 36.0];
        yield 'nested parentheses' => ['calc( (100% - 20px) / 2 )', 190.0];
        yield 'leading negative' => ['calc(-10px + 5px)', -5.0];
        yield 'viewport units' => ['calc(50vw - 10px)', 490.0];
        yield 'min' => ['min(150px, 50%)', 150.0];
        yield 'max' => ['max(120px, 10%)', 120.0];
        yield 'clamp in range' => ['clamp(50px, 30%, 200px)', 120.0];
        yield 'clamp below' => ['clamp(50px, 5%, 200px)', 50.0];
        yield 'clamp above' => ['clamp(50px, 90%, 200px)', 200.0];
        yield 'math inside math' => ['min(300px, calc(50% + 20px))', 220.0];
        yield 'container and root units' => ['calc(10px + 25% + 2em - 1rem + 10cqh)', 226.0];
    }

    #[DataProvider('expressions')]
    public function testEvaluates(string $value, float $expected): void
    {
        $length = (new LengthParser(1000, 800))->parseLengthOrPercent($value);

        self::assertNotNull($length, $value);
        self::assertEqualsWithDelta($expected, LengthResolver::resolve($length, 400, 16, 16, 1000, 1000), 1e-9, $value);
    }

    public function testRejectsWhatIsNotAMathFunction(): void
    {
        $parser = new LengthParser(1000, 800);
        foreach (['calc(10px', 'calc(10px ? 2)', 'mini(10px)', 'calc(10px, 20px)', 'calc()'] as $value) {
            self::assertNull($parser->parseLengthOrPercent($value), $value);
        }
    }
}
