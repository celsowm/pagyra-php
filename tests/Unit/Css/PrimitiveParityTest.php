<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\Color\ColorParser;
use Pagyra\Css\Length\CalcLength;
use Pagyra\Css\Length\LengthParser;
use Pagyra\Css\Length\LengthResolver;
use PHPUnit\Framework\TestCase;

final class PrimitiveParityTest extends TestCase
{
    public function testColorParsingMatchesReferenceShapes(): void
    {
        self::assertSame(['r'=>255.0,'g'=>0.0,'b'=>0.0,'a'=>1.0], ColorParser::parse('#f00')?->jsonSerialize());
        self::assertEqualsWithDelta(0.5019607843, ColorParser::parse('#00000080')?->a, 1e-9);
        self::assertSame(['r'=>255.0,'g'=>0.0,'b'=>0.0,'a'=>0.5], ColorParser::parse('rgba(300,-5,0,0.5)')?->jsonSerialize());
        self::assertNull(ColorParser::parse('transparent'));
    }

    public function testContainerAndCalcLengthsResolve(): void
    {
        $parser = new LengthParser(1000, 800);
        $cqw = $parser->parseLengthOrPercent('10cqw');
        self::assertInstanceOf(CalcLength::class, $cqw);
        self::assertSame(100.0, LengthResolver::resolve($cqw, 500, containerWidth: 1000, containerHeight: 800));

        $calc = $parser->parseLengthOrPercent('calc(10px + 25% + 2em - 1rem + 10cqh)');
        self::assertInstanceOf(CalcLength::class, $calc);
        self::assertSame(226.0, LengthResolver::resolve($calc, 400, fontSize: 16, rootFontSize: 16, containerWidth: 1000, containerHeight: 1000));
    }
}
