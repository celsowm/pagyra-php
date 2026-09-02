<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class StyledInlineRunsTest extends TestCase
{
    public function testInlineStylesSurviveIntoLineRuns(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p>Todo <strong>poder</strong><em> povo</em></p>',
            'css' => 'p { width: 500px; margin: 0; font-size: 20px; line-height: 1.2; }',
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        $line = $prepared->layoutRoot->children[0]->lineBoxes[0];
        self::assertSame('Todo poder povo', $line->text);
        self::assertCount(3, $line->runs);

        self::assertSame('Todo ', $line->runs[0]->text);
        self::assertSame('poder', $line->runs[1]->text);
        self::assertSame(' povo', $line->runs[2]->text);
        self::assertSame('bold', $line->runs[1]->style->get('font-weight'));
        self::assertSame('italic', $line->runs[2]->style->get('font-style'));
        self::assertSame(20.0, $line->runs[0]->fontSize);
        self::assertEqualsWithDelta(
            $line->width,
            array_sum(array_map(static fn($run): float => $run->width, $line->runs)),
            1e-9,
        );
    }

    public function testRunSpecificFontSizeControlsLineHeightAndBaseline(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p>small <span style="font-size: 30px">BIG</span> tail</p>',
            'css' => 'p { width: 500px; margin: 0; font-size: 10px; line-height: 1.2; }',
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        $line = $prepared->layoutRoot->children[0]->lineBoxes[0];
        self::assertSame(36.0, $line->height);
        self::assertSame(30.0, $line->runs[1]->fontSize);
        self::assertSame($line->baseline, $line->runs[0]->baseline);
        self::assertSame($line->baseline, $line->runs[1]->baseline);
        // Baseline-aligned, so the 30px run starts higher on the page than the 10px ones: its
        // own box is taller and its top sits further above the shared baseline.
        self::assertLessThan($line->runs[0]->y, $line->runs[1]->y);
    }

    public function testWrappingKeepsStyledRunsOnTheirLines(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p>alpha <strong>beta gamma</strong> delta</p>',
            'css' => 'p { width: 75px; margin: 0; font-size: 16px; line-height: 1.2; }',
            'viewportWidth' => 200,
            'viewportHeight' => 800,
        ]);

        $lines = $prepared->layoutRoot->children[0]->lineBoxes;
        self::assertGreaterThan(1, count($lines));

        $boldRuns = [];
        foreach ($lines as $line) {
            foreach ($line->runs as $run) {
                if ($run->style->get('font-weight') === 'bold') {
                    $boldRuns[] = trim($run->text);
                }
            }
        }
        self::assertNotEmpty($boldRuns);
        self::assertStringContainsString('beta', implode(' ', $boldRuns));
        self::assertStringContainsString('gamma', implode(' ', $boldRuns));
    }
}
