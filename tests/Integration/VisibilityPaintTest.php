<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\LayoutNode;
use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

final class VisibilityPaintTest extends TestCase
{
    public function testHiddenBoxKeepsLayoutButProducesNoPaintCommands(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page{size:160px 100px;margin:0}div,p{margin:0}</style>'
                . '<div id="hidden" style="height:30px;background:red;visibility:hidden">SECRET</div>'
                . '<p id="visible" style="height:20px;background:green">VISIBLE</p>',
            'pageWidth' => 160.0,
            'pageHeight' => 100.0,
            'viewportWidth' => 160.0,
            'viewportHeight' => 100.0,
            'margins' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        ]);

        self::assertSame(0.0, $prepared->layoutRoot->children[0]->box->borderBox()->y);
        self::assertSame(30.0, $prepared->layoutRoot->children[1]->box->borderBox()->y);

        $commands = $prepared->displayList?->pages[0]->commands ?? [];
        foreach ($commands as $command) {
            if ($command instanceof TextPaintCommand) {
                self::assertStringNotContainsString('SECRET', $command->text);
            }
            if ($command instanceof BoxPaintCommand && $command->node instanceof LayoutNode) {
                self::assertNotSame('hidden', $command->node->source->node->attribute('id'));
            }
        }
        $visibleText = implode('', array_map(
            static fn (TextPaintCommand $command): string => $command->text,
            array_values(array_filter($commands, static fn (object $command): bool => $command instanceof TextPaintCommand)),
        ));
        self::assertStringContainsString('VISIBLE', $visibleText);
    }

    public function testVisibleDescendantCanOverrideInheritedHiddenVisibility(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page{size:180px 100px;margin:0}div{margin:0}</style>'
                . '<div style="visibility:hidden"><span style="visibility:visible">CHILD</span><span>HIDDEN</span></div>',
            'pageWidth' => 180.0,
            'pageHeight' => 100.0,
            'viewportWidth' => 180.0,
            'viewportHeight' => 100.0,
            'margins' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        ]);

        $texts = array_values(array_filter(
            $prepared->displayList?->pages[0]->commands ?? [],
            static fn (object $command): bool => $command instanceof TextPaintCommand,
        ));
        $painted = implode('', array_map(static fn (TextPaintCommand $command): string => $command->text, $texts));
        self::assertStringContainsString('CHILD', $painted);
        self::assertStringNotContainsString('HIDDEN', $painted);
    }
}
