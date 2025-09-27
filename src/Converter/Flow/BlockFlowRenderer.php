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
        $__tag = $flow['tag'] ?? '?';
        $__id  = $flow['nodeId'] ?? '?';
        $__kids = is_array($flow['children'] ?? null) ? count($flow['children']) : 0;

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

        // Render nested children inside this block (if any)
        if (!empty($flow['children']) && is_array($flow['children'])) {
            $this->renderChildFlows($flow['children'], $block, $pdf, $document, $computedStyles);
        }

        $block->end();
    }


    private function renderChildFlows(array $children, PdfBlockBuilder $parent, PdfBuilder $pdf, HtmlDocument $document, array $computedStyles): void
    {
        foreach ($children as $child) {
            $type = $child['type'] ?? 'block';
            if ($type === 'list') {
                $parent->addList($child['items'] ?? [], []);
                continue;
            }
            if ($type === 'table') {
                $parent->addTable($child['rows'] ?? [], []);
                continue;
            }

            // type === 'block'
            $style = $child['style'] ?? null;
            $opts  = [];
            $painter = null;
            $paraOptions = [];

            if ($style instanceof ComputedStyle) {
                // CORREÇÃO: Calcular opções de parágrafo PRIMEIRO para obter o tamanho da fonte.
                $paraOptions = $this->paragraphBuilder->buildParagraphOptions($style);
                $baseFontSize = (float)($paraOptions['size'] ?? 12.0);

                // CORREÇÃO: Usar o tamanho da fonte correto para calcular margens e preenchimento.
                $margins = $this->marginCalculator->extractMarginBox($style, $baseFontSize);
                $padding = $this->marginCalculator->extractPaddingBox($style, $baseFontSize);
                $opts = [
                    'width'   => '100%',
                    'padding' => [$padding['top'], $padding['right'], $padding['bottom'], $padding['left']],
                    'margin'  => [$margins['top'], $margins['right'], $margins['bottom'], $margins['left']],
                ];

                $map = $style->toArray();
                $bgGradient = null;
                $bgImageValue = $map['background-image'] ?? ($map['background'] ?? null);
                if (is_string($bgImageValue) && str_contains($bgImageValue, 'linear-gradient')) {
                    $gp = new CssGradientParser();
                    $bgGradient = $gp->parseLinear($bgImageValue);
                }
                if ($bgGradient !== null) {
                    $opts['bggradient'] = $bgGradient;
                    $painter = new PdfBackgroundPainter($pdf, new PdfGradientFactory($pdf), new PdfShadingRegistry($pdf));
                } else {
                    $bgColorValue = $map['background-color'] ?? ($map['background'] ?? null);
                    if (is_string($bgColorValue) && preg_match('/^#?[0-9a-fA-F]{3,8}$/', $bgColorValue)) {
                        $opts['bgcolor'] = $bgColorValue;
                    }
                }

                $paraOptions = $this->paragraphBuilder->buildParagraphOptions($style);
            }

            $parent->addBlock($opts, function (PdfBlockBuilder $nested) use ($child, $document, $computedStyles, $paraOptions, $pdf) {
                $runSpecsChild = is_array($child['runs'] ?? null) ? $child['runs'] : [];
                $baseMarkers = $this->paragraphBuilder->styleMarkersFromOptions($paraOptions);
                $baseFontSize = (float)($paraOptions['size'] ?? 12.0);
                $runsChild = $this->paragraphBuilder->buildRunsFromFlow(
                    $runSpecsChild,
                    $computedStyles,
                    $document,
                    $baseMarkers,
                    $baseFontSize
                );
                if ($runsChild !== []) {
                    $nested->addParagraphRuns($runsChild, $paraOptions);
                }

                if (!empty($child['children']) && is_array($child['children'])) {
                    $this->renderChildFlows($child['children'], $nested, $pdf, $document, $computedStyles);
                }
            });
        }
    }
}
