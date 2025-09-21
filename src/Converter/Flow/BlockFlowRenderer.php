<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter\Flow;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Html\HtmlDocument;
use Celsowm\PagyraPhp\Html\Style\ComputedStyle;

final class BlockFlowRenderer
{
    public function __construct(
        private ParagraphBuilder $paragraphBuilder,
        private MarginCalculator $marginCalculator
    ) {
    }

    /**
     * @param array<string, mixed> $flow
     * @param array<string, ComputedStyle> $computedStyles
     */
    public function render(
        array $flow,
        PdfBuilder $pdf,
        HtmlDocument $document,
        array $computedStyles
    ): void {
        $style = $flow['style'] ?? null;
        $paragraphOptions = ($style instanceof ComputedStyle)
            ? $this->paragraphBuilder->buildParagraphOptions($style)
            : [];

        $baseMarkers = $this->paragraphBuilder->styleMarkersFromOptions($paragraphOptions);
        $baseFontSize = (float)($paragraphOptions['size'] ?? 12.0);
        $runSpecs = is_array($flow['runs'] ?? null) ? $flow['runs'] : [];
        $runs = $this->paragraphBuilder->buildRunsFromFlow(
            $runSpecs,
            $computedStyles,
            $document,
            $baseMarkers,
            $baseFontSize
        );

        if ($runs === []) {
            return;
        }

        $marginTop = 0.0;
        $marginBottom = 0.0;
        if ($style instanceof ComputedStyle) {
            $margins = $this->marginCalculator->extractMarginBox($style, $baseFontSize);
            if ($margins['left'] > 0.0) {
                $indent = ($paragraphOptions['indent'] ?? 0.0) + $margins['left'];
                $paragraphOptions['indent'] = $indent;
                $paragraphOptions['hangIndent'] = $paragraphOptions['hangIndent'] ?? $indent;
                if ($paragraphOptions['hangIndent'] < $indent) {
                    $paragraphOptions['hangIndent'] = $indent;
                }
            }
            if ($margins['top'] > 0.0) {
                $marginTop = $margins['top'];
            }
            if ($margins['bottom'] > 0.0) {
                $marginBottom = $margins['bottom'];
            }
        }

        if ($marginTop > 0.0) {
            $pdf->addSpacer($marginTop);
        }

        $pdf->addParagraphRuns($runs, $paragraphOptions);

        if ($marginBottom > 0.0) {
            $pdf->addSpacer($marginBottom);
        }
    }
}
