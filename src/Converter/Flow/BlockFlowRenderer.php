<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter\Flow;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Font\Resolve\FontResolver;
use Celsowm\PagyraPhp\Html\HtmlDocument;
use Celsowm\PagyraPhp\Html\Style\ComputedStyle;
use Celsowm\PagyraPhp\Css\CssGradientParser;
use Celsowm\PagyraPhp\Graphics\Painter\PdfBackgroundPainter;
use Celsowm\PagyraPhp\Graphics\Gradient\PdfGradientFactory;
use Celsowm\PagyraPhp\Graphics\Shading\PdfShadingRegistry;
use Celsowm\PagyraPhp\Graphics\Shadow\PdfBoxShadowParser;
use Celsowm\PagyraPhp\Graphics\Shadow\PdfBoxShadowRenderer;
use Celsowm\PagyraPhp\Block\PdfBlockBuilder;
use Celsowm\PagyraPhp\Text\PdfRun;

final class BlockFlowRenderer
{
    public function __construct(
        private ParagraphBuilder $paragraphBuilder,
        private MarginCalculator $marginCalculator,
        private LengthConverter $lengthConverter,
        private FontResolver $fontResolver,
        private ImageFlowRenderer $imageFlowRenderer
    ) {}

    
    public function render(array $flow, PdfBuilder $pdf, HtmlDocument $document, array $computedStyles): void
    {
        $style = $flow['style'] ?? null;
        $imageResource = is_array($flow['image'] ?? null) ? $flow['image'] : null;
        if (!($style instanceof ComputedStyle)) {
            if ($imageResource === null && !is_array($flow['runs'] ?? null)) {
                return;
            }
            $style = new ComputedStyle();
        }

        $this->paragraphBuilder->beginFontContext($pdf, $this->fontResolver);

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

        $bgImageValue = $map['background-image'] ?? ($map['background'] ?? null);
        if (is_string($bgImageValue) && str_contains($bgImageValue, 'linear-gradient')) {
            $gp = new CssGradientParser();
            $bgGradient = $gp->parseLinear($bgImageValue);
        }

        if ($bgGradient !== null) {
            $blockOptions['bggradient'] = $bgGradient;
            $painter = new PdfBackgroundPainter($pdf, new PdfGradientFactory($pdf), new PdfShadingRegistry($pdf));
        } else {

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
        
        if (isset($map['text-align'])) {
            $align = strtolower($map['text-align']);
            if (in_array($align, ['left', 'right', 'center'], true)) {
                $blockOptions['align'] = $align;
            }
        }

        // Handle box-shadow
        $boxShadowValue = $map['box-shadow'] ?? null;
        $boxShadows = null;
        $borderRadius = null;
        if (is_string($boxShadowValue) && strtolower($boxShadowValue) !== 'none') {
            $shadowParser = new PdfBoxShadowParser();
            $boxShadows = $shadowParser->parse($boxShadowValue);

            // Extract border-radius for shadow rendering
            $borderRadiusValue = $map['border-radius'] ?? null;
            if (is_string($borderRadiusValue)) {
                $borderRadius = $this->parseBorderRadius($borderRadiusValue, $baseFontSize);
            }
        }

        // Add shadow options to block options
        if ($boxShadows !== null) {
            $blockOptions['boxShadows'] = $boxShadows;
            if ($borderRadius !== null) {
                $blockOptions['borderRadius'] = $borderRadius;
            }
        }

        // Add border-radius to block options for background rendering
        if ($borderRadius !== null) {
            $blockOptions['borderRadius'] = $borderRadius;
        }

        // Handle border
        if (isset($map['border']) && is_string($map['border']) && strtolower($map['border']) !== 'none') {
            $borderOptions = $this->parseBorderValue($map['border'], $baseFontSize);
            if ($borderOptions !== null) {
                $blockOptions['border'] = $borderOptions;
            }
        }

        $block = new PdfBlockBuilder($pdf, $blockOptions, $painter);

        // Handle image rendering using the new ImageFlowRenderer
        if ($imageResource !== null) {
            $this->imageFlowRenderer->render($block, $imageResource, $style, $flow, $baseFontSize, $pdf);
        }

        
        if ($runs !== []) {
            $block->addParagraphRuns($runs, $paragraphOptions);
        }

        
        if (!empty($flow['children']) && is_array($flow['children'])) {
            $this->renderChildFlows($flow['children'], $block, $pdf, $document, $computedStyles, $style, $baseFontSize);
        }

        $block->end();

        $this->paragraphBuilder->endFontContext();
    }

    /**
     * @param array<string, mixed> $tableFlow
     * @return array{data: array<int, array<int, string>>, options: array<string, mixed>}|null
     */
    private function mapTableFlowToData(array $tableFlow): ?array
    {
        $rows = $tableFlow['rows'] ?? null;
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $tableData = [];
        $headerRowIndex = null;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cellsSpec = $row['cells'] ?? null;
            if (!is_array($cellsSpec) || $cellsSpec === []) {
                continue;
            }

            $cells = [];
            foreach ($cellsSpec as $cell) {
                if (is_array($cell) && array_key_exists('text', $cell)) {
                    $text = $cell['text'];
                    $cells[] = is_string($text) ? $text : (string)$text;
                } else {
                    $cells[] = is_string($cell) ? $cell : (string)$cell;
                }
            }

            if ($cells === []) {
                continue;
            }

            $tableData[] = $cells;
            if (($row['isHeader'] ?? false) && $headerRowIndex === null) {
                $headerRowIndex = count($tableData) - 1;
            }
        }

        if ($tableData === []) {
            return null;
        }

        $options = [];
        if ($headerRowIndex !== null) {
            $options['headerRow'] = $headerRowIndex;
        }

        return ['data' => $tableData, 'options' => $options];
    }

