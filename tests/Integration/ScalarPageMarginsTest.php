<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * A bare number for `margins` reaches the page as the same margin on all four sides. Before, it
 * was dropped for the 48px default without an error, so a caller asking for 10mm got 12.7mm.
 */
final class ScalarPageMarginsTest extends TestCase
{
    private const TEN_MM = 37.795;

    /** The option set a caller uses to match `wkhtmltopdf --encoding UTF-8`. */
    private static function options(mixed $margins): array
    {
        return [
            'html' => '<p>Ementa</p><p style="text-align:justify">Acordao publicado a parte.</p>',
            'contentScale' => 0.8,
            'media' => 'screen',
            'margins' => $margins,
            'pageWidth' => 794,
            'pageHeight' => 1123,
        ];
    }

    public function testANumberIsTheMarginOnEverySide(): void
    {
        $prepared = Pagyra::prepareHtmlRender(['html' => '<p style="margin:0">x</p>', 'margins' => self::TEN_MM]);

        self::assertSame(
            ['top' => self::TEN_MM, 'right' => self::TEN_MM, 'bottom' => self::TEN_MM, 'left' => self::TEN_MM],
            $prepared->margins,
        );

        // the text is painted at the top-left corner of the content area, which the margin sets
        $text = null;
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand) {
                $text = $command;
                break;
            }
        }
        self::assertNotNull($text);
        self::assertEqualsWithDelta(self::TEN_MM, $text->x, 1e-9);
        self::assertEqualsWithDelta(self::TEN_MM, $text->y, 1e-9);
    }

    public function testANumberRendersExactlyLikeTheSameValueSpelledOutPerSide(): void
    {
        $perSide = ['top' => self::TEN_MM, 'right' => self::TEN_MM, 'bottom' => self::TEN_MM, 'left' => self::TEN_MM];

        self::assertSame(
            Pagyra::renderHtmlToPdf(self::options($perSide)),
            Pagyra::renderHtmlToPdf(self::options(self::TEN_MM)),
        );
    }

    public function testTheNumberIsNoLongerTheDefault(): void
    {
        self::assertNotSame(
            Pagyra::renderHtmlToPdf(self::options(null)),
            Pagyra::renderHtmlToPdf(self::options(self::TEN_MM)),
        );
    }
}
