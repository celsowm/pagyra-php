<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

final class TableHeaderFooterPaginationTest extends TestCase
{
    private function prepared(string $table, string $after = ''): \Pagyra\PreparedHtmlRender
    {
        return Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>'
                . '@page{size:200px 90px;margin:0}'
                . 'table{width:200px;border-collapse:collapse;margin:0}'
                . 'td,th{padding:0;margin:0;font-size:10px;line-height:20px}'
                . '</style>'
                . $table
                . $after,
            'pageWidth' => 200.0,
            'pageHeight' => 90.0,
            'viewportWidth' => 200.0,
            'viewportHeight' => 90.0,
            'margins' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        ]);
    }

    /** @return list<list<string>> */
    private function textsByPage(\Pagyra\PreparedHtmlRender $prepared): array
    {
        $pages = [];
        foreach ($prepared->displayList->pages as $page) {
            $texts = [];
            foreach ($page->commands as $command) {
                if (!$command instanceof TextPaintCommand) continue;
                $text = trim($command->text);
                if ($text !== '') $texts[] = $text;
            }
            $pages[] = $texts;
        }
        return $pages;
    }

    public function testHeaderAndFooterRepeatOnEveryTablePage(): void
    {
        $rows = '';
        for ($i = 1; $i <= 6; $i++) {
            $rows .= '<tr><td style="height:30px">ROW' . $i . '</td></tr>';
        }
        $prepared = $this->prepared(
            '<table>'
            . '<thead><tr><th style="height:20px">HEAD</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '<tfoot><tr><td style="height:20px">FOOT</td></tr></tfoot>'
            . '</table>',
        );

        $pages = $this->textsByPage($prepared);
        $tablePages = array_values(array_filter(
            $pages,
            static fn(array $texts): bool => (bool) array_filter($texts, static fn(string $text): bool => str_starts_with($text, 'ROW')),
        ));

        self::assertGreaterThan(1, count($tablePages));
        foreach ($tablePages as $texts) {
            self::assertContains('HEAD', $texts);
            self::assertContains('FOOT', $texts);

            $head = array_search('HEAD', $texts, true);
            $foot = array_search('FOOT', $texts, true);
            $bodyPositions = [];
            foreach ($texts as $index => $text) {
                if (str_starts_with($text, 'ROW')) $bodyPositions[] = $index;
            }
            self::assertNotEmpty($bodyPositions);
            self::assertLessThan(min($bodyPositions), $head);
            self::assertGreaterThan(max($bodyPositions), $foot);
        }

        $all = array_merge(...$pages);
        for ($i = 1; $i <= 6; $i++) {
            self::assertSame(1, count(array_keys($all, 'ROW' . $i, true)), 'body rows must not repeat');
        }
        self::assertSame(count($tablePages), count(array_keys($all, 'HEAD', true)));
        self::assertSame(count($tablePages), count(array_keys($all, 'FOOT', true)));
    }

    public function testRepeatedRowsConsumeSpaceAndPushFollowingContentAfterTheTable(): void
    {
        $rows = '';
        for ($i = 1; $i <= 5; $i++) {
            $rows .= '<tr><td style="height:30px">ROW' . $i . '</td></tr>';
        }
        $prepared = $this->prepared(
            '<table>'
            . '<thead><tr><th style="height:20px">HEAD</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '<tfoot><tr><td style="height:20px">FOOT</td></tr></tfoot>'
            . '</table>',
            '<p style="margin:0;font-size:10px;line-height:20px">AFTER</p>',
        );

        $lastFooterPage = -1;
        $afterPage = -1;
        $afterY = null;
        $lastFooterY = null;

        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if (!$command instanceof TextPaintCommand) continue;
                $text = trim($command->text);
                if ($text === 'FOOT') {
                    $lastFooterPage = $page->pageIndex;
                    $lastFooterY = $command->y;
                } elseif ($text === 'AFTER') {
                    $afterPage = $page->pageIndex;
                    $afterY = $command->y;
                }
            }
        }

        self::assertGreaterThanOrEqual(0, $lastFooterPage);
        self::assertGreaterThanOrEqual($lastFooterPage, $afterPage);
        if ($afterPage === $lastFooterPage) {
            self::assertNotNull($afterY);
            self::assertNotNull($lastFooterY);
            self::assertGreaterThan($lastFooterY, $afterY);
        }
    }

    public function testRowspanTableFallsBackToNonRepeatingPaginationUntilSpanningRowsArePlannable(): void
    {
        $prepared = $this->prepared(
            '<table>'
            . '<thead><tr><th style="height:20px">HEAD</th><th>H2</th></tr></thead>'
            . '<tbody>'
            . '<tr><td rowspan="2" style="height:100px">SPAN</td><td>ROW1</td></tr>'
            . '<tr><td>ROW2</td></tr>'
            . '<tr><td>ROW3</td><td>X</td></tr>'
            . '</tbody>'
            . '</table>',
        );

        $all = array_merge(...$this->textsByPage($prepared));
        self::assertSame(1, count(array_keys($all, 'HEAD', true)));
    }
}
