<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\ImagePaintCommand;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * O resto dos hints de apresentacao da secao de rendering do HTML Standard que estes documentos
 * de fato usam — so `border`, `width` e `height` de tabela estavam mapeados.
 *
 * `align` e o que decide alguma coisa: 1622 `<p align>` em 422 documentos, mas em 1447 deles o
 * mesmo elemento tambem traz `text-align` no `style`, que vence um hint, entao sao 25 documentos
 * onde ignorar o atributo deixava a esquerda um titulo que devia sair centralizado.
 * `cellpadding`/`cellspacing` (12 e 24 documentos) importam porque sem eles toda celula cai no
 * padding de 8px da folha UA e a grade saia muito mais folgada que a do wkhtmltopdf. `hr size`
 * (30 documentos) e a espessura do filete e `hspace`/`vspace`/`border` de `<img>` (46 documentos)
 * as margens e a moldura dele.
 */
final class PresentationalAttributeHintsTest extends TestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function primeiroTexto(string $html): TextPaintCommand
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand && trim($command->text) !== '') return $command;
            }
        }

        self::fail('nada foi pintado');
    }

    public function testAlignAttributeCentresAParagraph(): void
    {
        $centralizado = $this->primeiroTexto('<div style="width:400px"><p align="center">ALVO</p></div>');
        $padrao = $this->primeiroTexto('<div style="width:400px"><p>ALVO</p></div>');

        self::assertGreaterThan($padrao->x, $centralizado->x);
    }

    public function testAlignAttributeLosesToAnAuthorDeclaration(): void
    {
        // O caso dos 1447: o hint fica abaixo do `style` do autor.
        $comStyle = $this->primeiroTexto('<div style="width:400px"><p align="center" style="text-align:left">ALVO</p></div>');
        $padrao = $this->primeiroTexto('<div style="width:400px"><p>ALVO</p></div>');

        self::assertSame($padrao->x, $comStyle->x);
    }

    public function testAlignWorksOnDivAndCell(): void
    {
        $direita = $this->primeiroTexto('<div style="width:400px"><div align="right">ALVO</div></div>');
        $celula = $this->primeiroTexto('<table style="width:400px"><tr><td align="center">ALVO</td></tr></table>');
        $padraoCelula = $this->primeiroTexto('<table style="width:400px"><tr><td>ALVO</td></tr></table>');

        self::assertGreaterThan(200.0, $direita->x);
        self::assertGreaterThan($padraoCelula->x, $celula->x);
    }

    public function testCellpaddingReplacesTheUserAgentPadding(): void
    {
        $semPadding = $this->primeiroTexto('<table cellpadding="0" border="1"><tr><td>ALVO</td></tr></table>');
        $padrao = $this->primeiroTexto('<table border="1"><tr><td>ALVO</td></tr></table>');

        self::assertLessThan($padrao->x, $semPadding->x);
        self::assertLessThan($padrao->y, $semPadding->y);
    }

    public function testHrSizeIsTheRuleThickness(): void
    {
        $espessura = function (string $html): float {
            $prepared = Pagyra::prepareHtmlRender(['html' => $html, 'viewportWidth' => 600, 'viewportHeight' => 800]);

            return $prepared->layoutRoot->children[0]->box->border->top;
        };

        self::assertSame(2.0, $espessura('<hr size="2">'));
        self::assertSame(1.0, $espessura('<hr>'), 'sem o atributo continua o filete de 1px da folha UA');
    }

    public function testImageSpacingAndBorderAttributes(): void
    {
        $imagem = function (string $atributos): ImagePaintCommand {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => '<p><img src="' . self::PNG . '" width="20" height="20" ' . $atributos . '></p>',
                'viewportWidth' => 600,
                'viewportHeight' => 800,
            ]);
            foreach ($prepared->displayList->pages as $page) {
                foreach ($page->commands as $command) {
                    if ($command instanceof ImagePaintCommand) return $command;
                }
            }
            self::fail('a imagem nao foi pintada');
        };

        $comEspaco = $imagem('hspace="10" vspace="5" border="2"');
        $semEspaco = $imagem('');

        self::assertSame($semEspaco->x + 12.0, $comEspaco->x, 'hspace de 10 mais a borda de 2');
        self::assertGreaterThan($semEspaco->y, $comEspaco->y);
    }

    public function testTableBorderWidthAndHeightHintsStillWork(): void
    {
        // Guarda dos hints que ja existiam.
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table border="1" width="400"><tr><td width="300">A</td><td>B</td></tr></table>',
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        $tabela = $prepared->layoutRoot->children[0];
        self::assertSame(1.0, $tabela->box->border->top);
        self::assertEqualsWithDelta(400.0, $tabela->box->content->width, 1.0);
    }
}
