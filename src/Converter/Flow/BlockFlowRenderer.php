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
use Celsowm\PagyraPhp\Block\PdfBlockBuilder;

final class BlockFlowRenderer
{
    public function __construct(
        private ParagraphBuilder $paragraphBuilder,
        private MarginCalculator $marginCalculator,
        private LengthConverter $lengthConverter,
        private FontResolver $fontResolver
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

        [$blockOptions, $imageInstruction] = $this->prepareImageRendering($blockOptions, $imageResource, $style, $flow, $baseFontSize);

        
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


        
        $block = new PdfBlockBuilder($pdf, $blockOptions, $painter);

        if ($imageInstruction !== null) {
            $this->renderImageInstruction($block, $imageInstruction);
        }

        
        if ($runs !== []) {
            $block->addParagraphRuns($runs, $paragraphOptions);
        }

        
        if (!empty($flow['children']) && is_array($flow['children'])) {
            $this->renderChildFlows($flow['children'], $block, $pdf, $document, $computedStyles);
        }

        $block->end();

        $this->paragraphBuilder->endFontContext();
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
                    if (is_string($bgColorValue) && preg_match('/^#|^rgb/', $bgColorValue)) {
                        $opts['bgcolor'] = $bgColorValue;
                    }
                }

                $paraOptions = $this->paragraphBuilder->buildParagraphOptions($style);
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
            [$opts, $imageInstructionChild] = $this->prepareImageRendering($opts, $childImageResource, $style instanceof ComputedStyle ? $style : null, $child, $childBaseFont);

