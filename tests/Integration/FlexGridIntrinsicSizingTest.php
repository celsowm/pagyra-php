<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\LayoutNode;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * Flex and grid containers nested as items of another flex row: their intrinsic width is their
 * items side by side, a shrinking item stops at its min-content width, and an item laid out at
 * exactly its max-content width keeps its text on one line. The invoice header of the Fagus
 * benchmark (a flex brand with a logo and a name, a two-column grid of labels and values, totals
 * rows of two spans) wrapped, spilled and clipped before.
 */
final class FlexGridIntrinsicSizingTest extends TestCase
{
    private function root(string $html, float $width = 400.0): LayoutNode
    {
        return Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero', 'margins' => 0.0, 'pageWidth' => $width, 'viewportWidth' => $width,
            'html' => $html,
        ])->layoutRoot;
    }

    /** @return list<LayoutNode> */
    private function lines(LayoutNode $node): array
    {
        $found = $node->lineBoxes;
        foreach ($node->children as $child) {
            array_push($found, ...$this->lines($child));
        }

        return $found;
    }

    public function testNestedFlexRowMeasuresItsItemsAndGapSideBySide(): void
    {
        $root = $this->root(
            '<div style="display:flex;justify-content:space-between">'
            . '<div style="display:flex;gap:10px"><div style="width:40px;height:40px"></div><div>Muster GmbH</div></div>'
            . '<div>badge</div></div>',
        );
        $brand = $root->children[0]->children[0];
        [$logo, $name] = $brand->children;

        self::assertEqualsWithDelta(40.0, $logo->box->borderBox()->width, 0.001);
        self::assertEqualsWithDelta(50.0, $name->box->borderBox()->x - $brand->box->borderBox()->x, 0.001);
        self::assertEqualsWithDelta(
            $brand->box->borderBox()->width,
            50.0 + $name->box->borderBox()->width,
            0.001,
        );
        self::assertCount(1, $this->lines($name), 'the name stays on one line');
    }

    public function testGridItemOfAFlexRowGetsItsColumnsAndGapAndStaysInside(): void
    {
        $root = $this->root(
            '<div style="display:flex;justify-content:space-between">'
            . '<div>Kunde AG</div>'
            . '<dl style="display:grid;grid-template-columns:auto auto;column-gap:20px;margin:0">'
            . '<dt>Rechnungsnummer</dt><dd style="margin:0">RE-2026-0042</dd><dt>Datum</dt><dd style="margin:0">01.11.2026</dd>'
            . '</dl></div>',
        );
        $grid = $root->children[0]->children[1];
        [$label, $value] = $grid->children;
        $gridBox = $grid->box->borderBox();

        self::assertEqualsWithDelta(20.0, $value->box->borderBox()->x - ($label->box->borderBox()->x + $label->box->borderBox()->width), 0.001);
        self::assertLessThanOrEqual($gridBox->x + $gridBox->width + 0.001, $value->box->borderBox()->x + $value->box->borderBox()->width);
        self::assertLessThanOrEqual(400.001, $gridBox->x + $gridBox->width);
        foreach ($grid->children as $cell) {
            self::assertCount(1, $this->lines($cell));
        }
    }

    public function testShrinkingStopsAtTheMinContentWidthUnlessMinWidthIsZero(): void
    {
        $word = '<div class="w">Unzerbrechlich</div>';
        $wide = '<div style="width:380px;height:10px"></div>';
        $floor = $this->root('<div style="display:flex">' . $word . $wide . '</div>', 200.0)->children[0];
        $free = $this->root('<style>.w{min-width:0}</style><div style="display:flex">' . $word . $wide . '</div>', 200.0)->children[0];

        $wordWidth = $floor->children[0]->box->borderBox()->width;
        self::assertGreaterThan(60.0, $wordWidth, 'the word keeps its own width');
        self::assertEqualsWithDelta(200.0 - $wordWidth, $floor->children[1]->box->borderBox()->width, 0.001, 'the other item takes the overflow');
        self::assertLessThan($wordWidth, $free->children[0]->box->borderBox()->width);
    }

    public function testItemsAtTheirMaxContentWidthDoNotWrapTheirLastWord(): void
    {
        $rows = '';
        foreach (['Umsatzsteuer 19 %', '108.30 €', '678.30 €', 'Nettobetrag', '1,083.00 €', 'Seite 1 von 2', '2 Std.'] as $text) {
            foreach (['9.5pt', '11pt', '12.5px', '8pt'] as $size) {
                $rows .= '<div style="display:flex;justify-content:space-between;width:283px;font-size:' . $size . '"><span>' . $text . '</span><span>' . $text . '</span></div>';
            }
        }
        $root = $this->root($rows, 600.0);

        foreach ($root->children as $row) {
            foreach ($row->children as $span) {
                self::assertCount(1, $this->lines($span), trim($this->lines($span)[0]->text ?? ''));
            }
        }
    }

    public function testBackgroundShorthandWithAVariableAndAMillimetreRadiusPaint(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>:root{--accent:#2741d9}.logo{width:12mm;height:12mm;border-radius:3mm;background:var(--accent)}'
                . '.img{width:10px;height:10px;background:var(--none, #ff0000) no-repeat}</style>'
                . '<div class="logo"></div><div class="img"></div>',
        ]);
        $boxes = [];
        foreach ($prepared->displayList->pages[0]->commands ?? $prepared->displayList->pages[0] as $command) {
            $data = json_decode((string) json_encode($command), true);
            if (($data['type'] ?? null) === 'box' && ($data['backgroundColor'] ?? null) !== null) $boxes[] = $data;
        }

        self::assertCount(2, $boxes);
        self::assertSame(['r' => 39, 'g' => 65, 'b' => 217, 'a' => 1], $boxes[0]['backgroundColor']);
        self::assertEqualsWithDelta(3 * 96 / 25.4, $boxes[0]['borderRadius']['topLeft']['x'], 0.001);
        self::assertSame(['r' => 255, 'g' => 0, 'b' => 0, 'a' => 1], $boxes[1]['backgroundColor'], 'the fallback of var() is used');
    }
}
