<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\LayoutNode;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * Documentos reais chegam cheios de elementos que a folha UA não conhece: `<mce:style>` do
 * TinyMCE já está no corpus, HTML colado do Word traz `<o:p>`, e cada tribunal inventa os seus.
 * Nenhum deles pode fazer conteúdo desaparecer da conversão.
 */
final class UnknownElementsKeepTheirContentTest extends TestCase
{
    /** @return list<array{0:float,1:string}> todo texto renderizado, com o y da linha */
    private function textoRenderizado(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 500,
            'viewportHeight' => 400,
        ]);

        $itens = [];
        $percorre = function (LayoutNode $no) use (&$percorre, &$itens): void {
            foreach ($no->lineBoxes as $linha) {
                foreach ($linha->runs as $run) {
                    $texto = trim($run->text);
                    if ($texto !== '') {
                        $itens[] = [$run->y, $texto];
                    }
                }
            }
            foreach ($no->children as $filho) {
                $percorre($filho);
            }
        };
        $percorre($prepared->layoutRoot);

        usort($itens, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $itens;
    }

    /** @param list<array{0:float,1:string}> $itens */
    private function apenasTexto(array $itens): string
    {
        return implode(' ', array_column($itens, 1));
    }

    public function testElementoDesconhecidoNoTopoNaoPerdeSeuTexto(): void
    {
        $itens = $this->textoRenderizado('<p>ANTES</p><foobar>MEIO</foobar><p>DEPOIS</p>');

        // Preservado e, também, na ordem certa: entre os dois parágrafos, não no fim nem no topo.
        self::assertSame('ANTES MEIO DEPOIS', $this->apenasTexto($itens));
    }

    public function testElementoDesconhecidoEnvolvendoBlocosNaoApagaODocumento(): void
    {
        $itens = $this->textoRenderizado(
            '<article><secao-custom><p>PARAGRAFO ANINHADO</p></secao-custom></article>'
        );

        self::assertSame('PARAGRAFO ANINHADO', $this->apenasTexto($itens));
    }

    public function testElementoComNamespaceDoWordOuDoEditorEPreservado(): void
    {
        self::assertSame('TEXTO DO WORD', $this->apenasTexto($this->textoRenderizado('<div><o:p>TEXTO DO WORD</o:p></div>')));
        self::assertSame('TEXTO DO TINYMCE', $this->apenasTexto($this->textoRenderizado('<div><mce:item>TEXTO DO TINYMCE</mce:item></div>')));
    }

    public function testTextoSoltoNaRaizContinuaNoDocumento(): void
    {
        $itens = $this->textoRenderizado('TEXTO SOLTO<p>e um paragrafo</p>');

        self::assertSame('TEXTO SOLTO e um paragrafo', $this->apenasTexto($itens));
    }

    public function testInlineComumSegueNaMesmaLinhaEmVezDeVirarBloco(): void
    {
        // A promoção vale só para inline que carrega bloco: um <span> de texto continua inline,
        // senão toda ênfase no meio de um parágrafo quebraria a linha.
        $itens = $this->textoRenderizado('<p>a <span>meio</span> b</p>');

        self::assertNotSame([], $itens);
        $ys = array_unique(array_column($itens, 0));
        self::assertCount(1, $ys, 'o span não deveria ter quebrado a linha do parágrafo');
    }

    public function testInlineQueCarregaBlocoViraBlocoEMantemTudo(): void
    {
        $itens = $this->textoRenderizado('<span>antes<div>BLOCO DENTRO DE INLINE</div>depois</span>');

        $texto = $this->apenasTexto($itens);
        self::assertStringContainsString('BLOCO DENTRO DE INLINE', $texto);
        self::assertStringContainsString('antes', $texto);
        self::assertStringContainsString('depois', $texto);
    }
}