            $parent->addBlock($opts, function (PdfBlockBuilder $nested) use ($child, $document, $computedStyles, $paraOptions, $pdf, $imageInstructionChild) {
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
                if ($imageInstructionChild !== null) {
                    $this->renderImageInstruction($nested, $imageInstructionChild);
                }
                if ($runsChild !== []) {
                    $nested->addParagraphRuns($runsChild, $paraOptions);
                }

                if (!empty($child['children']) && is_array($child['children'])) {
                    $this->renderChildFlows($child['children'], $nested, $pdf, $document, $computedStyles);
                }
            });
        }
    }
    
    private function prepareImageRendering(array $blockOptions, ?array $imageResource, ?ComputedStyle $style, array $flow, float $baseFontSize): array
    {
        if ($imageResource === null) {
            return [$blockOptions, null];
        }

        
        $finalWidth = null;
        $finalHeight = null;
        $aspectRatio = 1.0;

        if (isset($imageResource['width'], $imageResource['height']) && $imageResource['width'] > 0) {
            $aspectRatio = (float)$imageResource['height'] / (float)$imageResource['width'];
        }

        if ($style instanceof ComputedStyle) {
            $styleMap = $style->toArray();
            
            $widthValue = $styleMap['width'] ?? null;
            if (is_string($widthValue)) {
                $parsedWidth = $this->parseCssLength($widthValue, $baseFontSize);
                if ($parsedWidth !== null && $parsedWidth > 0) {
                    $finalWidth = $parsedWidth;
                }
            }
            
            $heightValue = $styleMap['height'] ?? null;
            if (is_string($heightValue) && strtolower($heightValue) !== 'auto') {
                $parsedHeight = $this->parseCssLength($heightValue, $baseFontSize);
                if ($parsedHeight !== null && $parsedHeight > 0) {
                    $finalHeight = $parsedHeight;
                }
            }
        }
        
        if ($finalWidth !== null) {
            $blockOptions['width'] = $finalWidth;
            if ($finalHeight === null) {
                
                $finalHeight = $finalWidth * $aspectRatio;
            }
        }
        
        if ($finalHeight !== null) {
            $blockOptions['minHeight'] = $finalHeight;
            $blockOptions['maxHeight'] = $finalHeight;
        }

        


        $type = strtolower((string)($imageResource['type'] ?? ''));
        if ($type === 'svg') {
            $textSpec = is_array($imageResource['text'] ?? null) ? $imageResource['text'] : [];
            if (!isset($blockOptions['bgcolor']) && isset($imageResource['background']) && is_string($imageResource['background']) && $imageResource['background'] !== '') {
                $blockOptions['bgcolor'] = $imageResource['background'];
            }
            
            
            if ($finalHeight !== null) {
                $textHeight = (float)($textSpec['fontSize'] ?? 12.0);
                $paddingY = max(0.0, ($finalHeight - $textHeight) / 2.0);
                
                if ($this->paddingIsEmpty($blockOptions['padding'])) {
                    $blockOptions['padding'] = [$paddingY, 0, $paddingY, 0];
                }
            }
            

            if (!isset($textSpec['align'])) {
                $textSpec['align'] = $this->inferImageAlignment($style, $flow);
            }
            if (!isset($textSpec['fontSize']) && isset($textSpec['size'])) {
                $textSpec['fontSize'] = (float)$textSpec['size'];
            }

            $instruction = [
                'type' => 'svg',
                'text' => $textSpec,
            ];

            return [$blockOptions, $instruction];
        }

        $alias = $imageResource['alias'] ?? null;
        if (!is_string($alias) || $alias === '') {
            return [$blockOptions, null];
        }

        $instruction = [
            'type' => 'bitmap',
            'alias' => $alias,
            'options' => $this->buildBitmapImageOptions($style, $flow, $baseFontSize, $imageResource),
        ];

        return [$blockOptions, $instruction];
    }

    private function renderImageInstruction(PdfBlockBuilder $block, array $instruction): void
    {
        $type = strtolower((string)($instruction['type'] ?? ''));
        if ($type === 'bitmap') {
            $alias = $instruction['alias'] ?? null;
            if (is_string($alias) && $alias !== '') {
                $block->addImage($alias, (array)($instruction['options'] ?? []));
            }
            return;
        }

        if ($type === 'svg') {
            $textSpec = is_array($instruction['text'] ?? null) ? $instruction['text'] : [];
            $content = (string)($textSpec['content'] ?? '');
            if ($content === '') {
                return;
            }

            $fontSize = (float)($textSpec['fontSize'] ?? 12.0);
            
            // --- START OF FINAL FIX ---
            // Ensure the extracted color from SVG fill is used. Default to white as a safe fallback for logos.
            $textColor = $textSpec['color'] ?? 'white';
            
            $paragraphOptions = [
                'align' => $textSpec['align'] ?? 'center',
                'size' => $fontSize,
                'lineHeight' => $fontSize, 
                'color' => $textColor,
            ];
            // --- END OF FINAL FIX ---
            
            $style = (string)($textSpec['style'] ?? '');
            if ($style !== '') {
                $paragraphOptions['style'] = $style;
            }

            
            $block->addParagraph($content, $paragraphOptions);
        }
    }

    
    private function buildBitmapImageOptions(?ComputedStyle $style, array $flow, float $baseFontSize, ?array $imageResource): array
    {
        $options = [];
        $options['align'] = $this->inferImageAlignment($style, $flow);

        $styleMap = $style instanceof ComputedStyle ? $style->toArray() : [];
        $attributes = $this->normalizeAttributes($flow['attributes'] ?? []);

        $width = null;
        if (isset($styleMap['width'])) {
            $width = $this->parseCssLength($styleMap['width'], $baseFontSize);
        }
        if ($width === null && isset($attributes['width'])) {
            $width = $this->parseAttributeLength($attributes['width']);
        }
        if ($width !== null && $width > 0) {
            $options['w'] = $width;
        }

        $height = null;
        if (isset($styleMap['height'])) {
            $height = $this->parseCssLength($styleMap['height'], $baseFontSize);
        }
        if ($height === null && isset($attributes['height'])) {
            $height = $this->parseAttributeLength($attributes['height']);
        }
        if ($height !== null && $height > 0) {
            $options['h'] = $height;
        }

        if (!isset($options['w']) && isset($styleMap['max-width'])) {
            $maxWidth = $this->parseCssLength($styleMap['max-width'], $baseFontSize);
            if ($maxWidth !== null && $maxWidth > 0) {
                $options['maxW'] = $maxWidth;
            }
        }

        if (isset($styleMap['max-height'])) {
            $maxHeight = $this->parseCssLength($styleMap['max-height'], $baseFontSize);
            if ($maxHeight !== null && $maxHeight > 0) {
                $options['maxH'] = $maxHeight;
            }
        }

        return $options;
    }

    private function inferImageAlignment(?ComputedStyle $style, array $flow): string
    {
        $align = 'left';
        if ($style instanceof ComputedStyle) {
            $map = $style->toArray();
            $candidate = strtolower((string)($map['text-align'] ?? ''));
            if (in_array($candidate, ['left', 'right', 'center'], true)) {
                $align = $candidate;
            }
        }

        $attributes = $this->normalizeAttributes($flow['attributes'] ?? []);
        if (isset($attributes['align'])) {
            $candidate = strtolower($attributes['align']);
            if (in_array($candidate, ['left', 'right', 'center'], true)) {
                $align = $candidate;
            }
        }

        return $align;
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

    private function parseAttributeLength(string $value): ?float
    {
        $trim = trim($value);
        if ($trim === '') {
            return null;
        }
        if (is_numeric($trim)) {
            return (float)$trim * 0.75;
        }
        if (preg_match('/^([0-9]*\.?[0-9]+)(px|pt)?$/i', $trim, $matches) === 1) {
            $number = (float)$matches[1];
            $unit = strtolower($matches[2] ?? '');
            return $unit === 'pt' ? $number : $number * 0.75;
        }

        return null;
    }

    private function normalizeAttributes(mixed $attributes): array
    {
        if (!is_array($attributes)) {
            return [];
        }

        $normalized = [];
        foreach ($attributes as $key => $value) {
            $normalized[strtolower((string)$key)] = is_string($value) ? $value : (string)$value;
        }

        return $normalized;
    }

    private function paddingIsEmpty(array $padding): bool
    {
        foreach ($padding as $value) {
            if (abs((float)$value) > 1e-6) {
                return false;
            }
        }

        return true;
    }

}