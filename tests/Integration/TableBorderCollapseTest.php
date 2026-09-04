<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class TableBorderCollapseTest extends TestCase
{
    public function testCollapseKeepsTheThickerAdjacentBorderAndZerosTheThinnerSide(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table style="border-collapse:collapse">'
                . '<tr><td style="padding:0;border:1px solid #000">a</td>'
                . '<td style="padding:0;border:3px solid #f00">b</td></tr>'
                . '</table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $row = $prepared->layoutRoot->children[0]->children[0];
        [$a, $b] = $row->children;

        self::assertSame(0.0, $a->box->border->right);
        self::assertGreaterThan(0.0, $b->box->border->left);
    }

    public function testCollapseTieKeepsTheEarlierCellsSideAndZerosTheOther(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table style="border-collapse:collapse">'
                . '<tr><td style="padding:0;border:1px solid #000">a</td>'
                . '<td style="padding:0;border:1px solid #000">b</td></tr>'
                . '</table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $row = $prepared->layoutRoot->children[0]->children[0];
        [$a, $b] = $row->children;

        self::assertGreaterThan(0.0, $a->box->border->right);
        self::assertSame(0.0, $b->box->border->left);
    }

    public function testCollapseResolvesSharedBordersBetweenRowsToo(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table style="border-collapse:collapse">'
                . '<tr><td style="padding:0;border:1px solid #000">a</td></tr>'
                . '<tr><td style="padding:0;border:4px solid #000">b</td></tr>'
                . '</table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $table = $prepared->layoutRoot->children[0];
        $a = $table->children[0]->children[0];
        $b = $table->children[1]->children[0];

        self::assertSame(0.0, $a->box->border->bottom);
        self::assertGreaterThan(0.0, $b->box->border->top);
    }

    public function testSeparateIsTheDefaultAndDoesNotZeroEitherSide(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table>'
                . '<tr><td style="padding:0;border:1px solid #000">a</td>'
                . '<td style="padding:0;border:1px solid #000">b</td></tr>'
                . '</table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $row = $prepared->layoutRoot->children[0]->children[0];
        [$a, $b] = $row->children;

        self::assertGreaterThan(0.0, $a->box->border->right);
        self::assertGreaterThan(0.0, $b->box->border->left);
    }
}
