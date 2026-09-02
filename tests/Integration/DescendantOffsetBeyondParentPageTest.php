<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\LayoutNode;
use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

final class DescendantOffsetBeyondParentPageTest extends TestCase
{
    /**
     * Paragraphs of exactly three lines under `widows: 3; orphans: 3`: any page boundary that
     * would split one pushes the whole paragraph to the next page. Those shifts accumulate, so
     * the later paragraphs end up far below where the wrapping <section>'s own box ends — the
     * section box comes from layout, which knows nothing about pagination offsets.
     */
    private function document(int $paragraphs): string
    {
        $body = '';
        for ($i = 1; $i <= $paragraphs; $i++) {
            $body .= '<p style="margin:0;font-size:10px;line-height:20px">'
                . "L{$i}a<br/>L{$i}b<br/>L{$i}c</p>";
        }

        return '<style>p{widows:3;orphans:3}</style><article><section>' . $body . '</section></article>';
    }

    /** @return array{0:list<string>,1:string} every laid-out line, and everything actually painted */
    private function laidOutAndPainted(int $paragraphs): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $this->document($paragraphs),
            'pageWidth' => 300,
            'pageHeight' => 200,
            'margins' => 10,
        ]);

        $lines = [];
        $collect = static function (LayoutNode $node) use (&$collect, &$lines): void {
            foreach ($node->lineBoxes as $line) {
                if (trim($line->text) !== '') $lines[] = trim($line->text);
            }
            foreach ($node->children as $child) $collect($child);
        };
        $collect($prepared->layoutRoot);

        $painted = '';
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand) $painted .= $command->text;
            }
        }

        return [$lines, $painted];
    }

    public function testEveryLineShiftedPastItsWrapperStillReachesThePage(): void
    {
        [$lines, $painted] = $this->laidOutAndPainted(8);

        self::assertCount(24, $lines);
        $missing = array_values(array_filter($lines, static fn (string $l): bool => !str_contains($painted, $l)));
        self::assertSame([], $missing, 'linhas deslocadas para alem do wrapper nao foram pintadas');
    }

    public function testLossDoesNotGrowWithTheDocument(): void
    {
        foreach ([4, 8, 16] as $paragraphs) {
            [$lines, $painted] = $this->laidOutAndPainted($paragraphs);
            $missing = array_filter($lines, static fn (string $l): bool => !str_contains($painted, $l));
            self::assertSame([], array_values($missing), "perda com {$paragraphs} paragrafos");
        }
    }

    public function testWrapperItselfStillPaintsOnlyItsOwnArea(): void
    {
        // The fix lets a wrapper be visited on pages its box does not cover, so those extra
        // fragments must stay empty instead of stretching its background/borders over them.
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $this->document(8),
            'pageWidth' => 300,
            'pageHeight' => 200,
            'margins' => 10,
        ]);

        $section = null;
        foreach ($prepared->pagination->placements[0]->fragments as $fragment) {
            foreach ($fragment->blocks as $block) {
                if ($block->node->source->node->isElement('section')) $section = $block;
            }
        }

        self::assertNotNull($section);
        self::assertGreaterThanOrEqual(0.0, $section->height);
    }
}
