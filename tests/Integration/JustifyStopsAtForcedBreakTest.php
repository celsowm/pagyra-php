<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class JustifyStopsAtForcedBreakTest extends TestCase
{
    /** @return list<\Pagyra\Layout\LineBox> */
    private function lines(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 600,
            'viewportHeight' => 400,
        ]);

        return $prepared->layoutRoot->children[0]->lineBoxes;
    }

    public function testLineEndingInAForcedBreakIsNotStretched(): void
    {
        // The signature block every judicial document ends with: a short bold line, a <br>, then
        // the job title. CSS Text treats a line ended by a forced break like the last line of the
        // block, so it is not justified — before this, those four words were spread across the
        // full width of the cell.
        $lines = $this->lines(
            '<p style="margin:0;width:500px;text-align:justify;font-size:16px">'
            . 'Dr(a) BRUNO FABIANI MONTEIRO<br>Juiz Federal da Vara Federal</p>'
        );

        self::assertCount(2, $lines);
        foreach ($lines[0]->runs as $run) {
            self::assertSame(0.0, $run->justificationWordSpacing);
        }
    }

    public function testAnOrdinarySoftWrappedLineIsStillJustified(): void
    {
        $lines = $this->lines(
            '<p style="margin:0;width:260px;text-align:justify;font-size:16px">'
            . 'palavras suficientes para forcar a quebra automatica desta linha aqui</p>'
        );

        self::assertGreaterThan(1, count($lines));
        $stretched = false;
        foreach ($lines[0]->runs as $run) {
            if ($run->justificationWordSpacing > 0.0) {
                $stretched = true;
            }
        }
        self::assertTrue($stretched, 'a primeira linha de quebra automática deveria continuar justificada');
    }
}
