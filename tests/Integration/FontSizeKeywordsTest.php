<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * As palavras-chave de `font-size` (`x-small`, `small`, `medium`, `large`, ...) caiam em
 * silencio para o tamanho herdado, nos dois sentidos: `x-small` dentro de um paragrafo de 13pt
 * continuava 13pt e `medium` dentro de um de 8px continuava 8px. Sao 482 dos 2445 documentos do
 * corpus (19,7%) e 13509 declaracoes, todas em atributo `style=` inline — o formato que o
 * navegador emite quando alguem cola texto no editor de um sistema processual —, entao nao ha
 * folha morta nenhuma entre elas.
 *
 * As razoes seguem a tabela FONT_SIZE_KEYWORDS da referencia, inclusive nos dois pontos em que
 * ela se afasta do navegador: so `medium` e absoluto (16px) e as demais escalam o tamanho do
 * pai em vez de escalar a base.
 */
final class FontSizeKeywordsTest extends TestCase
{
    private function fontSize(string $parentDeclaration, string $keyword): float
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="' . $parentDeclaration . '"><span style="font-size: ' . $keyword . '">X</span></p>',
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand && trim($command->text) === 'X') {
                    return $command->fontSize;
                }
            }
        }

        self::fail('nenhum texto foi pintado');
    }

    public function testAbsoluteSizeKeywordsScaleTheParentSize(): void
    {
        self::assertEqualsWithDelta(9.6, $this->fontSize('font-size: 16px', 'xx-small'), 0.01);
        self::assertEqualsWithDelta(12.0, $this->fontSize('font-size: 16px', 'x-small'), 0.01);
        self::assertEqualsWithDelta(14.24, $this->fontSize('font-size: 16px', 'small'), 0.01);
        self::assertEqualsWithDelta(19.2, $this->fontSize('font-size: 16px', 'large'), 0.01);
        self::assertEqualsWithDelta(24.0, $this->fontSize('font-size: 16px', 'x-large'), 0.01);
        self::assertEqualsWithDelta(32.0, $this->fontSize('font-size: 16px', 'xx-large'), 0.01);
    }

    public function testMediumIsTheAbsoluteBaseSizeAndIgnoresTheParent(): void
    {
        // A referencia devolve o 16 fixo para `medium`, entao ele nao acompanha o pai.
        self::assertEqualsWithDelta(16.0, $this->fontSize('font-size: 16px', 'medium'), 0.01);
        self::assertEqualsWithDelta(16.0, $this->fontSize('font-size: 8px', 'medium'), 0.01);
    }

    public function testRelativeSizeKeywordsAreResolvedToo(): void
    {
        self::assertEqualsWithDelta(12.8, $this->fontSize('font-size: 16px', 'smaller'), 0.01);
        self::assertEqualsWithDelta(19.2, $this->fontSize('font-size: 16px', 'larger'), 0.01);
    }

    public function testKeywordShrinksInsteadOfInheritingTheParentSize(): void
    {
        // O caso do corpus: uma linha de endereco marcada `small` dentro de um cabecalho de
        // 13pt saia do tamanho do corpo e ocupava a largura toda.
        $herdado = $this->fontSize('font-size: 13pt', 'small');
        self::assertLessThan($this->fontSize('font-size: 13pt', 'medium'), $herdado);
        self::assertEqualsWithDelta(13.0 * 96.0 / 72.0 * 0.89, $herdado, 0.01);
    }

    public function testImportantSuffixDoesNotDefeatTheKeyword(): void
    {
        self::assertEqualsWithDelta(12.0, $this->fontSize('font-size: 16px', 'x-small !important'), 0.01);
    }

    public function testLengthsAndPercentagesAreUntouched(): void
    {
        self::assertEqualsWithDelta(20.0, $this->fontSize('font-size: 16px', '20px'), 0.01);
        self::assertEqualsWithDelta(8.0, $this->fontSize('font-size: 16px', '50%'), 0.01);
        self::assertEqualsWithDelta(32.0, $this->fontSize('font-size: 16px', '2em'), 0.01);
    }

    public function testUnknownKeywordStillFallsBackToTheInheritedSize(): void
    {
        self::assertEqualsWithDelta(16.0, $this->fontSize('font-size: 16px', 'gigante'), 0.01);
    }
}
