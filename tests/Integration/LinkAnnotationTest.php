<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

final class LinkAnnotationTest extends TestCase
{
    public function testTextRunInsideAnAnchorCarriesTheHrefOnItsPaintCommand(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p>before <a href="https://example.org/x">link text</a> after</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        $texts = array_values(array_filter(
            $prepared->displayList?->pages[0]->commands ?? [],
            static fn (object $c): bool => $c instanceof TextPaintCommand,
        ));

        self::assertCount(3, $texts);
        self::assertNull($texts[0]->linkHref);
        self::assertSame('https://example.org/x', $texts[1]->linkHref);
        self::assertNull($texts[2]->linkHref);
    }

    public function testNestedSpanInsideAnAnchorInheritsTheHref(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p><a href="https://example.org/y"><span>nested</span></a></p>',
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        $texts = array_values(array_filter(
            $prepared->displayList?->pages[0]->commands ?? [],
            static fn (object $c): bool => $c instanceof TextPaintCommand,
        ));

        self::assertCount(1, $texts);
        self::assertSame('https://example.org/y', $texts[0]->linkHref);
    }

    public function testAnchorWithoutHrefProducesNoLinkOnItsText(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p><a name="anchor">not a link</a></p>',
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        $texts = array_values(array_filter(
            $prepared->displayList?->pages[0]->commands ?? [],
            static fn (object $c): bool => $c instanceof TextPaintCommand,
        ));

        self::assertCount(1, $texts);
        self::assertNull($texts[0]->linkHref);
    }

    public function testRenderedPdfContainsAClickableLinkAnnotationPointingToTheHref(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<p><a href="https://eproc1g.tjrj.jus.br/eproc/">https://eproc1g.tjrj.jus.br/eproc/</a></p>',
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        self::assertStringContainsString('/Subtype /Link', $pdf);
        self::assertStringContainsString('/S /URI', $pdf);
        self::assertStringContainsString('/URI (https://eproc1g.tjrj.jus.br/eproc/)', $pdf);
        self::assertMatchesRegularExpression('/\/Annots \[\d+ 0 R\]/', $pdf);
    }

    public function testPageWithoutAnyLinksHasNoAnnotsEntry(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<p>plain text, no links here</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        self::assertStringNotContainsString('/Annots', $pdf);
        self::assertStringNotContainsString('/Subtype /Link', $pdf);
    }
}
