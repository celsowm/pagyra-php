<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * Stylesheet rules with pseudo-classes and sibling combinators reach the page, and `rem`/`ch`/`ex`
 * in box properties resolve against the root font-size and the element's font.
 */
final class StructuralSelectorsAndRootUnitsTest extends TestCase
{
    /** @return array<string,TextPaintCommand> */
    private function textByContent(string $html): array
    {
        $found = [];
        foreach (Pagyra::prepareHtmlRender(['html' => $html])->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand) {
                    $found[trim($command->text)] = $command;
                }
            }
        }

        return $found;
    }

    public function testPseudoClassesAndSiblingCombinatorsApply(): void
    {
        $text = $this->textByContent(
            '<style>li:first-child{color:#ff0000} li:nth-child(2n){color:#0000ff} h2 + p{color:#00ff00} p:not(.x){font-size:20px}</style>'
            . '<ul><li>um</li><li>dois</li><li>tres</li></ul><h2>T</h2><p>adjacente</p><p class="x">outro</p>',
        );

        self::assertSame(255, (int) round($text['um']->color->r));
        self::assertSame(255, (int) round($text['dois']->color->b));
        self::assertSame(0, (int) round($text['tres']->color->r + $text['tres']->color->g + $text['tres']->color->b));
        self::assertSame(255, (int) round($text['adjacente']->color->g));
        self::assertEqualsWithDelta(20.0, $text['adjacente']->fontSize, 0.001);
        self::assertEqualsWithDelta(16.0, $text['outro']->fontSize, 0.001);
    }

    public function testRootCustomPropertiesReachTheDocument(): void
    {
        $text = $this->textByContent('<style>:root{--cor:#ff0000} p{color:var(--cor)}</style><p>x</p>');

        self::assertSame(255, (int) round($text['x']->color->r));
    }

    public function testRemChAndExInBoxPropertiesAreAbsolutized(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>html{font-size:10px} div{background:#ccc;height:5px}</style>'
                . '<div style="width:20rem"></div><div style="width:20ch;font-size:20px"></div><div style="width:10ex"></div>',
        ]);

        $widths = [];
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof BoxPaintCommand && $command->backgroundColor !== null) {
                $widths[] = $command->width;
            }
        }

        self::assertEqualsWithDelta([200.0, 200.0, 50.0], $widths, 0.001);
    }
}
