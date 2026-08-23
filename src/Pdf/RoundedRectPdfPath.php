<?php

declare(strict_types=1);

namespace Pagyra\Pdf;

use Pagyra\Paint\BorderRadius;
use Pagyra\Units\Units;

final class RoundedRectPdfPath
{
    public static function build(float $xPx, float $yPx, float $widthPx, float $heightPx, float $pageHeightPx, BorderRadius $r): array
    {
        $x = Units::pxToPt($xPx);
        $bottom = Units::pxToPt($pageHeightPx - $yPx - $heightPx);
        $width = Units::pxToPt($widthPx);
        $height = Units::pxToPt($heightPx);
        return [
            'x' => $x,
            'bottom' => $bottom,
            'right' => $x + $width,
            'top' => $bottom + $height,
            'tlx' => Units::pxToPt($r->topLeft->x),
            'tly' => Units::pxToPt($r->topLeft->y),
            'trx' => Units::pxToPt($r->topRight->x),
            'try' => Units::pxToPt($r->topRight->y),
            'brx' => Units::pxToPt($r->bottomRight->x),
            'bry' => Units::pxToPt($r->bottomRight->y),
            'blx' => Units::pxToPt($r->bottomLeft->x),
            'bly' => Units::pxToPt($r->bottomLeft->y),
        ];
    }
}
