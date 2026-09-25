<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\ClipPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

final class ZIndexPaintOrderTest extends TestCase
{
    /** @return list<string> */
    private function paintedTexts(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => $html,
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $texts = [];
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if (!$command instanceof TextPaintCommand) continue;
            $text = trim($command->text);
            if ($text !== '') $texts[] = $text;
        }

        return $texts;
    }

    public function testNegativeNormalAndPositivePositionedSiblingsUseStackingPhases(): void
    {
        $texts = $this->paintedTexts(
            '<div style="position:relative;width:200px;height:80px">'
            . '<div style="position:absolute;z-index:2">POSITIVE</div>'
            . '<div>NORMAL</div>'
            . '<div style="position:absolute;z-index:-1">NEGATIVE</div>'
            . '</div>',
        );

        self::assertSame(['NEGATIVE', 'NORMAL', 'POSITIVE'], $texts);
    }

    public function testNumericZIndexIsIgnoredOnStaticBoxes(): void
    {
        $texts = $this->paintedTexts(
            '<div>'
            . '<div style="z-index:99">STATIC-FIRST</div>'
            . '<div style="position:relative;z-index:1">POSITIONED</div>'
            . '<div>STATIC-LAST</div>'
            . '</div>',
        );

        self::assertSame(['STATIC-FIRST', 'STATIC-LAST', 'POSITIONED'], $texts);
    }

    public function testEqualZIndexKeepsDocumentOrder(): void
    {
        $texts = $this->paintedTexts(
            '<div>'
            . '<div style="position:relative;z-index:3">A</div>'
            . '<div style="position:relative;z-index:3">B</div>'
            . '<div style="position:relative;z-index:3">C</div>'
            . '</div>',
        );

        self::assertSame(['A', 'B', 'C'], $texts);
    }

    public function testPositionedGrandchildCompetesInNearestAncestorStackingContext(): void
    {
        $texts = $this->paintedTexts(
            '<div>'
            . '<div>NORMAL-ANCESTOR'
            . '<div style="position:absolute;z-index:100">GRANDCHILD-100</div>'
            . '</div>'
            . '<div style="position:relative;z-index:2">SIBLING-2</div>'
            . '</div>',
        );

        self::assertSame(['NORMAL-ANCESTOR', 'SIBLING-2', 'GRANDCHILD-100'], $texts);
    }

    public function testNegativeGrandchildEscapesNonContextAncestorIntoNegativePhase(): void
    {
        $texts = $this->paintedTexts(
            '<div>'
            . '<div>NORMAL-A'
            . '<div style="position:absolute;z-index:-5">NEGATIVE-GRANDCHILD</div>'
            . '</div>'
            . '<div>NORMAL-B</div>'
            . '</div>',
        );

        self::assertSame(['NEGATIVE-GRANDCHILD', 'NORMAL-A', 'NORMAL-B'], $texts);
    }

    public function testPromotedDescendantReopensOverflowClipOfCrossedAncestor(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<div>'
                . '<div style="height:20px;overflow:hidden">CLIPPED-ANCESTOR'
                . '<div style="position:absolute;z-index:100">PROMOTED</div>'
                . '</div>'
                . '<div style="position:relative;z-index:2">SIBLING</div>'
                . '</div>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $commands = $prepared->displayList->pages[0]->commands;
        $promotedIndex = null;
        foreach ($commands as $index => $command) {
            if ($command instanceof TextPaintCommand && trim($command->text) === 'PROMOTED') {
                $promotedIndex = $index;
                break;
            }
        }

        self::assertNotNull($promotedIndex);

        $openBefore = false;
        $depth = 0;
        foreach ($commands as $index => $command) {
            if ($index >= $promotedIndex) break;
            if (!$command instanceof ClipPaintCommand) continue;
            if ($command->opens()) $depth++;
            else $depth = max(0, $depth - 1);
        }
        $openBefore = $depth > 0;

        self::assertTrue($openBefore, 'promoted descendant must still paint inside its overflow ancestor clip');
    }

    public function testDescendantOfTopLevelNormalEntryCompetesWithAnotherTopLevelContext(): void
    {
        $texts = $this->paintedTexts(
            '<div>TOP-NORMAL'
            . '<div style="position:absolute;z-index:100">TOP-GRANDCHILD-100</div>'
            . '</div>'
            . '<div style="position:relative;z-index:2">TOP-SIBLING-2</div>',
        );

        self::assertSame(['TOP-NORMAL', 'TOP-SIBLING-2', 'TOP-GRANDCHILD-100'], $texts);
    }

    public function testNestedSiblingScopesAreResolvedRecursively(): void
    {
        $texts = $this->paintedTexts(
            '<div>'
            . '<div style="position:relative;z-index:1">'
            . '<div style="position:absolute;z-index:5">INNER-HIGH</div>'
            . '<div style="position:absolute;z-index:-2">INNER-LOW</div>'
            . '</div>'
            . '<div style="position:relative;z-index:2">OUTER-HIGH</div>'
            . '</div>',
        );

        self::assertSame(['INNER-LOW', 'INNER-HIGH', 'OUTER-HIGH'], $texts);
    }
}
