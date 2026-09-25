<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\OpacityGroupPaintCommand;
use PHPUnit\Framework\TestCase;

final class OpacityGroupCompositingTest extends TestCase
{
    public function testGroupOpacityIsFactoredOutOfOpaqueChildPrimitives(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<div style="opacity:.5;width:100px;height:60px">'
                . '<div style="position:absolute;width:60px;height:40px;background:red"></div>'
                . '<div style="position:absolute;left:20px;width:60px;height:40px;background:blue"></div>'
                . '</div>',
            'viewportWidth' => 200,
            'viewportHeight' => 120,
        ]);

        $opens = [];
        $colored = [];
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof OpacityGroupPaintCommand && $command->opens()) {
                $opens[] = $command;
            }
            if ($command instanceof BoxPaintCommand && $command->backgroundColor !== null) {
                $colored[] = $command->backgroundColor;
            }
        }

        self::assertCount(1, $opens);
        self::assertEqualsWithDelta(0.5, $opens[0]->normalizedOpacity(), 1e-9);
        self::assertGreaterThanOrEqual(2, count($colored));
        foreach ($colored as $color) {
            self::assertEqualsWithDelta(1.0, $color->a, 1e-9);
        }
    }

    public function testPdfUsesIsolatedTransparencyFormInsteadOfOnlyPerPrimitiveAlpha(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<div style="opacity:.5;width:100px;height:60px">'
                . '<div style="position:absolute;width:60px;height:40px;background:red"></div>'
                . '<div style="position:absolute;left:20px;width:60px;height:40px;background:blue"></div>'
                . '</div>',
            'viewportWidth' => 200,
            'viewportHeight' => 120,
        ]);

        self::assertStringContainsString('/Subtype /Form', $pdf);
        self::assertStringContainsString('/Group << /S /Transparency /I true /K false /CS /DeviceRGB >>', $pdf);
        self::assertStringContainsString('/ca 0.5 /CA 0.5', $pdf);
        self::assertMatchesRegularExpression('/\/GS\d+ gs\n\/Fm\d+ Do/', $pdf);
    }

    public function testNestedOpacityGroupsRemainNestedAndPrimitiveAlphaIsNotDoubleApplied(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<div style="opacity:.5">'
                . '<div style="opacity:.4;width:40px;height:30px;background:red"></div>'
                . '</div>',
        ]);

        $opens = [];
        $red = null;
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof OpacityGroupPaintCommand && $command->opens()) {
                $opens[] = $command->normalizedOpacity();
            }
            if ($command instanceof BoxPaintCommand && $command->backgroundColor !== null) {
                $red = $command->backgroundColor;
            }
        }

        self::assertSame([0.5, 0.4], $opens);
        self::assertNotNull($red);
        self::assertEqualsWithDelta(1.0, $red->a, 1e-9);

        $pdf = Pagyra::renderHtmlToPdf([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<div style="opacity:.5"><div style="opacity:.4;width:40px;height:30px;background:red"></div></div>',
        ]);
        self::assertGreaterThanOrEqual(2, substr_count($pdf, '/Subtype /Form'));
        self::assertStringContainsString('/ca 0.5 /CA 0.5', $pdf);
        self::assertStringContainsString('/ca 0.4 /CA 0.4', $pdf);
    }

    public function testInlineNonAtomicOpacityKeepsPerRunFallback(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<p style="margin:0">A<span style="opacity:.5;color:red">INLINE</span>Z</p>',
        ]);

        $groups = array_filter(
            $prepared->displayList->pages[0]->commands,
            static fn(object $command): bool => $command instanceof OpacityGroupPaintCommand,
        );

        // A normal inline span has no independent LayoutNode/AtomicInlineBox in the current paint
        // tree, so it deliberately retains the existing per-run alpha fallback for now.
        self::assertSame([], array_values($groups));
    }
}
