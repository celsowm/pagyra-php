<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Empty paragraphs at the end of a document (`<p>&nbsp;</p>` left by word processors after the
 * signature) must not push a blank page onto the end of the PDF.
 */
final class TrailingBlankPageTest extends TestCase
{
    /** A 200px page with no margins: a 190px block leaves room for less than one more line. */
    private const PAGE = ['pagedBodyMargin' => 'zero', 'margins' => 0.0, 'pageWidth' => 300, 'pageHeight' => 200];

    private const FULL_PAGE = '<div style="height:190px">Assinado digitalmente</div>';

    /** @return list<list<string>> text painted on each page, whitespace-only runs left out */
    private function textByPage(string $html): array
    {
        $pages = Pagyra::prepareHtmlRender(['html' => $html] + self::PAGE)->displayList->pages;
        return array_map(static fn($page): array => array_values(array_filter(array_map(
            static fn(object $c): ?string => $c instanceof TextPaintCommand && trim($c->text, " \u{00A0}") !== '' ? $c->text : null,
            $page->commands,
        ))), $pages);
    }

    public function testTrailingEmptyParagraphsDoNotAddAPage(): void
    {
        $pages = $this->textByPage(self::FULL_PAGE . str_repeat('<p>&nbsp;</p>', 4));

        self::assertCount(1, $pages);
        self::assertSame(['Assinado digitalmente'], $pages[0]);
    }

    public function testThePdfEndsOnThePageWithTheSignature(): void
    {
        $pdf = Pagyra::renderHtmlToPdf(['html' => self::FULL_PAGE . '<p>&nbsp;</p><p>&nbsp;</p>'] + self::PAGE);

        self::assertSame(1, preg_match_all('~/Type\s*/Page(?![a-zA-Z])~', $pdf));
    }

    public function testTextOnTheLastPageKeepsIt(): void
    {
        $pages = $this->textByPage(self::FULL_PAGE . '<p>&nbsp;</p><p>fim</p><p>&nbsp;</p>');

        self::assertCount(2, $pages);
        self::assertSame(['fim'], $pages[1]);
    }

    /** @return array<string, array{0:string}> */
    public static function markWithoutText(): array
    {
        return [
            'border' => ['<div style="border:1px solid #000;height:20px"></div>'],
            'background' => ['<p style="background:#ccc">&nbsp;</p>'],
            'underlined space' => ['<p><u>&nbsp;&nbsp;&nbsp;</u></p>'],
            'image' => ['<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==" width="20" height="20">'],
        ];
    }

    #[DataProvider('markWithoutText')]
    public function testAPageWithAVisibleMarkButNoTextIsKept(string $mark): void
    {
        self::assertCount(2, $this->textByPage(self::FULL_PAGE . $mark));
    }

    public function testABlankPageInTheMiddleIsKept(): void
    {
        $pages = $this->textByPage('<p>um</p><p style="break-before:right">dois</p>');

        self::assertCount(3, $pages);
        self::assertSame([], $pages[1]);
        self::assertSame(['dois'], $pages[2]);
    }

    public function testAnEmptyPageAForcedBreakAsksForIsKept(): void
    {
        $pages = $this->textByPage('<p>um</p><div style="break-before:page;height:10px"></div><p>&nbsp;</p>');

        self::assertCount(2, $pages);
    }

    public function testEmptyParagraphsOverflowingPastAForcedBreakPageAreStillTrimmed(): void
    {
        $pages = $this->textByPage('<p>um</p><div style="break-before:page">' . self::FULL_PAGE . '</div>' . str_repeat('<p>&nbsp;</p>', 4));

        self::assertCount(2, $pages);
        self::assertSame(['Assinado digitalmente'], $pages[1]);
    }

    public function testADocumentWithNothingVisibleStillHasOnePage(): void
    {
        $pdf = Pagyra::renderHtmlToPdf(['html' => str_repeat('<p>&nbsp;</p>', 40)] + self::PAGE);

        self::assertSame(1, preg_match_all('~/Type\s*/Page(?![a-zA-Z])~', $pdf));
    }
}
