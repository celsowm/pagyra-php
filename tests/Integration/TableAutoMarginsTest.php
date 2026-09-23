<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/** A table with a width narrower than its container is placed by its auto side margins. */
final class TableAutoMarginsTest extends TestCase
{
    private function firstTextX(string $html): float
    {
        $prepared = Pagyra::prepareHtmlRender(['html' => $html, 'pagedBodyMargin' => 'zero', 'margins' => 0.0, 'pageWidth' => 400.0, 'viewportWidth' => 400.0]);
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand) return $command->x;
        }
        self::fail('no text');
    }

    public function testCenteredByAutoMarginsAndByTheAlignAttribute(): void
    {
        self::assertEqualsWithDelta(100.0, $this->firstTextX('<table style="width:200px;margin:0 auto"><tr><td style="padding:0">x</td></tr></table>'), 0.01);
        self::assertEqualsWithDelta(100.0, $this->firstTextX('<table align="center" width="200"><tr><td style="padding:0">x</td></tr></table>'), 0.01);
    }

    public function testPushedRightByAnAutoLeftMargin(): void
    {
        self::assertEqualsWithDelta(300.0, $this->firstTextX('<table style="width:100px;margin-left:auto"><tr><td style="padding:0">x</td></tr></table>'), 0.01);
    }

    public function testFullWidthTableIsUnaffected(): void
    {
        self::assertEqualsWithDelta(0.0, $this->firstTextX('<table style="margin:0 auto"><tr><td style="padding:0">x</td></tr></table>'), 0.01);
    }
}
