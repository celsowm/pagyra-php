<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * Two ways content used to vanish without an error: a table's `<caption>` was skipped by the
 * table layout, and an element with `display: contents` reached the inline formatter, which drops
 * block-level children.
 */
final class CaptionAndDisplayContentsTest extends TestCase
{
    /** @return array<string,TextPaintCommand> */
    private function texts(string $html): array
    {
        $found = [];
        foreach (Pagyra::prepareHtmlRender(['html' => $html, 'pagedBodyMargin' => 'zero'])->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand && trim($command->text) !== '') {
                    $found[trim($command->text)] = $command;
                }
            }
        }

        return $found;
    }

    public function testCaptionsAreRenderedAboveAndBelowTheGrid(): void
    {
        $texts = $this->texts(
            '<p>antes</p><table border="1"><caption>Legenda</caption><tr><td>celula</td></tr>'
            . '<caption style="caption-side:bottom">Fonte</caption></table><p>depois</p>',
        );

        self::assertArrayHasKey('Legenda', $texts);
        self::assertArrayHasKey('Fonte', $texts);
        self::assertLessThan($texts['celula']->y, $texts['Legenda']->y);
        self::assertGreaterThan($texts['celula']->y, $texts['Fonte']->y);
        self::assertGreaterThan($texts['Fonte']->y, $texts['depois']->y);
        // Centred over the table, as the UA sheet does.
        self::assertGreaterThan($texts['celula']->x + 100.0, $texts['Legenda']->x);
    }

    public function testTableMarginsStayOutsideTheCaptions(): void
    {
        $texts = $this->texts(
            '<p style="margin:0">antes</p><table style="margin:20px 0"><caption style="margin:0">Legenda</caption>'
            . '<tr><td style="padding:0">celula</td></tr><caption style="caption-side:bottom;margin:0">Fonte</caption></table>'
            . '<p style="margin:0">depois</p>',
        );

        $line = $texts['antes']->run->height;
        self::assertEqualsWithDelta($texts['antes']->y + $line + 20.0, $texts['Legenda']->y, 0.001);
        self::assertEqualsWithDelta($texts['Fonte']->y + $line + 20.0, $texts['depois']->y, 0.001);
    }

    public function testDisplayContentsKeepsItsChildren(): void
    {
        $texts = $this->texts(
            '<p>a</p><div style="display:contents;color:#ff0000"><p>bloco</p> solto <b>negrito</b></div><p>b</p>',
        );

        self::assertSame(['a', 'bloco', 'solto', 'negrito', 'b'], array_keys($texts));
        self::assertSame(255, (int) round($texts['solto']->color->r));
        self::assertSame(255, (int) round($texts['bloco']->color->r));
        self::assertLessThan($texts['b']->y, $texts['bloco']->y);
    }

    public function testDisplayContentsHasNoBoxOfItsOwn(): void
    {
        $plain = $this->texts('<div><p style="margin:0">x</p></div>');
        $contents = $this->texts('<div style="display:contents;padding:30px;margin:30px"><p style="margin:0">x</p></div>');

        self::assertEqualsWithDelta($plain['x']->x, $contents['x']->x, 0.001);
        self::assertEqualsWithDelta($plain['x']->y, $contents['x']->y, 0.001);
    }
}
