<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * Tres entradas que faltavam na folha UA.
 *
 * `<sup>` e `<sub>` nao tinham estilo nenhum: `art. 1<sup>o</sup>` saia como "art. 1o", no
 * tamanho do corpo e na linha de base do corpo. Sao 37 documentos do corpus com `<sup>` (50 das
 * ocorrencias sao exatamente esse indicador ordinal) e 6 com `<sub>`.
 *
 * `<a>` nao era sublinhado. Aqui a folha diverge de proposito da referencia, que tambem so define
 * a cor: sao 221 documentos com 656 links cujo CSS proprio nao diz nada sobre decoracao, e todos
 * eles saem sublinhados na saida do wkhtmltopdf contra a qual este port e comparado.
 */
final class UserAgentSupSubAndLinkTest extends TestCase
{
    /** @return array<string,TextPaintCommand> */
    private function porTexto(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        $porTexto = [];
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand) $porTexto[trim($command->text)] = $command;
            }
        }

        return $porTexto;
    }

    public function testSupIsRaisedAndSmaller(): void
    {
        $runs = $this->porTexto('<p>base<sup>SUP</sup>fim</p>');

        self::assertLessThan($runs['base']->fontSize, $runs['SUP']->fontSize);
        self::assertLessThan($runs['base']->baseline, $runs['SUP']->baseline, 'o expoente sobe');
    }

    public function testSubIsLoweredAndSmaller(): void
    {
        $runs = $this->porTexto('<p>base<sub>SUB</sub>fim</p>');

        self::assertLessThan($runs['base']->fontSize, $runs['SUB']->fontSize);
        self::assertGreaterThan($runs['base']->baseline, $runs['SUB']->baseline, 'o indice desce');
    }

    public function testAuthorCssStillBeatsTheUserAgentSheet(): void
    {
        $runs = $this->porTexto('<p>base<sup style="font-size:30px;vertical-align:baseline">SUP</sup>fim</p>');

        self::assertEqualsWithDelta(30.0, $runs['SUP']->fontSize, 0.01);
        self::assertSame($runs['base']->baseline, $runs['SUP']->baseline);
    }

    public function testLinksAreUnderlined(): void
    {
        $runs = $this->porTexto('<p><a href="http://exemplo">LINK</a> comum</p>');

        self::assertTrue($runs['LINK']->underline);
        self::assertFalse($runs['comum']->underline);
    }

    public function testLinkUnderlineCanBeTurnedOffByTheAuthor(): void
    {
        $runs = $this->porTexto('<p><a href="http://exemplo" style="text-decoration:none">LINK</a></p>');

        self::assertFalse($runs['LINK']->underline);
    }

    public function testLinkKeepsItsColourAndHref(): void
    {
        $runs = $this->porTexto('<p><a href="http://exemplo">LINK</a></p>');

        self::assertSame('http://exemplo', $runs['LINK']->linkHref);
        self::assertNotNull($runs['LINK']->color);
        self::assertSame([0.0, 0.0, 238.0], [(float) $runs['LINK']->color->r, (float) $runs['LINK']->color->g, (float) $runs['LINK']->color->b]);
    }
}
