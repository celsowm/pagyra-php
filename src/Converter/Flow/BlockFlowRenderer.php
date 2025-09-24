<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter\Flow;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Html\HtmlDocument;
use Celsowm\PagyraPhp\Html\Style\ComputedStyle;
use Celsowm\PagyraPhp\Css\CssGradientParser;
use Celsowm\PagyraPhp\Graphics\Painter\PdfBackgroundPainter;
use Celsowm\PagyraPhp\Graphics\Gradient\PdfGradientFactory;
use Celsowm\PagyraPhp\Graphics\Shading\PdfShadingRegistry;
use Celsowm\PagyraPhp\Block\PdfBlockBuilder;

final class BlockFlowRenderer
{
    public function __construct(
        private ParagraphBuilder $paragraphBuilder,
        private MarginCalculator $marginCalculator
    ) {}

    /**
     * @param array<string, mixed> $flow
     * @param array<string, ComputedStyle> $computedStyles
     */
    public function render(array $flow, PdfBuilder $pdf, HtmlDocument $document, array $computedStyles): void
    {
        $style = $flow['style'] ?? null;
        if (!($style instanceof ComputedStyle)) {
            return;
        }

        $paragraphOptions = $this->paragraphBuilder->buildParagraphOptions($style);
        $baseFontSize = (float)($paragraphOptions['size'] ?? 12.0);
        $runSpecs = is_array($flow['runs'] ?? null) ? $flow['runs'] : [];
        $runs = $this->paragraphBuilder->buildRunsFromFlow(
            $runSpecs,
            $computedStyles,
            $document,
            $this->paragraphBuilder->styleMarkersFromOptions($paragraphOptions),
            $baseFontSize
        );

        // Get block-level styling
        $margins = $this->marginCalculator->extractMarginBox($style, $baseFontSize);
        $padding = $this->marginCalculator->extractPaddingBox($style, $baseFontSize);

        $blockOptions = [
            'width'   => '100%',
            'padding' => [$padding['top'], $padding['right'], $padding['bottom'], $padding['left']],
            'margin'  => [$margins['top'], $margins['right'], $margins['bottom'], $margins['left']],
        ];

        $map = $style->toArray();
        $bgGradient = null;
        $painter = null;

        // Check for gradients
        $bgImageValue = $map['background-image'] ?? ($map['background'] ?? null);
        if (is_string($bgImageValue) && str_contains($bgImageValue, 'linear-gradient')) {
            $gp = new CssGradientParser();
            $bgGradient = $gp->parseLinear($bgImageValue);
        }

        if ($bgGradient !== null) {
            $blockOptions['bggradient'] = $bgGradient;
            $painter = new PdfBackgroundPainter($pdf, new PdfGradientFactory($pdf), new PdfShadingRegistry($pdf));
        } else {
            // Check for solid background color
            $bgColorValue = $map['background-color'] ?? ($map['background'] ?? null);
            if (
                is_string($bgColorValue) &&
                !str_contains($bgColorValue, 'gradient') &&
                strtolower($bgColorValue) !== 'transparent' &&
                strtolower($bgColorValue) !== 'none'
            ) {
                $blockOptions['bgcolor'] = $bgColorValue;
            }
        }

        // Use PdfBlockBuilder to render the block's container and background
        $block = new PdfBlockBuilder($pdf, $blockOptions, $painter);

        // Add the text content (runs), if any exist
        if ($runs !== []) {
            $block->addParagraphRuns($runs, $paragraphOptions);
        }

        $block->end();
    }
}
