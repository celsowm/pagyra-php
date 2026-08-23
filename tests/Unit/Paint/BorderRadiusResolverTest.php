<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Paint;

use Pagyra\Paint\BorderRadiusResolver;
use Pagyra\Style\ComputedStyle;
use PHPUnit\Framework\TestCase;

final class BorderRadiusResolverTest extends TestCase
{
    public function testExpandsEllipticalShorthand(): void
    {
        $radius = BorderRadiusResolver::resolve(
            new ComputedStyle(['border-radius' => '10px 20px 30px 40px / 5px 15px 25px 35px']),
            200.0,
            100.0,
        );

        self::assertSame(10.0, $radius->topLeft->x);
        self::assertSame(5.0, $radius->topLeft->y);
        self::assertSame(20.0, $radius->topRight->x);
        self::assertSame(15.0, $radius->topRight->y);
        self::assertSame(30.0, $radius->bottomRight->x);
        self::assertSame(25.0, $radius->bottomRight->y);
        self::assertSame(40.0, $radius->bottomLeft->x);
        self::assertSame(35.0, $radius->bottomLeft->y);
    }

    public function testPercentagesUseAxisSpecificReference(): void
    {
        $radius = BorderRadiusResolver::resolve(
            new ComputedStyle(['border-radius' => '50%']),
            200.0,
            100.0,
        );

        self::assertSame(100.0, $radius->topLeft->x);
        self::assertSame(50.0, $radius->topLeft->y);
    }

    public function testNormalizesAllRadiiWithOneGlobalScaleFactor(): void
    {
        $radius = BorderRadiusResolver::resolve(
            new ComputedStyle(['border-radius' => '80px 80px / 60px 60px']),
            100.0,
            60.0,
        );

        // Horizontal sums require f = 100/160 = 0.625; vertical would allow 0.5,
        // so the CSS global minimum is 0.5 and all radii use that same factor.
        self::assertSame(40.0, $radius->topLeft->x);
        self::assertSame(30.0, $radius->topLeft->y);
        self::assertSame(40.0, $radius->bottomRight->x);
        self::assertSame(30.0, $radius->bottomRight->y);
    }

    public function testLonghandOverridesMatchingCorner(): void
    {
        $radius = BorderRadiusResolver::resolve(
            new ComputedStyle([
                'border-radius' => '10px',
                'border-top-right-radius' => '25% 50%',
            ]),
            200.0,
            100.0,
        );

        self::assertSame(50.0, $radius->topRight->x);
        self::assertSame(50.0, $radius->topRight->y);
        self::assertSame(10.0, $radius->topLeft->x);
    }
}
