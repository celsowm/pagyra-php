<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/** `::before`/`::after` content, CSS counters and quotes. */
final class GeneratedContentTest extends TestCase
{
    /** @return list<TextPaintCommand> */
    private function runs(string $html): array
    {
        $runs = [];
        foreach (Pagyra::prepareHtmlRender(['html' => $html])->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand) $runs[] = $command;
            }
        }

        return $runs;
    }

    private function text(string $html): string
    {
        return implode('|', array_map(static fn(TextPaintCommand $c): string => trim($c->text), $this->runs($html)));
    }

    public function testStringsAndAttributes(): void
    {
        self::assertSame(
            '[|texto|] (x)',
            $this->text('<style>p::before{content:"["} p::after{content:"] (" attr(data-id) ")"}</style><p data-id="x">texto</p>'),
        );
    }

    public function testPseudoElementHasItsOwnStyle(): void
    {
        $runs = $this->runs('<style>.a::before{content:"B";color:#ff0000;font-size:30px}</style><p class="a">x</p>');

        self::assertSame('B', $runs[0]->text);
        self::assertSame(255, (int) round($runs[0]->color->r));
        self::assertEqualsWithDelta(30.0, $runs[0]->fontSize, 0.001);
        self::assertEqualsWithDelta(16.0, $runs[1]->fontSize, 0.001);
    }

    public function testCountersNumberHeadings(): void
    {
        self::assertSame(
            '1.|Um|2.|Dois|II -|p',
            $this->text('<style>body{counter-reset:sec} h3::before{counter-increment:sec;content:counter(sec) ". "} p::before{content:counter(sec, upper-roman) " - "}</style><h3>Um</h3><h3>Dois</h3><p>p</p>'),
        );
    }

    public function testNestedCountersAndScopes(): void
    {
        $html = '<style>ol{counter-reset:item;list-style:none} li::before{counter-increment:item;content:counters(item, ".") ") "}</style>'
            . '<ol><li>a<ol><li>b</li><li>c</li></ol></li><li>d</li></ol>';

        self::assertSame('1)|a|1.1)|b|1.2)|c|2)|d', $this->text($html));
    }

    public function testCounterIncrementWithoutResetCreatesTheCounterAtThatElement(): void
    {
        // CSS 2.1 12.4.1: each h3 then gets its own new counter, as in browsers.
        self::assertSame('1|a|1|b', $this->text('<style>h3::before{counter-increment:n;content:counter(n)}</style><h3>a</h3><h3>b</h3>'));
    }

    public function testQuotesNestAndCanBeChanged(): void
    {
        self::assertSame("\u{201C}|a|\u{2018}|b|\u{2019}|\u{201D}", $this->text('<p><q>a <q>b</q></q></p>'));
        self::assertSame('«|a|»', $this->text('<style>q{quotes:"«" "»"}</style><p><q>a</q></p>'));
    }

    public function testNoContentAndHiddenElementsGenerateNothing(): void
    {
        self::assertSame('x', $this->text('<style>p::before{color:red} p::after{content:none}</style><p>x</p>'));
        self::assertSame('y', $this->text('<style>.h::before{content:"N"}</style><p class="h" style="display:none">x</p><p>y</p>'));
    }

    public function testEscapesInStrings(): void
    {
        self::assertSame("\u{201C}x\"y", explode('|', $this->text('<style>p::before{content:"\\201C x\\"y"}</style><p>z</p>'))[0]);
    }
}
