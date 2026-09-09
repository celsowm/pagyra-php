<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * As palavras-chave CSS-wide (`inherit`, `initial`, `unset`, `revert`) nao eram entendidas em
 * lugar nenhum: o token literal era guardado como se fosse valor e chegava inteiro na camada de
 * pintura. `font-family: inherit` virava uma familia chamada "inherit", que nao casa com nada e
 * derruba o texto em Times; `font-style: inherit` virava um estilo "inherit" em vez de italico;
 * `font-weight: inherit` dentro de um pai negrito resolvia 400; `color: inherit` nao produzia cor
 * nenhuma; e `line-height: inherit` punha o span numa linha de base diferente da do texto ao lado.
 *
 * Sao 1368 declaracoes dessas so nas propriedades visuais, em 19 documentos do corpus, 36 a 45 por
 * documento, porque e o que o navegador escreve no atributo `style` quando alguem cola texto nos
 * editores desses sistemas.
 */
final class CssWideKeywordsTest extends TestCase
{
    private function pintado(string $html): TextPaintCommand
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand && trim($command->text) === 'ALVO') {
                    return $command;
                }
            }
        }

        self::fail('o texto ALVO nao foi pintado');
    }

    public function testInheritTakesTheParentComputedValue(): void
    {
        self::assertSame(700, $this->pintado('<p style="font-weight:bold"><span style="font-weight:inherit">ALVO</span></p>')->fontWeight);
        self::assertSame('italic', $this->pintado('<p style="font-style:italic"><span style="font-style:inherit">ALVO</span></p>')->fontStyle);
        self::assertSame('Arial,sans-serif', $this->pintado('<p style="font-family:Arial,sans-serif"><span style="font-family:inherit">ALVO</span></p>')->fontFamily);
    }

    public function testInheritOnColourNoLongerLosesTheColour(): void
    {
        $cor = $this->pintado('<p style="color:red"><span style="color:inherit">ALVO</span></p>')->color;

        self::assertNotNull($cor);
        self::assertSame([255.0, 0.0, 0.0], [(float) $cor->r, (float) $cor->g, (float) $cor->b]);
    }

    public function testInheritOnANonInheritedPropertyAlsoReadsTheParent(): void
    {
        // background-color nao e herdada, entao so o `inherit` explicito traz o valor do pai.
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="background-color:#ff0000"><p style="background-color:inherit">ALVO</p></div>',
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        $encontrou = false;
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof \Pagyra\Paint\BoxPaintCommand && $command->backgroundColor !== null
                    && [(float) $command->backgroundColor->r, (float) $command->backgroundColor->g, (float) $command->backgroundColor->b] === [255.0, 0.0, 0.0]) {
                    $encontrou = true;
                }
            }
        }
        self::assertTrue($encontrou, 'o fundo vermelho do pai deveria ter sido herdado explicitamente');
    }

    public function testInheritKeepsBothRunsOnTheSameBaseline(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="line-height:40px"><span style="line-height:inherit">ALVO</span>vizinho</p>',
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        $baselines = [];
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand) {
                    $baselines[] = round($command->baseline, 3);
                }
            }
        }

        self::assertCount(2, $baselines);
        self::assertSame($baselines[0], $baselines[1], 'os dois trechos estao na mesma linha');
    }

    public function testInitialResetsAnInheritedPropertyInsteadOfKeepingTheParentValue(): void
    {
        self::assertSame(400, $this->pintado('<p style="font-weight:bold"><span style="font-weight:initial">ALVO</span></p>')->fontWeight);
        self::assertEqualsWithDelta(16.0, $this->pintado('<p style="font-size:40px"><span style="font-size:initial">ALVO</span></p>')->fontSize, 0.01);
        self::assertFalse($this->pintado('<p><u><span style="text-decoration-line:initial">ALVO</span></u></p>')->underline);
    }

    public function testUnsetAndRevertFallBackToWhatWasUnderneath(): void
    {
        // Propriedade herdada: `unset` equivale a `inherit`.
        self::assertSame(700, $this->pintado('<p style="font-weight:bold"><span style="font-weight:unset">ALVO</span></p>')->fontWeight);
        // `revert` volta ao valor da folha UA, que aqui nao define peso para <span>.
        self::assertSame(700, $this->pintado('<p style="font-weight:bold"><span style="font-weight:revert">ALVO</span></p>')->fontWeight);
    }

    public function testOrdinaryValuesAreUntouched(): void
    {
        self::assertSame(700, $this->pintado('<p><span style="font-weight:bold">ALVO</span></p>')->fontWeight);
        self::assertSame(400, $this->pintado('<p style="font-weight:bold"><span style="font-weight:normal">ALVO</span></p>')->fontWeight);
    }
}
