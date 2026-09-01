<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlockLevelElementDefaultsTest extends TestCase
{
    private function paintedText(string $html): string
    {
        $prepared = Pagyra::prepareHtmlRender(['html' => $html]);
        $text = '';
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand) $text .= $command->text;
            }
        }
        return $text;
    }

    /** @return list<array{0:string}> */
    public static function blockLevelTags(): array
    {
        return array_map(
            static fn (string $tag): array => [$tag],
            ['blockquote', 'figure', 'figcaption', 'pre', 'address', 'dl', 'dt', 'dd',
             'fieldset', 'form', 'aside', 'hgroup', 'center', 'details', 'summary', 'dir', 'menu', 'legend'],
        );
    }

    #[DataProvider('blockLevelTags')]
    public function testTagIsBlockLevel(string $tag): void
    {
        $prepared = Pagyra::prepareHtmlRender(['html' => "<{$tag}>conteudo</{$tag}>"]);

        $found = null;
        $walk = static function ($node) use (&$walk, $tag, &$found): void {
            if ($node->node->isElement($tag)) $found = $node;
            foreach ($node->children as $child) $walk($child);
        };
        $walk($prepared->styledRoot);

        self::assertNotNull($found, "elemento <{$tag}> nao encontrado");
        self::assertSame('block', $found->style->get('display'));
    }

    public function testContentOfABlockLevelTagSurvivesAmongBlockSiblings(): void
    {
        // The motivating failure: an inline-resolved element sitting among block siblings is
        // skipped by the block engine, so its text never reaches the page at all.
        $text = $this->paintedText('<p>antes</p><blockquote>CITADO</blockquote><p>depois</p>');

        self::assertStringContainsString('CITADO', $text);
        self::assertStringContainsString('antes', $text);
        self::assertStringContainsString('depois', $text);
    }

    public function testDocumentBuiltOutOfBlockquotesKeepsEveryOne(): void
    {
        $html = '';
        for ($i = 1; $i <= 12; $i++) $html .= "<blockquote>trecho{$i}</blockquote>";

        $text = $this->paintedText($html);
        for ($i = 1; $i <= 12; $i++) self::assertStringContainsString("trecho{$i}", $text);
    }

    public function testBlockquoteIsIndentedLikeTheCss21DefaultStylesheet(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<blockquote>citado</blockquote>',
            'viewportWidth' => 400,
        ]);

        $quote = $prepared->layoutRoot->children[0];
        self::assertSame(40.0, $quote->box->margin->left);
        self::assertSame(40.0, $quote->box->margin->right);
    }

    public function testPreKeepsItsOwnLineBreaks(): void
    {
        $prepared = Pagyra::prepareHtmlRender(['html' => "<pre>um\ndois\ntres</pre>"]);

        $pre = $prepared->layoutRoot->children[0];
        self::assertSame('pre', $pre->source->node->tagName);
        self::assertCount(3, $pre->lineBoxes);
    }

    public function testCenterCentersItsContent(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<center>meio</center>',
            'viewportWidth' => 400,
        ]);

        self::assertSame('center', $prepared->layoutRoot->children[0]->source->style->get('text-align'));
    }
}
