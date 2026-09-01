<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\ImagePaintCommand;
use Pagyra\Core\PreparedRender;
use PHPUnit\Framework\TestCase;

final class BlockLevelReplacedElementTest extends TestCase
{
    // 32x16 opaque PNG.
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAQCAYAAAB3AH1ZAAAAG0lEQVRIx2NgGAWjYBSMglEwCkbBKBgFo4B6AAAI+gAB8f6bIgAAAABJRU5ErkJggg==';

    private function render(string $html): PreparedRender
    {
        return Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 400,
            'viewportHeight' => 400,
        ]);
    }

    /** @return list<ImagePaintCommand> */
    private function images(PreparedRender $prepared): array
    {
        $images = [];
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof ImagePaintCommand) $images[] = $command;
            }
        }
        return $images;
    }

    public function testBlockImageIsPaintedInsteadOfSilentlyDisappearing(): void
    {
        $images = $this->images($this->render('<div><img style="display:block" src="' . self::PNG . '"/></div>'));

        self::assertCount(1, $images);
        self::assertSame(32.0, $images[0]->width);
        self::assertSame(16.0, $images[0]->height);
    }

    public function testBlockImageUsesItsIntrinsicSizeInsteadOfStretchingToTheContainer(): void
    {
        $prepared = $this->render('<div style="width:400px"><img style="display:block" src="' . self::PNG . '"/></div>');

        $image = $prepared->layoutRoot->children[0]->children[0];
        self::assertSame('img', $image->source->node->tagName);
        self::assertSame(32.0, $image->box->content->width);
        self::assertSame(16.0, $image->box->content->height);
    }

    public function testBlockImageKeepsAspectRatioWhenOnlyOneAxisIsSpecified(): void
    {
        $prepared = $this->render('<div style="width:400px"><img style="display:block;width:64px" src="' . self::PNG . '"/></div>');

        $image = $prepared->layoutRoot->children[0]->children[0];
        self::assertSame(64.0, $image->box->content->width);
        self::assertSame(32.0, $image->box->content->height);
    }

    public function testBlockImageHonoursAutoMarginsLikeAnyFixedWidthBlock(): void
    {
        $prepared = $this->render('<div style="width:400px"><img style="display:block;margin:0 auto" src="' . self::PNG . '"/></div>');

        $image = $prepared->layoutRoot->children[0]->children[0];
        self::assertSame((400.0 - 32.0) / 2.0, $image->box->content->x);
    }

    public function testFollowingBlockStartsBelowTheImageInsteadOfOverlappingIt(): void
    {
        $prepared = $this->render(
            '<div style="width:400px"><img style="display:block" src="' . self::PNG . '"/><p style="margin:0">abaixo</p></div>',
        );

        [$image, $paragraph] = $prepared->layoutRoot->children[0]->children;
        self::assertSame('img', $image->source->node->tagName);
        self::assertSame('p', $paragraph->source->node->tagName);
        self::assertGreaterThanOrEqual($image->box->borderBox()->bottom(), $paragraph->box->content->y);
    }

    public function testBlockImageNestedInAnInlineBlockIsStillPainted(): void
    {
        // The shape every court letterhead in the motivating corpus uses: an inline-block
        // wrapper whose only child is a block image.
        $images = $this->images($this->render(
            '<header><div style="display:inline-block;width:100%"><img style="display:block" src="' . self::PNG . '"/></div></header>',
        ));

        self::assertCount(1, $images);
    }

    public function testBlockImageInsideAnInlineBlockSitsAtItsTopWithNoLeadingEmptyLine(): void
    {
        $prepared = $this->render(
            '<header><div style="display:inline-block;width:100%"><img style="display:block" src="' . self::PNG . '"/></div></header>',
        );

        $line = $prepared->layoutRoot->children[0]->lineBoxes[0];
        $wrapper = $line->atomicBoxes[0];
        self::assertCount(1, $wrapper->contentLines);
        self::assertSame($wrapper->contentLines[0]->y, $line->y);
    }

    public function testTextAlignCenterDoesNotCentreABlockLevelImage(): void
    {
        $wrapper = '<div style="display:inline-block;width:100%%;%s"><img style="display:block" src="' . self::PNG . '"/></div>';
        $centered = $this->images($this->render('<header>' . sprintf($wrapper, 'text-align:center') . '</header>'));
        $plain = $this->images($this->render('<header>' . sprintf($wrapper, '') . '</header>'));

        self::assertCount(1, $centered);
        self::assertCount(1, $plain);
        self::assertSame($plain[0]->x, $centered[0]->x);
    }

    public function testInlineContentIsLaidOutInFlowOrderBetweenBlockSiblings(): void
    {
        $prepared = $this->render(
            '<div style="width:400px;margin:0"><p style="margin:0">primeiro</p>solto<p style="margin:0">terceiro</p></div>',
        );

        $container = $prepared->layoutRoot->children[0];
        [$first, $third] = $container->children;
        self::assertCount(1, $container->lineBoxes);

        $loose = $container->lineBoxes[0];
        self::assertSame('solto', $loose->text);
        self::assertGreaterThanOrEqual($first->box->borderBox()->bottom(), $loose->y);
        self::assertGreaterThanOrEqual($loose->y + $loose->height, $third->box->content->y);
    }

    public function testInlineContentBeforeABlockKeepsItsPlaceAtTheTop(): void
    {
        $prepared = $this->render('<div style="width:400px;margin:0">antes<p style="margin:0">bloco</p></div>');

        $container = $prepared->layoutRoot->children[0];
        self::assertSame('antes', $container->lineBoxes[0]->text);
        self::assertSame(0.0, $container->lineBoxes[0]->y);
        self::assertGreaterThanOrEqual(
            $container->lineBoxes[0]->height,
            $container->children[0]->box->content->y,
        );
    }

    public function testWhitespaceBetweenBlocksDoesNotCreateEmptyLines(): void
    {
        $prepared = $this->render("<div style=\"width:400px\">\n  <p>um</p>\n  <p>dois</p>\n</div>");

        self::assertSame([], $prepared->layoutRoot->children[0]->lineBoxes);
    }
}
