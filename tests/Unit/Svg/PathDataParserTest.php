<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Svg;

use Pagyra\Svg\PathDataParser;
use PHPUnit\Framework\TestCase;

final class PathDataParserTest extends TestCase
{
    public function testNormalizesRelativeMoveLineHorizontalVerticalAndClose(): void
    {
        $segments = (new PathDataParser())->parse('m 10 20 5 0 h 10 v 5 z');

        self::assertSame('M', $segments[0]['type']);
        self::assertSame(10.0, $segments[0]['x']);
        self::assertSame(20.0, $segments[0]['y']);
        self::assertSame(['type' => 'L', 'x' => 15.0, 'y' => 20.0], $segments[1]);
        self::assertSame(['type' => 'L', 'x' => 25.0, 'y' => 20.0], $segments[2]);
        self::assertSame(['type' => 'L', 'x' => 25.0, 'y' => 25.0], $segments[3]);
        self::assertSame(['type' => 'L', 'x' => 10.0, 'y' => 20.0], $segments[4]);
        self::assertSame(['type' => 'Z'], $segments[5]);
    }

    public function testConvertsQuadraticAndSmoothQuadraticToCubic(): void
    {
        $segments = (new PathDataParser())->parse('M0 0 Q 9 9 18 0 T 36 0');

        self::assertCount(3, $segments);
        self::assertSame('C', $segments[1]['type']);
        self::assertEqualsWithDelta(6.0, $segments[1]['x1'], 0.000001);
        self::assertEqualsWithDelta(6.0, $segments[1]['y1'], 0.000001);
        self::assertEqualsWithDelta(12.0, $segments[1]['x2'], 0.000001);
        self::assertEqualsWithDelta(6.0, $segments[1]['y2'], 0.000001);
        self::assertSame(18.0, $segments[1]['x']);
        self::assertSame(0.0, $segments[1]['y']);

        self::assertSame('C', $segments[2]['type']);
        self::assertEqualsWithDelta(24.0, $segments[2]['x1'], 0.000001);
        self::assertEqualsWithDelta(-6.0, $segments[2]['y1'], 0.000001);
        self::assertEqualsWithDelta(30.0, $segments[2]['x2'], 0.000001);
        self::assertEqualsWithDelta(-6.0, $segments[2]['y2'], 0.000001);
        self::assertSame(36.0, $segments[2]['x']);
        self::assertSame(0.0, $segments[2]['y']);
    }

    public function testReflectsPreviousCubicControlForSmoothCubic(): void
    {
        $segments = (new PathDataParser())->parse('M0 0 C 1 2 3 4 5 6 S 9 10 11 12');

        self::assertSame('C', $segments[2]['type']);
        self::assertSame(7.0, $segments[2]['x1']);
        self::assertSame(8.0, $segments[2]['y1']);
        self::assertSame(9.0, $segments[2]['x2']);
        self::assertSame(10.0, $segments[2]['y2']);
        self::assertSame(11.0, $segments[2]['x']);
        self::assertSame(12.0, $segments[2]['y']);
    }

    public function testAcceptsCompactSignedAndExponentNumbers(): void
    {
        $segments = (new PathDataParser())->parse('M1e1-2 L.5,+3');

        self::assertSame(['type' => 'M', 'x' => 10.0, 'y' => -2.0], $segments[0]);
        self::assertSame(['type' => 'L', 'x' => 0.5, 'y' => 3.0], $segments[1]);
    }

    public function testConvertsHalfCircleArcToTwoCubicSegments(): void
    {
        $segments = (new PathDataParser())->parse('M0 0 A 10 10 0 0 1 20 0');

        self::assertCount(3, $segments);
        self::assertSame('C', $segments[1]['type']);
        self::assertSame('C', $segments[2]['type']);
        self::assertEqualsWithDelta(20.0, $segments[2]['x'], 0.000001);
        self::assertEqualsWithDelta(0.0, $segments[2]['y'], 0.000001);
        self::assertEqualsWithDelta(10.0, $segments[1]['x'], 0.000001);
        self::assertEqualsWithDelta(-10.0, $segments[1]['y'], 0.000001);
    }

    public function testSupportsRelativeArcAndCorrectsTooSmallRadii(): void
    {
        $segments = (new PathDataParser())->parse('M10 10 a 2 2 0 0 1 10 0');

        self::assertGreaterThanOrEqual(2, count($segments));
        $last = $segments[array_key_last($segments)];
        self::assertSame('C', $last['type']);
        self::assertEqualsWithDelta(20.0, $last['x'], 0.000001);
        self::assertEqualsWithDelta(10.0, $last['y'], 0.000001);
    }

    public function testDegenerateArcWithZeroRadiusBecomesLine(): void
    {
        $segments = (new PathDataParser())->parse('M1 2 A 0 10 0 0 1 7 8');

        self::assertSame(['type' => 'L', 'x' => 7.0, 'y' => 8.0], $segments[1]);
    }

    public function testArcAcceptsNumericFlagsLikeReferenceParser(): void
    {
        $segments = (new PathDataParser())->parse('M0 0 A10 10 0 2 -3 20 0');

        self::assertNotEmpty($segments);
        $last = $segments[array_key_last($segments)];
        self::assertSame('C', $last['type']);
        self::assertEqualsWithDelta(20.0, $last['x'], 0.000001);
        self::assertEqualsWithDelta(0.0, $last['y'], 0.000001);
    }
}
