<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class LineBreakElementTest extends TestCase
{
    /** @return list<string> */
    private function lineTexts(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 600,
            'viewportHeight' => 400,
        ]);

        foreach ($prepared->layoutRoot->children as $child) {
            if ($child->source->node->isElement('p')) {
                return array_map(static fn ($line): string => $line->text, $child->lineBoxes);
            }
        }

        self::fail('nenhum <p> encontrado no layout');
    }

    public function testBrStartsANewLineUnderDefaultWhiteSpace(): void
    {
        self::assertSame(
            ['uma', 'duas', 'tres'],
            $this->lineTexts('<p style="margin:0">uma<br/>duas<br/>tres</p>'),
        );
    }

    public function testConsecutiveBrProducesAnEmptyLineInBetween(): void
    {
        self::assertSame(['a', '', 'b'], $this->lineTexts('<p style="margin:0">a<br/><br/>b</p>'));
    }

    public function testEmptyLineFromBrStillOccupiesTheBlockLineHeight(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;font-size:10px;line-height:20px">a<br/><br/>b</p>',
            'viewportWidth' => 600,
            'viewportHeight' => 400,
        ]);

        $paragraph = $prepared->layoutRoot->children[0];
        self::assertCount(3, $paragraph->lineBoxes);
        self::assertSame(20.0, $paragraph->lineBoxes[1]->height);
        self::assertSame(20.0, $paragraph->lineBoxes[1]->y - $paragraph->lineBoxes[0]->y);
    }

    public function testBrBreaksEvenWhenSoftWrappingIsDisabled(): void
    {
        self::assertSame(['a', 'b'], $this->lineTexts('<p style="margin:0;white-space:nowrap">a<br/>b</p>'));
    }

    public function testBrHiddenWithDisplayNoneDoesNotBreak(): void
    {
        self::assertSame(['ab'], $this->lineTexts('<p style="margin:0">a<br style="display:none"/>b</p>'));
    }

    public function testBrNestedInAnInlineElementStillBreaksTheParentLine(): void
    {
        self::assertSame(['ax', 'yb'], $this->lineTexts('<p style="margin:0">a<span>x<br/>y</span>b</p>'));
    }
}
