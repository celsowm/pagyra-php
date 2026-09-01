<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\BorderPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use Pagyra\Style\StyledNode;
use PHPUnit\Framework\TestCase;

final class UserAgentElementDefaultsTest extends TestCase
{
    private function findStyled(StyledNode $node, string $tag): StyledNode
    {
        if ($node->node->isElement($tag)) return $node;
        foreach ($node->children as $child) {
            try {
                return $this->findStyled($child, $tag);
            } catch (\RuntimeException) {
                continue;
            }
        }
        throw new \RuntimeException('elemento nao encontrado: ' . $tag);
    }

    private function styleOf(string $html, string $tag, string $property): ?string
    {
        $prepared = Pagyra::prepareHtmlRender(['html' => $html]);
        return $this->findStyled($prepared->styledRoot, $tag)->style->get($property);
    }

    public function testHrIsABlockThatCarriesTheReferenceRuleGeometry(): void
    {
        $html = '<p>antes</p><hr/><p>depois</p>';
        self::assertSame('block', $this->styleOf($html, 'hr', 'display'));
        self::assertSame('1px', $this->styleOf($html, 'hr', 'border-top-width'));
        self::assertSame('solid', $this->styleOf($html, 'hr', 'border-top-style'));
        self::assertSame('#a0a0a0', $this->styleOf($html, 'hr', 'border-top-color'));
        self::assertSame('0.5em', $this->styleOf($html, 'hr', 'margin-top'));
    }

    public function testHrActuallyPaintsARuleInTheDisplayList(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0">antes</p><hr/><p style="margin:0">depois</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 400,
        ]);

        $borders = [];
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof BorderPaintCommand) $borders[] = $command;
            }
        }

        self::assertNotSame([], $borders, 'o <hr> nao gerou nenhum comando de borda');
    }

    public function testHrKeepsAZeroHeightContentBoxSoItIsJustTheRule(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<hr/>',
            'viewportWidth' => 300,
            'viewportHeight' => 400,
        ]);

        $hr = $prepared->layoutRoot->children[0];
        self::assertSame('hr', $hr->source->node->tagName);
        self::assertSame(0.0, $hr->box->content->height);
        self::assertSame(1.0, $hr->box->border->top);
        self::assertSame(1.0, $hr->box->borderBox()->height);
    }

    public function testUnderlineAndLineThroughTagsCarryTheirDecoration(): void
    {
        self::assertSame('underline', $this->styleOf('<p><u>x</u></p>', 'u', 'text-decoration-line'));
        self::assertSame('line-through', $this->styleOf('<p><s>x</s></p>', 's', 'text-decoration-line'));
        self::assertSame('line-through', $this->styleOf('<p><del>x</del></p>', 'del', 'text-decoration-line'));
        self::assertSame('line-through', $this->styleOf('<p><strike>x</strike></p>', 'strike', 'text-decoration-line'));
    }

    public function testUnderlineTagReachesTheTextPaintCommand(): void
    {
        $prepared = Pagyra::prepareHtmlRender(['html' => '<p><u>sublinhado</u></p>']);

        $underlined = [];
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand && $command->underline) $underlined[] = $command->text;
            }
        }

        self::assertContains('sublinhado', $underlined);
    }

    public function testRemainingHeadingLevelsGetTheReferenceSizesAndWeight(): void
    {
        self::assertSame('bold', $this->styleOf('<h4>x</h4>', 'h4', 'font-weight'));
        self::assertSame('1em', $this->styleOf('<h4>x</h4>', 'h4', 'font-size'));
        self::assertSame('0.83em', $this->styleOf('<h5>x</h5>', 'h5', 'font-size'));
        self::assertSame('0.67em', $this->styleOf('<h6>x</h6>', 'h6', 'font-size'));
        self::assertSame('2.33em', $this->styleOf('<h6>x</h6>', 'h6', 'margin-top'));
    }

    public function testAnchorGetsTheReferenceLinkColor(): void
    {
        self::assertSame('#0000EE', $this->styleOf('<p><a href="https://exemplo.test">x</a></p>', 'a', 'color'));
    }

    public function testTableCellsGetDefaultPaddingAndHeaderCellsAreBoldAndCentered(): void
    {
        $html = '<table><tr><th>cabecalho</th><td>celula</td></tr></table>';
        self::assertSame('8px', $this->styleOf($html, 'td', 'padding-left'));
        self::assertSame('8px', $this->styleOf($html, 'th', 'padding-top'));
        self::assertSame('bold', $this->styleOf($html, 'th', 'font-weight'));
        self::assertSame('center', $this->styleOf($html, 'th', 'text-align'));
    }

    public function testInlineFormattingTagsStayInline(): void
    {
        self::assertSame('inline', $this->styleOf('<p><u>x</u></p>', 'u', 'display'));
        self::assertSame('inline', $this->styleOf('<p><code>x</code></p>', 'code', 'display'));
    }

    public function testAuthorCssStillOverridesTheseDefaults(): void
    {
        $html = '<style>hr { border-top-color: #ff0000 } u { text-decoration-line: none }</style><hr/><p><u>x</u></p>';
        self::assertSame('#ff0000', $this->styleOf($html, 'hr', 'border-top-color'));
        self::assertSame('none', $this->styleOf($html, 'u', 'text-decoration-line'));
    }
}
