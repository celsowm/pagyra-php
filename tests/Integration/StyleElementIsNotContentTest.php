<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

final class StyleElementIsNotContentTest extends TestCase
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

    public function testStyleInsideABlockDoesNotPrintItsOwnCss(): void
    {
        self::assertSame(
            'antesdepois',
            $this->paintedText('<div>antes<style>.segredo { color: red }</style>depois</div>'),
        );
    }

    public function testStyleInsideABlockStillFeedsTheCascade(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div><style>p { width: 123px }</style><p>texto</p></div>',
        ]);

        self::assertSame(123.0, $prepared->layoutRoot->children[0]->children[0]->box->content->width);
    }

    public function testStyleIsAbsentFromTheStyledTree(): void
    {
        $prepared = Pagyra::prepareHtmlRender(['html' => '<style>p { color: red }</style><p>texto</p>']);

        $tags = [];
        $walk = static function ($node) use (&$walk, &$tags): void {
            if ($node->node->type === 'element') $tags[] = $node->node->tagName;
            foreach ($node->children as $child) $walk($child);
        };
        $walk($prepared->styledRoot);

        self::assertNotContains('style', $tags);
        self::assertContains('p', $tags);
    }
}
