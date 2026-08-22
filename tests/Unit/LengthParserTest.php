<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit;

use Pagyra\Css\Length\LengthParser;
use Pagyra\Css\Length\PercentLength;
use Pagyra\Css\Length\RelativeLength;
use PHPUnit\Framework\TestCase;

final class LengthParserTest extends TestCase
{
    public function testAbsoluteUnitsMatchReferenceConventions(): void
    {
        $parser = new LengthParser();

        self::assertSame(10.0, $parser->parseLength('10px'));
        self::assertSame(96.0, $parser->parseLength('72pt'));
        self::assertEqualsWithDelta(96.0, $parser->parseLength('1in'), 0.000001);
        self::assertEqualsWithDelta(96.0, $parser->parseLength('2.54cm'), 0.000001);
    }

    public function testViewportUnitsUseConfiguredViewport(): void
    {
        $parser = new LengthParser(1000, 800);

        self::assertSame(100.0, $parser->parseLength('10vw'));
        self::assertSame(80.0, $parser->parseLength('10vh'));
    }

    public function testRelativeAndPercentLengthsRemainDeferred(): void
    {
        $parser = new LengthParser();

        $em = $parser->parseLength('1.5em');
        self::assertInstanceOf(RelativeLength::class, $em);
        self::assertSame('em', $em->unit);
        self::assertSame(1.5, $em->value);

        $percent = $parser->parseLengthOrPercent('25%');
        self::assertInstanceOf(PercentLength::class, $percent);
        self::assertSame(0.25, $percent->ratio);
    }

    public function testAutoAndInvalidValuesFollowReferenceBehavior(): void
    {
        $parser = new LengthParser();

        self::assertNull($parser->parseLength('auto'));
        self::assertSame('auto', $parser->parseLengthOrAuto('AUTO'));
        self::assertNull($parser->parseLength('banana'));
    }
}
