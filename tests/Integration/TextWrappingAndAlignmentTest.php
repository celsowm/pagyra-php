<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class TextWrappingAndAlignmentTest extends TestCase
{
    public function testPrePreservesNewlinesAndSpaces(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => "<p>A  B\nC</p>",
            'css' => 'p { margin:0; white-space:pre; font-size:16px; line-height:20px; }',
            'viewportWidth' => 300,
            'viewportHeight' => 300,
        ]);

        $lines = $prepared->layoutRoot->children[0]->lineBoxes;
        self::assertCount(2, $lines);
        self::assertSame('A  B', $lines[0]->text);
        self::assertSame('C', $lines[1]->text);
        self::assertSame(40.0, $prepared->layoutRoot->children[0]->box->content->height);
    }

    public function testPreLinePreservesNewlineButCollapsesSpaces(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => "<p>A   B\nC</p>",
            'css' => 'p { margin:0; white-space:pre-line; font-size:16px; line-height:20px; }',
            'viewportWidth' => 300,
            'viewportHeight' => 300,
        ]);

        $lines = $prepared->layoutRoot->children[0]->lineBoxes;
        self::assertCount(2, $lines);
        self::assertSame('A B', $lines[0]->text);
        self::assertSame('C', $lines[1]->text);
    }

    public function testNowrapDoesNotSoftWrap(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p>alpha beta gamma</p>',
            'css' => 'p { margin:0; width:40px; white-space:nowrap; font-size:16px; }',
            'viewportWidth' => 200,
            'viewportHeight' => 300,
        ]);

        self::assertCount(1, $prepared->layoutRoot->children[0]->lineBoxes);
        self::assertSame('alpha beta gamma', $prepared->layoutRoot->children[0]->lineBoxes[0]->text);
    }

    public function testOverflowWrapAnywhereSplitsOversizedWord(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p>abcdefghij</p>',
            'css' => 'p { margin:0; width:30px; overflow-wrap:anywhere; font-size:16px; line-height:20px; }',
            'viewportWidth' => 200,
            'viewportHeight' => 300,
        ]);

        $lines = $prepared->layoutRoot->children[0]->lineBoxes;
        self::assertGreaterThan(1, count($lines));
        self::assertSame('abcdefghij', implode('', array_map(static fn($line) => $line->text, $lines)));
    }

    public function testCenterAndRightAlignmentOffsetLineAndRuns(): void
    {
        $center = Pagyra::prepareHtmlRender([
            'html' => '<p>hello</p>',
            'css' => 'p { margin:0; width:200px; text-align:center; font-size:16px; }',
            'viewportWidth' => 300,
            'viewportHeight' => 300,
        ])->layoutRoot->children[0]->lineBoxes[0];

        $right = Pagyra::prepareHtmlRender([
            'html' => '<p>hello</p>',
            'css' => 'p { margin:0; width:200px; text-align:right; font-size:16px; }',
            'viewportWidth' => 300,
            'viewportHeight' => 300,
        ])->layoutRoot->children[0]->lineBoxes[0];

        self::assertGreaterThan(0.0, $center->x);
        self::assertGreaterThan($center->x, $right->x);
        self::assertSame($center->x, $center->runs[0]->x);
        self::assertSame($right->x, $right->runs[0]->x);
    }

    public function testJustifyExpandsNonFinalLineToAvailableWidth(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p>one two three four five six</p>',
            'css' => 'p { margin:0; width:90px; text-align:justify; font-size:16px; line-height:20px; }',
            'viewportWidth' => 300,
            'viewportHeight' => 300,
        ]);

        $lines = $prepared->layoutRoot->children[0]->lineBoxes;
        self::assertGreaterThan(1, count($lines));
        self::assertEqualsWithDelta(90.0, $lines[0]->width, 0.0001);
        self::assertLessThanOrEqual(90.0, $lines[array_key_last($lines)]->width);
    }
}
