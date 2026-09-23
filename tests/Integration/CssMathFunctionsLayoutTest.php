<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use PHPUnit\Framework\TestCase;

/** `min()`, `max()`, `clamp()` and `calc()` with `*`/`/` size real boxes. */
final class CssMathFunctionsLayoutTest extends TestCase
{
    public function testWidthsMatchTheBrowserValues(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'pageWidth' => 400.0,
            'viewportWidth' => 400.0,
            'margins' => 0.0,
            'html' => '<style>div{height:4px;background:#ccc}</style>'
                . '<div style="width:calc(100% - 100px)"></div><div style="width:calc(100% / 4)"></div>'
                . '<div style="width:min(150px, 50%)"></div><div style="width:clamp(50px, 30%, 200px)"></div>'
                . '<div style="width:max(120px, 10%)"></div>',
        ]);

        $widths = [];
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof BoxPaintCommand && $command->backgroundColor !== null) {
                $widths[] = $command->width;
            }
        }

        self::assertEqualsWithDelta([300.0, 100.0, 150.0, 120.0, 120.0], $widths, 1e-6);
    }
}
