<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * `<html>` and `<body>` take part in the cascade and the body is laid out as a block box. The
 * parser used to keep only the body's children, so every rule aimed at those two elements —
 * `body { font-family; font-size; color; margin }`, `html { font-size }` for `rem`,
 * `<body class="x">` for `.x p` — was silently ignored, and the UA's 8px body margin never applied.
 */
final class HtmlAndBodyCascadeTest extends TestCase
{
    private function firstText(array $options): TextPaintCommand
    {
        foreach (Pagyra::prepareHtmlRender($options)->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand && trim($command->text) !== '') {
                    return $command;
                }
            }
        }
        self::fail('no text painted');
    }

    private const PAGE = ['pageWidth' => 400.0, 'pageHeight' => 400.0, 'viewportWidth' => 400.0, 'viewportHeight' => 400.0, 'margins' => 0.0];

    public function testBodyRuleReachesTheText(): void
    {
        $text = $this->firstText(self::PAGE + [
            'html' => '<style>body { font-family: Helvetica, sans-serif; font-size: 20px; color: #c00000; }</style><p>corpo</p>',
        ]);

        self::assertEqualsWithDelta(20.0, $text->fontSize, 0.001);
        self::assertStringContainsString('Helvetica', (string) $text->fontFamily);
        self::assertSame(192, (int) round($text->color->r));
    }

    public function testBodyClassMatchesDescendantSelectors(): void
    {
        $text = $this->firstText(self::PAGE + [
            'html' => '<html><head><style>.ck-content p { font-size: 24px; }</style></head><body class="ck-content"><p>x</p></body></html>',
        ]);

        self::assertEqualsWithDelta(24.0, $text->fontSize, 0.001);
    }

    public function testRemFollowsTheHtmlFontSize(): void
    {
        $text = $this->firstText(self::PAGE + [
            'html' => '<style>html { font-size: 10px; } p { font-size: 1.5rem; }</style><p>x</p>',
        ]);

        self::assertEqualsWithDelta(15.0, $text->fontSize, 0.001);
    }

    public function testDefaultBodyMarginCollapsesWithTheFirstChild(): void
    {
        $plain = $this->firstText(self::PAGE + ['html' => '<div>x</div>']);
        $paragraph = $this->firstText(self::PAGE + ['html' => '<p>x</p>']);

        // 8px on each side from the UA sheet, as in the reference's default `auto` mode.
        self::assertEqualsWithDelta(8.0, $plain->x, 0.001);
        self::assertEqualsWithDelta(8.0, $plain->y, 0.001);
        // The body's 8px and the paragraph's 16px collapse into 16, not 24.
        self::assertEqualsWithDelta(16.0, $paragraph->y, 0.001);
    }

    public function testAuthorBodyMarginAndCenteredMaxWidth(): void
    {
        $text = $this->firstText(self::PAGE + [
            'html' => '<style>body { margin: 20px auto; max-width: 200px; }</style><div>x</div>',
        ]);

        self::assertEqualsWithDelta(100.0, $text->x, 0.001);
        self::assertEqualsWithDelta(20.0, $text->y, 0.001);
    }

    public function testPagedBodyMarginZeroRemovesIt(): void
    {
        $text = $this->firstText(self::PAGE + [
            'pagedBodyMargin' => 'zero',
            'html' => '<style>body { margin: 30px; }</style><div>x</div>',
        ]);

        self::assertEqualsWithDelta(0.0, $text->x, 0.001);
        self::assertEqualsWithDelta(0.0, $text->y, 0.001);
    }

    public function testInvalidPagedBodyMarginIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pagyra::prepareHtmlRender(['html' => '<p>x</p>', 'pagedBodyMargin' => 'none']);
    }
}
