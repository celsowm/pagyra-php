<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\TextRun;
use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * The background of an inline element (`<span style="background-color:yellow">`, the highlight
 * that pasted editor text carries) is painted behind its glyphs. It used to be dropped: the text
 * run carries the text node's style, which only receives inherited properties, so the span's
 * non-inherited background never reached the paint layer.
 */
final class InlineBackgroundPaintTest extends TestCase
{
    /** @return list<object> */
    private function commands(string $html): array
    {
        $commands = [];
        foreach (Pagyra::prepareHtmlRender(['html' => $html])->displayList->pages as $page) {
            array_push($commands, ...$page->commands);
        }

        return $commands;
    }

    /** @return list<BoxPaintCommand> */
    private function inlineBackgrounds(array $commands): array
    {
        return array_values(array_filter(
            $commands,
            static fn(object $c): bool => $c instanceof BoxPaintCommand && $c->node instanceof TextRun,
        ));
    }

    private function textCommand(array $commands, string $text): TextPaintCommand
    {
        foreach ($commands as $command) {
            if ($command instanceof TextPaintCommand && trim($command->text) === $text) {
                return $command;
            }
        }
        self::fail('no text run "' . $text . '"');
    }

    public function testHighlightedSpanPaintsABandBehindItsText(): void
    {
        $commands = $this->commands('<p>antes <span style="background-color:yellow">grifado</span> depois</p>');

        $backgrounds = $this->inlineBackgrounds($commands);
        self::assertCount(1, $backgrounds);
        $band = $backgrounds[0];
        $text = $this->textCommand($commands, 'grifado');

        self::assertSame(255, (int) round($band->backgroundColor->r));
        self::assertSame(255, (int) round($band->backgroundColor->g));
        self::assertSame(0, (int) round($band->backgroundColor->b));
        self::assertEqualsWithDelta($text->x, $band->x, 0.001);
        self::assertEqualsWithDelta($text->run->width, $band->width, 0.001);
        // The band hugs the glyphs around the baseline, and is painted before them.
        self::assertLessThan($text->baseline, $band->y);
        self::assertGreaterThan($text->baseline, $band->y + $band->height);
        self::assertLessThan(array_search($text, $commands, true), array_search($band, $commands, true));
    }

    public function testBandFollowsTheFontAndNotTheLineHeight(): void
    {
        $commands = $this->commands('<p style="line-height:3">x <span style="background:#ff0">y</span> z</p>');

        $band = $this->inlineBackgrounds($commands)[0];
        self::assertEqualsWithDelta(16.0 * 1.12, $band->height, 0.001);
    }

    public function testNestedElementsInsideTheHighlightAreCoveredToo(): void
    {
        $commands = $this->commands('<p><span style="background-color:#c6c6c6">a <b>negrito</b> b</span> fora</p>');

        $painted = [];
        foreach ($this->inlineBackgrounds($commands) as $band) {
            $painted[] = trim($band->node->text);
        }
        self::assertContains('negrito', $painted);
        self::assertNotContains('fora', $painted);
    }

    public function testTransparentAndAbsentBackgroundsPaintNothing(): void
    {
        $commands = $this->commands(
            '<p><span>a</span> <span style="background-color:transparent">b</span> <span style="background:rgba(0,0,0,0)">c</span></p>',
        );

        self::assertSame([], $this->inlineBackgrounds($commands));
    }

    public function testHiddenHighlightIsNotPainted(): void
    {
        $commands = $this->commands('<p>a <span style="background:yellow;visibility:hidden">b</span></p>');

        self::assertSame([], $this->inlineBackgrounds($commands));
    }
}
