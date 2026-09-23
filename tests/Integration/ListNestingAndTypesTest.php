<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\TextRun;
use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\RoundedBorderPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * Nested lists step their bullet (disc, circle, square) and drop their vertical margins, the
 * `type` and `reversed` attributes are honoured, and the bullets are drawn as shapes, so `circle`
 * and `square` no longer print `?` in the Base14 fonts.
 */
final class ListNestingAndTypesTest extends TestCase
{
    /** @return list<object> */
    private function commands(string $html): array
    {
        return Pagyra::prepareHtmlRender(['html' => $html])->displayList->pages[0]->commands;
    }

    /** @return list<string> */
    private function texts(string $html): array
    {
        $texts = [];
        foreach ($this->commands($html) as $command) {
            if ($command instanceof TextPaintCommand) $texts[] = $command->text;
        }

        return $texts;
    }

    /** @return list<string> */
    private function bulletShapes(string $html): array
    {
        $shapes = [];
        foreach ($this->commands($html) as $command) {
            if ($command instanceof RoundedBorderPaintCommand && $command->node instanceof TextRun) {
                $shapes[] = 'circle';
            } elseif ($command instanceof BoxPaintCommand && $command->node instanceof TextRun) {
                $shapes[] = $command->borderRadius->isZero() ? 'square' : 'disc';
            }
        }

        return $shapes;
    }

    public function testNestedUnorderedListsStepTheirBullet(): void
    {
        self::assertSame(
            ['disc', 'circle', 'square', 'square'],
            $this->bulletShapes('<ul><li>a<ul><li>b<ul><li>c<ul><li>d</li></ul></li></ul></li></ul></li></ul>'),
        );
        // Any list ancestor counts, as in the UA sheet's :is(dir, menu, ol, ul) :is(dir, menu, ol, ul) ul.
        self::assertSame(['disc', 'square'], $this->bulletShapes('<ul><li>a<ol><li>b<ul><li>c</li></ul></li></ol></li></ul>'));
    }

    public function testAuthorListStyleStillWins(): void
    {
        self::assertSame(['disc', 'disc'], $this->bulletShapes('<style>ul{list-style-type:disc}</style><ul><li>a<ul><li>b</li></ul></li></ul>'));
    }

    public function testNestedListsHaveNoVerticalMargin(): void
    {
        $commands = $this->commands('<ul><li>um<ul><li>dois</li></ul></li><li>tres</li></ul>');
        $y = [];
        foreach ($commands as $command) {
            if ($command instanceof TextPaintCommand) $y[$command->text] = $command->y;
        }

        self::assertEqualsWithDelta($y['dois'] - $y['um'], $y['tres'] - $y['dois'], 0.001);
    }

    public function testTypeAttributes(): void
    {
        self::assertSame(['a.', 'x', 'b.', 'y'], $this->texts('<ol type="a"><li>x</li><li>y</li></ol>'));
        self::assertSame(['III.', 'x'], $this->texts('<ol type="I" start="3"><li>x</li></ol>'));
        self::assertSame(['1.', 'x', 'B.', 'y'], $this->texts('<ol><li>x</li><li type="A">y</li></ol>'));
        self::assertSame(['square'], $this->bulletShapes('<ul type="square"><li>x</li></ul>'));
    }

    public function testReversedListCountsDown(): void
    {
        self::assertSame(['3.', 'c', '2.', 'b', '1.', 'a'], $this->texts('<ol reversed><li>c</li><li>b</li><li>a</li></ol>'));
        self::assertSame(['10.', 'c', '9.', 'b'], $this->texts('<ol reversed start="10"><li>c</li><li>b</li></ol>'));
    }
}
