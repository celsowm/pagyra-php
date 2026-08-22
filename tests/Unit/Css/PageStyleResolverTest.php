<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Css;

use Pagyra\Css\PageStyleResolver;
use Pagyra\Units\Units;
use PHPUnit\Framework\TestCase;

final class PageStyleResolverTest extends TestCase
{
    public function testResolvesNamedPageSizeAndLandscapeOrientation(): void
    {
        $resolved = (new PageStyleResolver())->resolve(
            '@page { size: A4 landscape; }',
            794.0,
            1123.0,
            $this->fallbackMargins(),
        );

        self::assertEqualsWithDelta(Units::mmToPx(297), $resolved['width'], 0.0001);
        self::assertEqualsWithDelta(Units::mmToPx(210), $resolved['height'], 0.0001);
    }

    public function testResolvesExplicitAbsolutePageDimensions(): void
    {
        $resolved = (new PageStyleResolver())->resolve(
            '@page { size: 8.5in 11in; }',
            794.0,
            1123.0,
            $this->fallbackMargins(),
        );

        self::assertSame(816.0, $resolved['width']);
        self::assertSame(1056.0, $resolved['height']);
    }

    public function testMarginShorthandAndLonghandFollowDeclarationOrder(): void
    {
        $resolved = (new PageStyleResolver())->resolve(
            '@page { margin: 10px 20px 30px 40px; margin-left: 50px; }',
            794.0,
            1123.0,
            $this->fallbackMargins(),
        );

        self::assertSame([
            'top' => 10.0,
            'right' => 20.0,
            'bottom' => 30.0,
            'left' => 50.0,
        ], $resolved['margins']);
    }

    public function testImportantMarginBeatsLaterNonImportantDeclaration(): void
    {
        $resolved = (new PageStyleResolver())->resolve(
            '@page { margin-left: 12mm !important; } @page { margin-left: 30mm; }',
            794.0,
            1123.0,
            $this->fallbackMargins(),
        );

        self::assertEqualsWithDelta(Units::mmToPx(12), $resolved['margins']['left'], 0.0001);
    }

    public function testQualifiedPageRulesDoNotAffectDefaultProfileYet(): void
    {
        $resolved = (new PageStyleResolver())->resolve(
            '@page :first { size: A5; margin: 1px; }',
            794.0,
            1123.0,
            $this->fallbackMargins(),
        );

        self::assertSame(794.0, $resolved['width']);
        self::assertSame(1123.0, $resolved['height']);
        self::assertSame($this->fallbackMargins(), $resolved['margins']);
    }

    public function testPageRuleInsideMatchingMediaQueryIsApplied(): void
    {
        $resolved = (new PageStyleResolver())->resolve(
            '@media print and (max-width:700px) { @page { size:500px 300px; margin:25px; } }',
            794.0,
            1123.0,
            $this->fallbackMargins(),
            'print',
            650.0,
            900.0,
        );

        self::assertSame(500.0, $resolved['width']);
        self::assertSame(300.0, $resolved['height']);
        self::assertSame([
            'top' => 25.0,
            'right' => 25.0,
            'bottom' => 25.0,
            'left' => 25.0,
        ], $resolved['margins']);
    }

    public function testPageRuleInsideNonMatchingMediaQueryIsIgnored(): void
    {
        $resolved = (new PageStyleResolver())->resolve(
            '@media print and (max-width:700px) { @page { size:500px 300px; } }',
            794.0,
            1123.0,
            $this->fallbackMargins(),
            'print',
            750.0,
            900.0,
        );

        self::assertSame(794.0, $resolved['width']);
        self::assertSame(1123.0, $resolved['height']);
    }

    /** @return array{top:float,right:float,bottom:float,left:float} */
    private function fallbackMargins(): array
    {
        return ['top' => 48.0, 'right' => 48.0, 'bottom' => 48.0, 'left' => 48.0];
    }
}
