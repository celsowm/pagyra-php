<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * `text-align: -webkit-center` e o alias prefixado que o WebKit ainda honra, e o wkhtmltopdf e
 * WebKit: sete documentos do corpus saem centralizados na saida de referencia e vinham colados a
 * esquerda aqui, porque a resolucao de alinhamento so conhecia os nomes sem prefixo.
 */
final class PrefixedTextAlignTest extends TestCase
{
    private function x(string $alignment): float
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="width:400px;text-align:' . $alignment . '"><span>ALVO</span></div>',
            'viewportWidth' => 600,
            'viewportHeight' => 800,
        ]);

        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand) return $command->x;
            }
        }

        self::fail('nada foi pintado');
    }

    public function testPrefixedCentreMatchesTheUnprefixedOne(): void
    {
        self::assertSame($this->x('center'), $this->x('-webkit-center'));
        self::assertSame($this->x('center'), $this->x('-moz-center'));
    }

    public function testPrefixedRightMatchesTheUnprefixedOne(): void
    {
        self::assertSame($this->x('right'), $this->x('-webkit-right'));
        self::assertSame($this->x('right'), $this->x('-moz-right'));
    }

    public function testTheUnprefixedValuesAreUnchanged(): void
    {
        self::assertGreaterThan($this->x('left'), $this->x('center'));
        self::assertGreaterThan($this->x('center'), $this->x('right'));
        self::assertSame($this->x('left'), $this->x('start'), 'start e left num documento ltr');
        self::assertSame($this->x('right'), $this->x('end'));
    }
}
