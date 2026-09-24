<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * `::first-letter` and `::first-line` (CSS Pseudo 4 §§4-5) were parsed by the selector grammar
 * but never reached the cascade at all: StyleComputer collected only `::before`/`::after` rules
 * and dropped every other pseudo-element whole, so `p::first-letter { font-size: 200% }` (a drop
 * cap) and `p::first-line { font-weight: bold }` both changed nothing.
 */
final class FirstLetterAndFirstLineTest extends TestCase
{
    /** @return list<TextPaintCommand> */
    private function texts(string $html, ?int $viewportWidth = null): array
    {
        $options = ['pagedBodyMargin' => 'zero', 'margins' => 0.0, 'html' => $html];
        if ($viewportWidth !== null) {
            $options['viewportWidth'] = $viewportWidth;
            $options['viewportHeight'] = 200;
        }
        $prepared = Pagyra::prepareHtmlRender($options);
        $found = [];
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand) $found[] = $command;
        }

        return $found;
    }

    public function testFirstLetterSplitsOffTheFirstCharacterWithItsOwnStyle(): void
    {
        $texts = $this->texts('<style>p::first-letter{font-size:32px;color:red}</style><p style="margin:0;font-size:16px">Texto normal</p>');

        self::assertCount(2, $texts);
        self::assertSame('T', $texts[0]->text);
        self::assertEqualsWithDelta(32.0, $texts[0]->fontSize, 0.01);
        self::assertEqualsWithDelta(255.0, $texts[0]->color->r, 0.5);
        self::assertSame('exto normal', $texts[1]->text);
        self::assertEqualsWithDelta(16.0, $texts[1]->fontSize, 0.01);
        self::assertEqualsWithDelta(0.0, $texts[1]->color->r, 0.5);
    }

    public function testFirstLetterOfAnEmptyOrWhitespaceOnlyElementDoesNothing(): void
    {
        $texts = $this->texts('<style>p::first-letter{color:red}</style><p style="margin:0">   </p>');

        self::assertSame([], $texts);
    }

    public function testWithoutARuleTheElementIsUnaffected(): void
    {
        $texts = $this->texts('<p style="margin:0">Texto normal</p>');

        self::assertCount(1, $texts);
        self::assertSame('Texto normal', $texts[0]->text);
    }

    public function testFirstLineBoldsOnlyTheWrappedFirstLine(): void
    {
        $texts = $this->texts(
            '<style>p::first-line{font-weight:bold;color:blue}</style><p style="margin:0;width:80px">um texto que quebra em duas linhas aqui</p>',
            80,
        );

        self::assertGreaterThan(1, count($texts));
        self::assertSame(700, $texts[0]->fontWeight);
        self::assertEqualsWithDelta(255.0, $texts[0]->color->b, 0.5);
        foreach (array_slice($texts, 1) as $later) {
            self::assertSame(400, $later->fontWeight);
            self::assertEqualsWithDelta(0.0, $later->color->b, 0.5);
        }
    }

    public function testFirstLetterAndFirstLineCanApplyTogether(): void
    {
        $texts = $this->texts(
            '<style>p::first-letter{color:red}p::first-line{font-weight:bold}</style><p style="margin:0;width:60px">alfa beta gama</p>',
            60,
        );

        self::assertGreaterThan(1, count($texts));
        self::assertSame('a', $texts[0]->text);
        self::assertEqualsWithDelta(255.0, $texts[0]->color->r, 0.5);
        self::assertSame(700, $texts[0]->fontWeight);
    }
}
