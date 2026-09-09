<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * O motor de tabela achava as linhas pelo nome da tag (`tr`, `tbody`, `thead`, `tfoot`) e
 * descartava em silencio qualquer outro filho. Com isso `columnCount` ficava zero e a caixa
 * voltava vazia: sumia a subarvore inteira do PDF, sem erro nenhum.
 *
 * O caso real e a folha do CKEditor que o eproc e a JFRJ embutem, que traz `.table { display:
 * table }` sobre a marcacao `<figure class="table"><table>` — o `<table>` de verdade vira filho
 * nao-linha de uma caixa de tabela externa. Tres documentos do corpus perdiam uma tabela inteira
 * assim, um deles a tabela de niveis de evidencia cientifica que fundamenta a decisao.
 */
final class AnonymousTableBoxesTest extends TestCase
{
    /** @return list<string> */
    private function textos(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        $textos = [];
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand) {
                    $texto = trim($command->text);
                    if ($texto !== '') $textos[] = $texto;
                }
            }
        }

        return $textos;
    }

    public function testTableWrapperFromTheEditorStylesheetKeepsItsTable(): void
    {
        $textos = $this->textos('<figure style="display:table"><table><tbody><tr><td>A1</td><td>B1</td></tr></tbody></table></figure>');

        self::assertSame(['A1', 'B1'], $textos);
    }

    public function testDisplayTableOnPlainContentDoesNotSwallowIt(): void
    {
        self::assertSame(['TEXTO'], $this->textos('<div style="display:table">TEXTO</div>'));
        self::assertSame(['P_DIRETO'], $this->textos('<table><p>P_DIRETO</p></table>'));
    }

    public function testRowsAndCellsAreFoundByComputedDisplayNotByTagName(): void
    {
        $textos = $this->textos('<table><div style="display:table-row"><div style="display:table-cell">DR</div></div></table>');

        self::assertSame(['DR'], $textos);
    }

    public function testOrdinaryTablesAreUnchanged(): void
    {
        self::assertSame(['A1', 'B1', 'A2', 'B2'], $this->textos('<table border="1"><tbody><tr><td>A1</td><td>B1</td></tr><tr><td>A2</td><td>B2</td></tr></tbody></table>'));
        self::assertSame(['H', 'C'], $this->textos('<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>C</td></tr></tbody></table>'));
    }

    public function testWhitespaceBetweenRowsGeneratesNoAnonymousRow(): void
    {
        // Marcacao indentada nao pode ganhar uma linha vazia a mais no meio.
        $prepared = Pagyra::prepareHtmlRender([
            'html' => "<table>\n  <tbody>\n    <tr><td>A</td></tr>\n    <tr><td>B</td></tr>\n  </tbody>\n</table>",
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        $tabela = $prepared->layoutRoot->children[0];
        self::assertCount(2, $tabela->children, 'a tabela tem exatamente duas linhas');
        self::assertSame(['A', 'B'], $this->textos("<table>\n  <tbody>\n    <tr><td>A</td></tr>\n    <tr><td>B</td></tr>\n  </tbody>\n</table>"));
    }

    public function testAnonymousBoxDoesNotInheritTheTableFrame(): void
    {
        // A caixa anonima leva so as propriedades herdaveis: se levasse a borda da tabela,
        // desenharia uma moldura a mais em volta do conteudo que ela apenas realoja.
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="display:table;border:5px solid black">TEXTO</div>',
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        $bordas = 0;
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof \Pagyra\Paint\BorderPaintCommand) $bordas++;
            }
        }

        self::assertGreaterThan(0, $bordas, 'a propria caixa display:table continua com borda');
        self::assertSame(['TEXTO'], $this->textos('<div style="display:table;border:5px solid black">TEXTO</div>'));
    }

    public function testDisplayNoneInsideATableIsStillHidden(): void
    {
        $textos = $this->textos('<table><tbody><tr><td>VISIVEL</td></tr><tr style="display:none"><td>OCULTO</td></tr></tbody></table>');

        self::assertSame(['VISIVEL'], $textos);
    }
}
