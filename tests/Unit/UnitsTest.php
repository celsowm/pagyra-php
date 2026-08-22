<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit;

use Pagyra\Units\Units;
use PHPUnit\Framework\TestCase;

final class UnitsTest extends TestCase
{
    public function testPxToPtMatchesPagyraJsConvention(): void
    {
        self::assertSame(72.0, Units::pxToPt(96.0));
        self::assertSame(96.0, Units::ptToPx(72.0));
    }

    public function testPhysicalUnitsUse96Dpi(): void
    {
        self::assertEqualsWithDelta(96.0, Units::inToPx(1.0), 0.000001);
        self::assertEqualsWithDelta(96.0, Units::cmToPx(2.54), 0.000001);
        self::assertEqualsWithDelta(96.0, Units::mmToPx(25.4), 0.000001);
    }
}
