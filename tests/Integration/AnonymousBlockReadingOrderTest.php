<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\BlockLayoutEngine;
use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * Um bloco que mistura conteudo inline e blocos guardava todas as linhas nas proprias lineBoxes
 * enquanto os blocos iam para os filhos, e a caminhada de pintura emite as linhas de um no antes
 * de qualquer filho dele. A geometria saia certa, a ordem das operacoes de desenho nao: em
 * `<div>SOLTO<p>BLOCO</p>FIM</div>` o "FIM" era desenhado antes do "BLOCO", que e o que recebe
 * quem copia o texto da decisao no PDF. Um documento do corpus (EPROC1/202608310009464) embaralha
 * assim as duas primeiras linhas de uma ementa do TJRJ.
 *
 * Pior no topo do documento: as lineBoxes da raiz nao eram pintadas de jeito nenhum, porque a
 * paginacao comeca pelos filhos dela, entao `<p>a</p>solto<p>b</p>` perdia "solto" e um `<img>`
 * ou texto direto dentro de `<body>` sumia — presente na arvore de layout, ausente do PDF.
 *
 * Agora cada corrida de conteudo inline vira uma caixa de bloco anonima (CSS 2.1 9.2.1.1) na
 * posicao certa entre os irmaos. Um bloco cujo conteudo e todo inline continua com as linhas em
 * si mesmo, como antes.
 */
final class AnonymousBlockReadingOrderTest extends TestCase
{
    /** @return list<string> texto pintado, na ordem em que e desenhado */
    private function ordemDePintura(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 500,
            'viewportHeight' => 400,
        ]);

        $textos = [];
        foreach ($prepared->displayList->pages as $pagina) {
            foreach ($pagina->commands as $comando) {
                if ($comando instanceof TextPaintCommand) {
                    $texto = trim($comando->text);
                    if ($texto !== '') $textos[] = $texto;
                }
            }
        }

        return $textos;
    }

    public function testInlineDepoisDeUmBlocoEDesenhadoDepoisDele(): void
    {
        self::assertSame(['SOLTO', 'BLOCO', 'FIM'], $this->ordemDePintura('<div>SOLTO<p>BLOCO</p>FIM</div>'));
    }

    public function testAOrdemValeEmQualquerContainer(): void
    {
        self::assertSame(['A', 'B', 'C'], $this->ordemDePintura('<section>A<p>B</p>C</section>'));
        self::assertSame(['A', 'B', 'C'], $this->ordemDePintura('<blockquote>A<p>B</p>C</blockquote>'));
        self::assertSame(['A', 'B', 'C'], $this->ordemDePintura('<table><tr><td>A<p>B</p>C</td></tr></table>'));
    }

    public function testConteudoInlineNaRaizChegaAoPapel(): void
    {
        self::assertSame(['a', 'solto', 'b'], $this->ordemDePintura('<p>a</p>solto<p>b</p>'));
        self::assertSame(['texto em body'], $this->ordemDePintura('<html><body>texto em body</body></html>'));
        self::assertSame(['A', 'B', 'C'], $this->ordemDePintura('<html><body>A<p>B</p>C</body></html>'));
    }

    public function testImagemDiretaDentroDeBodyNaoSome(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<html><body><img src="' . $png . '" width="20" height="20"></body></html>',
            'viewportWidth' => 500,
            'viewportHeight' => 400,
        ]);

        $imagens = 0;
        foreach ($prepared->displayList->pages as $pagina) {
            foreach ($pagina->commands as $comando) {
                if ($comando instanceof \Pagyra\Paint\ImagePaintCommand) $imagens++;
            }
        }

        self::assertSame(1, $imagens);
    }

    public function testGeometriaContinuaCorreta(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="width:400px;margin:0">SOLTO<p style="margin:0">BLOCO</p>FIM</div>',
            'viewportWidth' => 500,
            'viewportHeight' => 400,
        ]);

        $ys = [];
        foreach ($prepared->displayList->pages as $pagina) {
            foreach ($pagina->commands as $comando) {
                if ($comando instanceof TextPaintCommand) $ys[trim($comando->text)] = $comando->y;
            }
        }

        self::assertLessThan($ys['BLOCO'], $ys['SOLTO']);
        self::assertLessThan($ys['FIM'], $ys['BLOCO']);
    }

    public function testBlocoSoDeInlineNaoGanhaCaixaAnonima(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div>SO_INLINE</div>',
            'viewportWidth' => 500,
            'viewportHeight' => 400,
        ]);

        $div = $prepared->layoutRoot->children[0];
        self::assertSame([], $div->children, 'nada de caixa anonima quando nao ha mistura');
        self::assertCount(1, $div->lineBoxes);
    }

    public function testCaixaAnonimaNaoRepeteAMolduraDoPai(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="border:5px solid black;background-color:#ff0000">SOLTO<p>BLOCO</p></div>',
            'viewportWidth' => 500,
            'viewportHeight' => 400,
        ]);

        $anonima = $prepared->layoutRoot->children[0]->children[0];
        self::assertSame(BlockLayoutEngine::ANONYMOUS_TAG, $anonima->source->node->tagName);
        self::assertNull($anonima->source->style->get('border-top-style'));
        self::assertNull($anonima->source->style->get('background-color'));
        self::assertSame(0.0, $anonima->box->border->top);
    }
}