    private function renderChildFlows(array $children, PdfBlockBuilder $parent, PdfBuilder $pdf, HtmlDocument $document, array $computedStyles, ComputedStyle $parentStyle, float $parentBaseFontSize): void
    {
        foreach ($children as $child) {
            $type = $child['type'] ?? 'block';
            if ($type === 'list') {
                $parent->addList($child['items'] ?? [], []);
                continue;
            }
            if ($type === 'table') {
                $tableSpec = $this->mapTableFlowToData($child);
                if ($tableSpec !== null) {
                    // Calculate adjusted width considering parent block padding
                    $adjustedWidth = null;
                    $childStyle = $child['style'] ?? null;
                    if ($childStyle instanceof ComputedStyle) {
                        $childBaseFontSize = (float)($this->paragraphBuilder->buildParagraphOptions($childStyle)['size'] ?? 12.0);
                        $padding = $this->marginCalculator->extractPaddingBox($childStyle, $childBaseFontSize);
                        $currentContentWidth = $pdf->getContentAreaWidth();

                        // The issue is that table padding is 0, we need to consider the parent block's padding
                        // Get parent block's padding from the current context
                        $parentPadding = $this->marginCalculator->extractPaddingBox($parentStyle, $parentBaseFontSize);

                        $adjustedWidth = $currentContentWidth - $parentPadding['left'] - $parentPadding['right'];
                        if ($adjustedWidth < 0) {
                            $adjustedWidth = $currentContentWidth;
                        }
                    }

                    $tableSpec['options']['adjustedWidth'] = $adjustedWidth;
                    $parent->addTable($tableSpec['data'], $tableSpec['options']);
                }
                continue;
            }

            
            $style = $child['style'] ?? null;
            $opts  = [];
            $painter = null;
            $paraOptions = [];
            $imageInstructionChild = null;

            if ($style instanceof ComputedStyle) {
                
                $paraOptions = $this->paragraphBuilder->buildParagraphOptions($style);
                $baseFontSize = (float)($paraOptions['size'] ?? 12.0);

                
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
                    if (
                        is_string($bgColorValue) &&
                        !str_contains($bgColorValue, 'gradient') &&
                        strtolower($bgColorValue) !== 'transparent' &&
                        strtolower($bgColorValue) !== 'none'
                    ) {
                        $opts['bgcolor'] = $bgColorValue;
                    }
                }

                $paraOptions = $this->paragraphBuilder->buildParagraphOptions($style);

                // Handle box-shadow for child elements
                $childBoxShadowValue = $map['box-shadow'] ?? null;
                $childBoxShadows = null;
                $childBorderRadius = null;
                if (is_string($childBoxShadowValue) && strtolower($childBoxShadowValue) !== 'none') {
                    $shadowParser = new PdfBoxShadowParser();
                    $childBoxShadows = $shadowParser->parse($childBoxShadowValue);

                    // Extract border-radius for shadow rendering
                    $childBorderRadiusValue = $map['border-radius'] ?? null;
                    if (is_string($childBorderRadiusValue)) {
                        $childBorderRadius = $this->parseBorderRadius($childBorderRadiusValue, $baseFontSize);
                    }
                }

                // Add shadow options to child block options
                if ($childBoxShadows !== null) {
                    $opts['boxShadows'] = $childBoxShadows;
                    if ($childBorderRadius !== null) {
                        $opts['borderRadius'] = $childBorderRadius;
                    }
                }

                // Add border-radius to child block options for background rendering
                if ($childBorderRadius !== null) {
                    $opts['borderRadius'] = $childBorderRadius;
                }

                // Handle border for child elements
                if (isset($map['border']) && is_string($map['border']) && strtolower($map['border']) !== 'none') {
                    $childBorderOptions = $this->parseBorderValue($map['border'], $baseFontSize);
                    if ($childBorderOptions !== null) {
                        $opts['border'] = $childBorderOptions;
                    }
                }
            }

            $childBaseFont = (float)($paraOptions['size'] ?? 12.0);
            $childImageResource = is_array($child['image'] ?? null) ? $child['image'] : null;
            if ($opts === []) {
                $opts = [
                    'width' => '100%',
                    'padding' => [0.0, 0.0, 0.0, 0.0],
                    'margin' => [0.0, 0.0, 0.0, 0.0],
                ];
            }
            $parent->addBlock($opts, function (PdfBlockBuilder $nested) use ($child, $document, $computedStyles, $paraOptions, $pdf, $childImageResource, $style, $baseFontSize) {
                $runSpecsChild = is_array($child['runs'] ?? null) ? $child['runs'] : [];
                $baseMarkers = $this->paragraphBuilder->styleMarkersFromOptions($paraOptions);
                $runsChild = $this->paragraphBuilder->buildRunsFromFlow(
                    $runSpecsChild,
                    $computedStyles,
                    $document,
                    $baseMarkers,
                    $baseFontSize
                );

                // Handle image rendering using the new ImageFlowRenderer
                if ($childImageResource !== null) {
                    $this->imageFlowRenderer->render($nested, $childImageResource, $style, $child, $baseFontSize, $pdf);
                }

                if ($runsChild !== []) {
                    $nested->addParagraphRuns($runsChild, $paraOptions);
                }

                if (!empty($child['children']) && is_array($child['children'])) {
                    $this->renderChildFlows($child['children'], $nested, $pdf, $document, $computedStyles, $style, $baseFontSize);
                }
            });
        }
    }

    private function parseCssLength(string $value, float $reference): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parsed = $this->lengthConverter->parseLengthOptional($value, $reference, 0.0);
        return $parsed > 0.0 ? $parsed : null;
    }

    /**
     * Parse CSS border shorthand value into border options array.
     * @return array{width: float, style: string, color: string}|null
     */
    private function parseBorderValue(string $value, float $baseFontSize): ?array
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'none') {
            return null;
        }

        // Split by spaces: width, style, color
        $parts = preg_split('/\s+/', $value);
        $parts = array_filter($parts, fn($p) => trim($p) !== '');
        $parts = array_values($parts);

        if (count($parts) < 2) {
            return null; // Need at least width and style
        }

        $width = null;
        $style = 'solid';
        $color = 'black';

        // Parse width (first part that matches length)
        foreach ($parts as $i => $part) {
            $parsedWidth = $this->parseCssLength($part, $baseFontSize);
            if ($parsedWidth !== null) {
                $width = $parsedWidth;
                array_splice($parts, $i, 1); // Remove this part
                break;
            }
        }

        if ($width === null) {
            return null; // No valid width found
        }

        // Check for style (solid, dashed, dotted)
        foreach ($parts as $i => $part) {
            $partLower = strtolower($part);
            if (in_array($partLower, ['solid', 'dashed', 'dotted'], true)) {
                $style = $partLower;
                array_splice($parts, $i, 1); // Remove this part
                break;
            }
        }

        // Remaining part should be color
        if (!empty($parts)) {
            $color = trim(implode(' ', $parts));
        }

        return [
            'width' => $width,
            'style' => $style,
            'color' => $color
        ];
    }

    /**
     * Parse CSS border-radius value into an array of radius values.
     */
    private function parseBorderRadius(string $value, float $baseFontSize): ?array
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'none') {
            return null;
        }

        // Split by spaces to get individual radius values
        $values = preg_split('/\s+/', $value);
        $values = array_filter($values, fn($v) => trim($v) !== '');
        $values = array_values($values);

        if (empty($values)) {
            return null;
        }

        // Parse each value
        $radii = [];
        foreach ($values as $val) {
            $parsed = $this->parseCssLength($val, $baseFontSize);
            if ($parsed === null) {
                return null; // If any value is invalid, return null
            }
            $radii[] = max(0.0, $parsed);
        }

        // CSS border-radius follows the same pattern as margin/padding:
        // 1 value: all corners
        // 2 values: top-left/bottom-right, top-right/bottom-left
        // 3 values: top-left, top-right/bottom-left, bottom-right
        // 4 values: top-left, top-right, bottom-right, bottom-left
        $count = count($radii);
        if ($count === 1) {
            return [$radii[0], $radii[0], $radii[0], $radii[0]];
        } elseif ($count === 2) {
            return [$radii[0], $radii[1], $radii[0], $radii[1]];
        } elseif ($count === 3) {
            return [$radii[0], $radii[1], $radii[2], $radii[1]];
        } else {
            return array_slice($radii, 0, 4);
        }
    }

}
