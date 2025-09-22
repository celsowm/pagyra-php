<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Graphics\Painter;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Graphics\Gradient\PdfGradientFactory;
use Celsowm\PagyraPhp\Graphics\Shading\PdfShadingRegistry;

/**
 * Responsabilidade única: pintar fundo com gradiente em retângulos/rounded (clip + 'sh').
 */
final class PdfBackgroundPainter
{
    public function __construct(
        private PdfBuilder $pdf,
        private PdfGradientFactory $factory,
        private PdfShadingRegistry $registry
    ) {}

    public function linearRect(
        float $x,
        float $y,
        float $w,
        float $h,
        array $grad,
        ?array $radius = null,
        ?float $alpha = null
    ): void {

        $coords = $grad['coords'] ?? $this->coordsFromAngle($x, $y, $w, $h, (float)($grad['angle'] ?? 0));
        $fnId   = $this->factory->buildStitchingFn($grad['stops']);
        $ext    = $grad['extend'] ?? [true, true];

        $sh = $this->registry->getOrCreate('axial', $coords, $fnId, $ext);
        $this->registry->registerOnPage($sh['name'], $sh['id']);

        $this->emitPaintOps($x, $y, $w, $h, $radius, $alpha, $sh['name']);
    }

    public function radialRect(
        float $x,
        float $y,
        float $w,
        float $h,
        array $grad,
        ?array $radius = null,
        ?float $alpha = null
    ): void {

        $coords = $grad['coords'] ?? $this->defaultRadialCoords($x, $y, $w, $h, $grad);
        $fnId   = $this->factory->buildStitchingFn($grad['stops']);
        $ext    = $grad['extend'] ?? [true, true];

        $sh = $this->registry->getOrCreate('radial', $coords, $fnId, $ext);
        $this->registry->registerOnPage($sh['name'], $sh['id']);

        $this->emitPaintOps($x, $y, $w, $h, $radius, $alpha, $sh['name']);
    }

    private function emitPaintOps(float $x, float $y, float $w, float $h, ?array $radius, ?float $alpha, string $shName): void
    {
        $page = $this->pdf->getCurrentPage();
        $before = strlen($this->pdf->pageContents[$page] ?? '');

        $ops = "q\n";

        if (is_float($alpha) && $alpha < 1.0) {
            if (method_exists($this->pdf, 'ensureExtGState')) {
                $gs = $this->pdf->ensureExtGState(max(0.0, min(1.0, $alpha)));
                // ATENÇÃO: se ensureExtGState retornar SÓ o nome (/GS1), não precisa id
                // Se o teu registerPageResource exigir id também, ajuste p/ ($type,$name,$id)
                $this->pdf->registerPageResource('ExtGState', $gs);
                $ops .= "{$gs} gs\n";
            }
        }

        $ops .= $this->buildClipPath($x, $y, $w, $h, $radius);
        $ops .= "{$shName} sh\nQ\n";  // ⬅️ operador correto p/ shading

        // Se teu PdfBuilder não tiver writeOps(), use appendToPageContent($ops)
        if (method_exists($this->pdf, 'writeOps')) {
            $this->pdf->writeOps($ops);
        } else {
            $this->pdf->appendToPageContent($ops);
        }
    }

    private function coordsFromAngle(float $x, float $y, float $w, float $h, float $deg): array
    {
        $rad = deg2rad($deg);
        $ux = cos($rad);
        $uy = sin($rad);
        $half = (abs($ux) * $w + abs($uy) * $h) / 2;
        $cx = $x + $w / 2;
        $cy = $y + $h / 2;
        return [$cx - $ux * $half, $cy - $uy * $half, $cx + $ux * $half, $cy + $uy * $half];
    }

    private function defaultRadialCoords(float $x, float $y, float $w, float $h, array $grad): array
    {
        $cx = $grad['center'][0] ?? ($x + $w / 2);
        $cy = $grad['center'][1] ?? ($y + $h / 2);
        $r1 = $grad['r'] ?? (0.5 * hypot($w, $h));
        return [$cx, $cy, 0.0, $cx, $cy, $r1];
    }

    private function buildClipPath(float $x, float $y, float $w, float $h, ?array $radius): string
    {
        $r = $radius ?? [0, 0, 0, 0];
        $hasRadius = is_array($r) ? (max($r) > 0.0001) : (float)$r > 0.0001;

        if (!is_array($r)) $r = array_fill(0, 4, (float)$r);

        if ($hasRadius && method_exists($this->pdf, 'buildRoundedRectPath')) {
            return $this->pdf->buildRoundedRectPath($x, $y, $w, $h, $r) . "W n\n";
        }
        return sprintf("%.3F %.3F %.3F %.3F re\nW n\n", $x, $y, $w, $h);
    }
}
