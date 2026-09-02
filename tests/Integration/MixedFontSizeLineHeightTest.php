<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class MixedFontSizeLineHeightTest extends TestCase
{
    private function firstLine(string $html, string $css)
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'css' => $css,
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        return $prepared->layoutRoot->children[0]->lineBoxes[0];
    }

    public function testLineIsAsTallAsItsTallestRunNotTaller(): void
    {
        // 30px run under line-height 1.2 needs 36px; the 10px strut needs 12px. The finished
        // line is the taller of the two, matching the reference and browsers.
        $line = $this->firstLine(
            '<p>small <span style="font-size:30px">BIG</span> tail</p>',
            'p { width: 500px; margin: 0; font-size: 10px; line-height: 1.2; }',
        );

        self::assertSame(36.0, $line->height);
    }

    public function testUniformLineKeepsTheBlockLineHeight(): void
    {
        $line = $this->firstLine(
            '<p>apenas texto</p>',
            'p { width: 500px; margin: 0; font-size: 10px; line-height: 1.2; }',
        );

        self::assertSame(12.0, $line->height);
    }

    public function testTallerRunStartsHigherOnTheSharedBaseline(): void
    {
        $line = $this->firstLine(
            '<p>small <span style="font-size:30px">BIG</span> tail</p>',
            'p { width: 500px; margin: 0; font-size: 10px; line-height: 1.2; }',
        );

        self::assertLessThan($line->runs[0]->y, $line->runs[1]->y);
        self::assertSame($line->baseline, $line->runs[0]->baseline);
        self::assertSame($line->baseline, $line->runs[1]->baseline);
    }

    public function testRunsStayInsideTheLineBox(): void
    {
        $line = $this->firstLine(
            '<p>small <span style="font-size:30px">BIG</span> tail</p>',
            'p { width: 500px; margin: 0; font-size: 10px; line-height: 1.2; }',
        );

        foreach ($line->runs as $run) {
            self::assertGreaterThanOrEqual($line->y, $run->y);
            self::assertLessThanOrEqual($line->y + $line->height, $run->y + $run->height);
        }
    }

    public function testARaisedRunStillExpandsTheLine(): void
    {
        // The floor must not swallow the expansion pass: a run pushed above the strut still
        // grows the line box.
        $plain = $this->firstLine(
            '<p>base normal</p>',
            'p { width: 500px; margin: 0; font-size: 20px; line-height: 1.2; }',
        );
        $raised = $this->firstLine(
            '<p>base <span style="vertical-align:20px">alto</span></p>',
            'p { width: 500px; margin: 0; font-size: 20px; line-height: 1.2; }',
        );

        self::assertGreaterThan($plain->height, $raised->height);
    }
}
