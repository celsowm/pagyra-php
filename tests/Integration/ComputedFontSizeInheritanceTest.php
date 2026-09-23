<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * Descendants inherit the computed, absolute font-size, not the declared relative one. Before,
 * the declared text was inherited and resolved again at every level, so `font-size: 2em` doubled
 * once per nesting level and `90%` kept shrinking.
 */
final class ComputedFontSizeInheritanceTest extends TestCase
{
    /** @return array<string,TextPaintCommand> */
    private function runsByText(string $html): array
    {
        $runs = [];
        foreach (Pagyra::prepareHtmlRender(['html' => $html])->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand) {
                    $runs[trim($command->text)] = $command;
                }
            }
        }

        return $runs;
    }

    private function styleOf(string $html, string $tag, string $property): ?string
    {
        $found = null;
        $walk = function ($node) use (&$walk, $tag, $property, &$found): void {
            if ($found === null && ($node->node->tagName ?? null) === $tag) {
                $found = $node->style->get($property);
            }
            foreach ($node->children as $child) $walk($child);
        };
        $walk(Pagyra::prepareHtmlRender(['html' => $html])->styledRoot);

        return $found;
    }

    public function testEmFontSizeDoesNotCompoundInDescendants(): void
    {
        $runs = $this->runsByText('<div style="font-size:2em"><p>a</p><p><span>b <b>c</b></span></p></div>');

        self::assertEqualsWithDelta(32.0, $runs['a']->fontSize, 0.001);
        self::assertEqualsWithDelta(32.0, $runs['b']->fontSize, 0.001);
        self::assertEqualsWithDelta(32.0, $runs['c']->fontSize, 0.001);
    }

    public function testNestedRelativeSizesMultiplyOnceEach(): void
    {
        $runs = $this->runsByText('<div style="font-size:20px"><p style="font-size:90%">a <span style="font-size:0.5em">b <i>c</i></span></p></div>');

        self::assertEqualsWithDelta(18.0, $runs['a']->fontSize, 0.001);
        self::assertEqualsWithDelta(9.0, $runs['b']->fontSize, 0.001);
        self::assertEqualsWithDelta(9.0, $runs['c']->fontSize, 0.001);
    }

    public function testRelativeKeywordsResolveAgainstTheParentOnce(): void
    {
        $runs = $this->runsByText('<p style="font-size:20px">a <span style="font-size:smaller">b <b>c</b></span></p>');

        self::assertEqualsWithDelta(16.0, $runs['b']->fontSize, 0.001);
        self::assertEqualsWithDelta(16.0, $runs['c']->fontSize, 0.001);
    }

    public function testComputedFontSizeIsAnAbsoluteLength(): void
    {
        self::assertSame('17.3333px', $this->styleOf('<p style="font-size:13pt">x</p>', 'p', 'font-size'));
        self::assertSame('24px', $this->styleOf('<div style="font-size:12px"><p style="font-size:2em">x</p></div>', 'p', 'font-size'));
    }

    public function testEmLineHeightIsComputedOnTheDeclaringElement(): void
    {
        // CSS inherits `line-height: 1.2em` as the length it computes to on the paragraph,
        // not as a factor to be re-applied to every child's own font-size.
        self::assertSame('24px', $this->styleOf('<p style="font-size:20px;line-height:1.2em"><span style="font-size:10px">x</span></p>', 'span', 'line-height'));
        self::assertSame('1.5', $this->styleOf('<p style="font-size:20px;line-height:1.5"><span>x</span></p>', 'span', 'line-height'));
    }

    public function testEmSpacingIsComputedOnTheDeclaringElement(): void
    {
        self::assertSame('2px', $this->styleOf('<p style="font-size:20px;letter-spacing:0.1em"><span style="font-size:40px">x</span></p>', 'span', 'letter-spacing'));
    }
}
