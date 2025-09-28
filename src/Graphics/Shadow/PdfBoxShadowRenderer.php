<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Graphics\Shadow;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Color\PdfColor;

/**
 * Renders box-shadow effects in PDF documents.
 */
final class PdfBoxShadowRenderer
{
    public function __construct(
        private PdfBuilder $pdf
    ) {}

    /**
     * Render box-shadow for a rectangular area.
     * 
     * @param float $x X coordinate of the element
     * @param float $y Y coordinate of the element
     * @param float $w Width of the element
     * @param float $h Height of the element
     * @param array $shadows Array of shadow specifications
     * @param array|null $borderRadius Border radius for rounded corners
     */
    public function renderBoxShadow(
        float $x,
        float $y,
        float $w,
        float $h,
        array $shadows,
        ?array $borderRadius = null
    ): void {
        if (empty($shadows)) {
            return;
        }

        // Render shadows from back to front (reverse order)
        foreach (array_reverse($shadows) as $shadow) {
            $this->renderSingleShadow($x, $y, $w, $h, $shadow, $borderRadius);
        }
    }

    /**
     * Render a single shadow.
     */
    private function renderSingleShadow(
        float $x,
        float $y,
        float $w,
        float $h,
        array $shadow,
        ?array $borderRadius
    ): void {
        $offsetX = (float)($shadow['offsetX'] ?? 0.0);
        $offsetY = (float)($shadow['offsetY'] ?? 0.0);
        $blurRadius = (float)($shadow['blurRadius'] ?? 0.0);
        $spreadRadius = (float)($shadow['spreadRadius'] ?? 0.0);
        $color = $shadow['color'] ?? 'rgba(0, 0, 0, 0.5)';
        $alpha = (float)($shadow['alpha'] ?? 0.5);

        // Calculate shadow position and size
        $shadowX = $x + $offsetX - $spreadRadius;
        $shadowY = $y + $offsetY - $spreadRadius;
        $shadowW = $w + (2 * $spreadRadius);
        $shadowH = $h + (2 * $spreadRadius);

        // For blur effect, we'll create multiple layers with decreasing opacity
        if ($blurRadius > 0) {
            $this->renderBlurredShadow($shadowX, $shadowY, $shadowW, $shadowH, $color, $alpha, $blurRadius, $borderRadius);
        } else {
            $this->renderSolidShadow($shadowX, $shadowY, $shadowW, $shadowH, $color, $alpha, $borderRadius);
        }
    }

    /**
     * Render a solid shadow (no blur).
     */
    private function renderSolidShadow(
        float $x,
        float $y,
        float $w,
        float $h,
        string $color,
        float $alpha,
        ?array $borderRadius
    ): void {
        $page = $this->pdf->getCurrentPage();
        
        $ops = "q\n";

        // Set transparency
        if ($alpha < 1.0) {
            [$gsName, $gsId] = $this->pdf->getExtGStateManager()->ensureAlphaRef($alpha);
            $this->pdf->registerPageResource('ExtGState', $gsName, $gsId);
            $ops .= "{$gsName} gs\n";
        }

        // Set fill color
        $pdfColor = new PdfColor();
        $colorOps = $pdfColor->getFillOps($color);
        if ($colorOps !== '') {
            $ops .= $colorOps;
        }

        // Draw shadow shape
        if ($borderRadius && $this->hasSignificantRadius($borderRadius)) {
            $ops .= $this->buildRoundedRectPath($x, $y, $w, $h, $borderRadius);
        } else {
            $ops .= sprintf("%.3F %.3F %.3F %.3F re\n", $x, $y, $w, $h);
        }

        $ops .= "f\nQ\n"; // Fill and restore graphics state

        $this->pdf->appendToPageContent($ops);
    }

