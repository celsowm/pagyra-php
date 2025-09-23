<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Graphics\State;

use Celsowm\PagyraPhp\Core\PdfBuilder;

final class PdfTransparency
{
    public static function wrapAlpha(PdfBuilder $pdf, float $alpha, callable $emitOps): void
    {
        [$name, $id] = $pdf->getExtGStateManager()->ensureAlphaRef($alpha);
        $pdf->registerPageResource('ExtGState', $name, $id);

        $ops = "q\n{$name} gs\n";
        $ops .= $emitOps(); // deve retornar string com os operadores
        $ops .= "Q\n";

        $pdf->appendToPageContent($ops);
    }
}
