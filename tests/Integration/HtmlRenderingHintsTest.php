<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * The HTML Standard's rendering section (§15) for elements and attributes that were rendered as
 * plain text: hidden content, the phrasing elements the UA sheet did not style, and the legacy
 * presentational attributes (`<font>`, `bgcolor`, `nowrap`, `<hr width/align/color>`,
 * `<table align>`).
 */
final class HtmlRenderingHintsTest extends TestCase
{
    /** @return array<string,TextPaintCommand> */
    private function texts(string $html): array
    {
        $found = [];
        foreach (Pagyra::prepareHtmlRender(['html' => $html])->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand && trim($command->text) !== '') {
                    $found[trim($command->text)] = $command;
                }
            }
        }

        return $found;
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

    public function testHiddenContentIsNotRendered(): void
    {
        $texts = $this->texts(
            '<p>visivel</p><p hidden>atributo</p><template><p>modelo</p></template><noscript>semscript</noscript>'
            . '<dialog>fechado</dialog><dialog open>aberto</dialog>'
            . '<details><summary>resumo</summary><p>oculto</p>solto</details><details open><summary>r2</summary><p>exposto</p></details>',
        );

        self::assertSame(['visivel', 'aberto', 'resumo', 'r2', 'exposto'], array_keys($texts));
    }

    public function testAuthorDisplayBeatsTheHiddenAttribute(): void
    {
        self::assertArrayHasKey('x', $this->texts('<p hidden style="display:block">x</p>'));
    }

    public function testPhrasingElementsGetTheirRenderingDefaults(): void
    {
        $texts = $this->texts('<p>n <small>pequeno</small> <big>grande</big> <cite>obra</cite> <var>v</var> <ins>inserido</ins> <kbd>tecla</kbd></p>');

        self::assertEqualsWithDelta(12.8, $texts['pequeno']->fontSize, 0.001);
        self::assertEqualsWithDelta(19.2, $texts['grande']->fontSize, 0.001);
        self::assertSame('italic', $texts['obra']->fontStyle);
        self::assertSame('italic', $texts['v']->fontStyle);
        self::assertTrue($texts['inserido']->underline);
        self::assertStringContainsString('monospace', (string) $texts['tecla']->fontFamily);
    }

    public function testMarkIsHighlighted(): void
    {
        $prepared = Pagyra::prepareHtmlRender(['html' => '<p>a <mark>grifo</mark></p>']);
        $bands = array_filter(
            $prepared->displayList->pages[0]->commands,
            static fn(object $c): bool => $c instanceof BoxPaintCommand && $c->node instanceof \Pagyra\Layout\TextRun,
        );

        self::assertCount(1, $bands);
    }

    public function testFontElementAttributes(): void
    {
        $texts = $this->texts('<p><font color="red" size="5" face="Arial">grande</font> <font size="-2">menor</font> <font color="00ff00">verde</font></p>');

        self::assertEqualsWithDelta(24.0, $texts['grande']->fontSize, 0.001);
        self::assertSame(255, (int) round($texts['grande']->color->r));
        self::assertStringContainsString('Arial', (string) $texts['grande']->fontFamily);
        self::assertEqualsWithDelta(10.0, $texts['menor']->fontSize, 0.001);
        self::assertSame(255, (int) round($texts['verde']->color->g));
    }

    public function testTableAttributes(): void
    {
        $html = '<table align="center" width="200"><tr bgcolor="#c0c0c0"><td nowrap bgcolor="silver">a</td></tr></table>';

        self::assertSame('#c0c0c0', $this->styleOf($html, 'tr', 'background-color'));
        self::assertSame('silver', $this->styleOf($html, 'td', 'background-color'));
        self::assertSame('nowrap', $this->styleOf($html, 'td', 'white-space'));
        self::assertSame('auto', $this->styleOf($html, 'table', 'margin-left'));
        self::assertNull($this->styleOf($html, 'table', 'text-align'));
    }

    public function testHorizontalRuleAttributes(): void
    {
        $html = '<hr width="50%" align="left" color="red">';

        self::assertSame('50%', $this->styleOf($html, 'hr', 'width'));
        self::assertSame('0', $this->styleOf($html, 'hr', 'margin-left'));
        self::assertSame('auto', $this->styleOf($html, 'hr', 'margin-right'));
        self::assertSame('red', $this->styleOf($html, 'hr', 'border-top-color'));
    }
}
