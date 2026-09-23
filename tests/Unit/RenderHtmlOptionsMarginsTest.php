<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit;

use Pagyra\Core\RenderHtmlOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `margins` either takes a number for all four sides or an array keyed by side. Anything else is
 * rejected instead of silently falling back to the 48px default.
 */
final class RenderHtmlOptionsMarginsTest extends TestCase
{
    private const DEFAULTS = ['top' => 48.0, 'right' => 48.0, 'bottom' => 48.0, 'left' => 48.0];

    private static function margins(mixed $margins): array
    {
        return RenderHtmlOptions::fromArray(['html' => '<p>x</p>', 'margins' => $margins])->margins;
    }

    public function testAbsentMarginsKeepTheDefault(): void
    {
        self::assertSame(self::DEFAULTS, RenderHtmlOptions::fromArray(['html' => '<p>x</p>'])->margins);
        self::assertSame(self::DEFAULTS, self::margins(null));
        self::assertSame(self::DEFAULTS, self::margins([]));
    }

    public function testANumberAppliesToAllFourSides(): void
    {
        self::assertSame(['top' => 37.795, 'right' => 37.795, 'bottom' => 37.795, 'left' => 37.795], self::margins(37.795));
        self::assertSame(['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0], self::margins(0));
    }

    public function testAnArrayOverridesOnlyTheSidesItNames(): void
    {
        self::assertSame(
            ['top' => 10.0, 'right' => 48.0, 'bottom' => 48.0, 'left' => 20.0],
            self::margins(['top' => 10, 'left' => 20.0]),
        );
    }

    /** @return iterable<string,array{mixed,string}> */
    public static function invalidMargins(): iterable
    {
        yield 'numeric string' => ['37.795', 'margins must be a number or an array keyed by top, right, bottom and left'];
        yield 'boolean' => [true, 'margins must be a number or an array keyed by top, right, bottom and left'];
        yield 'css-ordered list' => [[10, 20, 30, 40], "margins accepts only top, right, bottom and left, got '0'"];
        yield 'misspelled side' => [['margin-top' => 10], "margins accepts only top, right, bottom and left, got 'margin-top'"];
        yield 'negative number' => [-1, 'margins must be non-negative'];
        yield 'infinite number' => [INF, 'margins must be finite'];
        yield 'non-numeric side' => [['top' => '10px'], 'margins.top must be numeric'];
        yield 'negative side' => [['left' => -5], 'margins.left must be non-negative'];
    }

    #[DataProvider('invalidMargins')]
    public function testAValueOfTheWrongShapeIsRejected(mixed $margins, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::margins($margins);
    }
}
