<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class DisplayListPaintTest extends TestCase
{
    public function testPreparedRenderBuildsPhysicalBoxAndTextCommands(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page { size:200px 100px; margin:10px; }</style>'
                . '<div style="margin:0;background-color:red">'
                . '<p style="margin:0;color:#123456;font-family:Fixture;font-weight:700;font-style:italic;font-size:10px;line-height:20px">Hi</p>'
                . '</div>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertNotNull($prepared->displayList);
        self::assertCount(1, $prepared->displayList->pages);
        $page = $prepared->displayList->pages[0];
        self::assertSame(200.0, $page->width);
        self::assertSame(100.0, $page->height);
        self::assertGreaterThanOrEqual(3, count($page->commands));

        $outerBox = $page->commands[0];
        self::assertInstanceOf(BoxPaintCommand::class, $outerBox);
        self::assertSame(10.0, $outerBox->x);
        self::assertSame(10.0, $outerBox->y);
        self::assertNotNull($outerBox->backgroundColor);
        self::assertSame(255.0, $outerBox->backgroundColor->r);
        self::assertSame(0.0, $outerBox->backgroundColor->g);
        self::assertSame(0.0, $outerBox->backgroundColor->b);

        $textCommands = array_values(array_filter(
            $page->commands,
            static fn (object $command): bool => $command instanceof TextPaintCommand,
        ));
        self::assertCount(1, $textCommands);
        $text = $textCommands[0];
        self::assertSame('Hi', $text->text);
        self::assertSame(10.0, $text->x);
        self::assertGreaterThanOrEqual(10.0, $text->y);
        self::assertSame(10.0, $text->fontSize);
        self::assertSame('Fixture', $text->fontFamily);
        self::assertSame(700, $text->fontWeight);
        self::assertSame('italic', $text->fontStyle);
        self::assertNotNull($text->color);
        self::assertSame(18.0, $text->color->r);
        self::assertSame(52.0, $text->color->g);
        self::assertSame(86.0, $text->color->b);
    }

    public function testDisplayListKeepsSkippedPhysicalPageEmpty(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>'
                . '@page { size:300px 200px; margin:20px; }'
                . 'p { margin:0; height:40px; }'
                . '#second { break-before:right; }'
                . '</style><p>one</p><p id="second">two</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        self::assertCount(3, $prepared->displayList->pages);
        self::assertNotEmpty($prepared->displayList->pages[0]->commands);
        self::assertSame([], $prepared->displayList->pages[1]->commands);
        self::assertNotEmpty($prepared->displayList->pages[2]->commands);
    }
}
