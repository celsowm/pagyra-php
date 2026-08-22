<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Image;

use Pagyra\Image\ObjectPositionParser;
use PHPUnit\Framework\TestCase;

final class ObjectPositionParserTest extends TestCase
{
    public function testDefaultsToCenter(): void
    {
        $position = ObjectPositionParser::parse(null);
        self::assertSame(0.5, $position->x);
        self::assertSame(0.5, $position->y);
    }

    public function testParsesKeywordPair(): void
    {
        $position = ObjectPositionParser::parse('right bottom');
        self::assertSame(1.0, $position->x);
        self::assertSame(1.0, $position->y);
    }

    public function testSingleVerticalKeywordKeepsHorizontalCenter(): void
    {
        $position = ObjectPositionParser::parse('top');
        self::assertSame(0.5, $position->x);
        self::assertSame(0.0, $position->y);
    }

    public function testParsesPercentages(): void
    {
        $position = ObjectPositionParser::parse('25% 75%');
        self::assertSame(0.25, $position->x);
        self::assertSame(0.75, $position->y);
    }
}