    /**
     * Render a blurred shadow by creating multiple layers.
     */
    private function renderBlurredShadow(
        float $x,
        float $y,
        float $w,
        float $h,
        string $color,
        float $alpha,
        float $blurRadius,
        ?array $borderRadius
    ): void {
        // Create blur effect by rendering multiple layers with decreasing opacity
        $layers = max(3, min(10, (int)($blurRadius / 2))); // Adaptive layer count
        $stepSize = $blurRadius / $layers;
        $baseAlpha = $alpha / $layers;

        for ($i = 0; $i < $layers; $i++) {
            $layerOffset = $stepSize * $i;
            $layerAlpha = $baseAlpha * (1 - ($i / $layers) * 0.5); // Fade out outer layers
            
            $layerX = $x - $layerOffset;
            $layerY = $y - $layerOffset;
            $layerW = $w + (2 * $layerOffset);
            $layerH = $h + (2 * $layerOffset);

            $this->renderSolidShadow($layerX, $layerY, $layerW, $layerH, $color, $layerAlpha, $borderRadius);
        }
    }

    /**
     * Build a rounded rectangle path.
     */
    private function buildRoundedRectPath(float $x, float $y, float $w, float $h, array $radius): string
    {
        // Ensure we have 4 radius values [top-left, top-right, bottom-right, bottom-left]
        if (!is_array($radius)) {
            $radius = array_fill(0, 4, (float)$radius);
        } elseif (count($radius) === 1) {
            $radius = array_fill(0, 4, (float)$radius[0]);
        } elseif (count($radius) === 2) {
            $radius = [$radius[0], $radius[1], $radius[0], $radius[1]];
        } elseif (count($radius) === 3) {
            $radius = [$radius[0], $radius[1], $radius[2], $radius[1]];
        } else {
            $radius = array_slice($radius, 0, 4);
        }

        $r1 = max(0, min((float)$radius[0], $w / 2, $h / 2)); // top-left
        $r2 = max(0, min((float)$radius[1], $w / 2, $h / 2)); // top-right
        $r3 = max(0, min((float)$radius[2], $w / 2, $h / 2)); // bottom-right
        $r4 = max(0, min((float)$radius[3], $w / 2, $h / 2)); // bottom-left

        $path = '';
        
        // Start from top-left corner
        $path .= sprintf("%.3F %.3F m\n", $x + $r1, $y + $h);
        
        // Top edge and top-right corner
        if ($r2 > 0) {
            $path .= sprintf("%.3F %.3F l\n", $x + $w - $r2, $y + $h);
            $path .= sprintf("%.3F %.3F %.3F %.3F %.3F %.3F c\n",
                $x + $w - $r2 * 0.552, $y + $h,
                $x + $w, $y + $h - $r2 * 0.552,
                $x + $w, $y + $h - $r2
            );
        } else {
            $path .= sprintf("%.3F %.3F l\n", $x + $w, $y + $h);
        }
        
        // Right edge and bottom-right corner
        if ($r3 > 0) {
            $path .= sprintf("%.3F %.3F l\n", $x + $w, $y + $r3);
            $path .= sprintf("%.3F %.3F %.3F %.3F %.3F %.3F c\n",
                $x + $w, $y + $r3 * 0.552,
                $x + $w - $r3 * 0.552, $y,
                $x + $w - $r3, $y
            );
        } else {
            $path .= sprintf("%.3F %.3F l\n", $x + $w, $y);
        }
        
        // Bottom edge and bottom-left corner
        if ($r4 > 0) {
            $path .= sprintf("%.3F %.3F l\n", $x + $r4, $y);
            $path .= sprintf("%.3F %.3F %.3F %.3F %.3F %.3F c\n",
                $x + $r4 * 0.552, $y,
                $x, $y + $r4 * 0.552,
                $x, $y + $r4
            );
        } else {
            $path .= sprintf("%.3F %.3F l\n", $x, $y);
        }
        
        // Left edge and top-left corner
        if ($r1 > 0) {
            $path .= sprintf("%.3F %.3F l\n", $x, $y + $h - $r1);
            $path .= sprintf("%.3F %.3F %.3F %.3F %.3F %.3F c\n",
                $x, $y + $h - $r1 * 0.552,
                $x + $r1 * 0.552, $y + $h,
                $x + $r1, $y + $h
            );
        } else {
            $path .= sprintf("%.3F %.3F l\n", $x, $y + $h);
        }
        
        $path .= "h\n"; // Close path
        
        return $path;
    }

    /**
     * Check if border radius has significant values.
     */
    private function hasSignificantRadius(?array $radius): bool
    {
        if (!is_array($radius)) {
            return false;
        }

        foreach ($radius as $r) {
            if ((float)$r > 0.1) {
                return true;
            }
        }

        return false;
    }
}
