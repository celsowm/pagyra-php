<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * `text-overflow: ellipsis` (CSS Overflow 3 §5) only does anything with `overflow-x` (or the
 * `overflow` shorthand) clipping and `white-space: nowrap` forcing the content onto one line that
 * can overflow in the first place. It was ignored outright — the initial `clip` behaviour, which
 * for this port means the line simply keeps going past the box — so a narrow badge or table cell
 * meant to truncate a long value spilled its text into its neighbours instead.
 */
final class TextOverflowEllipsisTest extends TestCase
{
    private function firstText(string $html, float $width = 60.0): TextPaintCommand
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => $html,
            'viewportWidth' => (int) $width,
            'viewportHeight' => 200,
        ]);
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand) return $command;
        }
        self::fail('no text');
    }

    private const STYLE = 'margin:0;width:60px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis';

    public function testLongTextIsTruncatedWithAnEllipsis(): void
    {
        $command = $this->firstText('<p style="' . self::STYLE . '">um texto bem mais longo do que a caixa</p>');

        self::assertStringEndsWith("\u{2026}", $command->text);
        self::assertLessThan(mb_strlen('um texto bem mais longo do que a caixa'), mb_strlen($command->text));
    }

    public function testTheTruncatedLineFitsWithinTheAvailableWidth(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<p style="' . self::STYLE . '">um texto bem mais longo do que a caixa</p>',
            'viewportWidth' => 60,
            'viewportHeight' => 200,
        ]);
        $li = $prepared->layoutRoot->children[0];
        self::assertCount(1, $li->lineBoxes);
        self::assertLessThanOrEqual(60.0 + 0.5, $li->lineBoxes[0]->width);
    }

    public function testShortTextThatAlreadyFitsIsLeftAlone(): void
    {
        $command = $this->firstText('<p style="' . self::STYLE . '">curto</p>');

        self::assertSame('curto', $command->text);
    }

    public function testWithoutOverflowHiddenTheLineIsLeftAlone(): void
    {
        $command = $this->firstText('<p style="margin:0;width:60px;white-space:nowrap;text-overflow:ellipsis">um texto bem mais longo do que a caixa</p>');

        self::assertStringNotContainsString("\u{2026}", $command->text);
    }

    public function testWithoutNowrapTheLineWrapsInsteadOfTruncating(): void
    {
        $command = $this->firstText('<p style="margin:0;width:60px;overflow:hidden;text-overflow:ellipsis">um texto bem mais longo do que a caixa</p>');

        self::assertStringNotContainsString("\u{2026}", $command->text);
    }

    public function testAtomicInlineBoxThatFitsIsKeptBeforeTheEllipsis(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<p style="' . self::STYLE . '">'
                . 'A<span style="display:inline-block;width:20px;height:10px"></span>'
                . 'texto-muito-longo'
                . '</p>',
            'viewportWidth' => 60,
            'viewportHeight' => 200,
        ]);

        $line = $prepared->layoutRoot->children[0]->lineBoxes[0];
        self::assertCount(1, $line->atomicBoxes);
        self::assertStringEndsWith("\u{2026}", $line->text);
        self::assertLessThanOrEqual(60.5, $line->width);
    }

    public function testDefaultTextOverflowClipIsUnaffected(): void
    {
        $command = $this->firstText('<p style="margin:0;width:60px;white-space:nowrap;overflow:hidden">um texto bem mais longo do que a caixa</p>');

        self::assertStringNotContainsString("\u{2026}", $command->text);
        self::assertSame('um texto bem mais longo do que a caixa', $command->text);
    }
}
