<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter\Flow;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Font\Resolve\FontResolver;
use Celsowm\PagyraPhp\Html\Style\ComputedStyle;
use Celsowm\PagyraPhp\Block\PdfBlockBuilder;
use Celsowm\PagyraPhp\Text\PdfRun;

final class ImageFlowRenderer
{
    public function __construct(
        private LengthConverter $lengthConverter,
        private FontResolver $fontResolver,
        private ParagraphBuilder $paragraphBuilder
    ) {}

    public function render(PdfBlockBuilder $block, array $imageResource, ?ComputedStyle $style, array $flow, float $baseFontSize, PdfBuilder $pdf): void
    {
        // 1. Calculate image dimensions and options from style and attributes
        $options = $this->calculateImageOptions($style, $flow, $baseFontSize, $imageResource);

        // 2. Handle different image types
        $type = strtolower((string)($imageResource['type'] ?? ''));

        if ($type === 'bitmap') {
            $alias = $imageResource['alias'] ?? null;
            if ($alias) {
                $block->addImage($alias, $options);
            }
        } elseif ($type === 'svg') {
            // Logic to render the SVG placeholder text and background
            $this->renderSvgPlaceholder($block, $imageResource, $options, $pdf);
        }
    }

    private function calculateImageOptions(?ComputedStyle $style, array $flow, float $baseFontSize, array $imageResource): array
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

    private function renderSvgPlaceholder(PdfBlockBuilder $block, array $imageResource, array $imageOptions, PdfBuilder $pdf): void
    {
        $textSpec = is_array($imageResource['text'] ?? null) ? $imageResource['text'] : [];
        $content = (string)($textSpec['content'] ?? '');
        if ($content === '') {
            return;
        }

        $fontSize = (float)($textSpec['fontSize'] ?? 12.0);
        $textColor = $textSpec['color'] ?? 'white';
        $styleValue = trim((string)($textSpec['style'] ?? ''));

        $paragraphOptions = [
            'align' => $textSpec['align'] ?? 'center',
            'size' => $fontSize,
            'lineHeight' => $fontSize,
        ];

        $runOptions = [
            'size' => $fontSize,
            'color' => $textColor,
        ];

        $markers = $this->paragraphBuilder->styleMarkersFromOptions(['style' => $styleValue]);
        $styleMarkers = $this->paragraphBuilder->markersToStyleString($markers);
        if ($styleMarkers !== '') {
            $runOptions['style'] = $styleMarkers;
        }

        $fontFamily = trim((string)($textSpec['fontFamily'] ?? ''));
        if ($fontFamily !== '') {
            $alias = $this->fontResolver->resolve($pdf, $fontFamily, $styleMarkers);
            if ($alias !== null) {
                $runOptions['fontAlias'] = $alias;
            }
        }

        $block->addParagraphRuns([new PdfRun($content, $runOptions)], $paragraphOptions);
    }

    private function inferImageAlignment(?ComputedStyle $style, array $flow): string
    {
        $align = 'left';
        if ($style instanceof ComputedStyle) {
            $map = $style->toArray();

            if ($this->styleHasAutoHorizontalMargins($map)) {
                $align = 'center';
            }

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

    private function styleHasAutoHorizontalMargins(array $styleMap): bool
    {
        $left = $this->normalizeMarginKeyword($styleMap['margin-left'] ?? null);
        $right = $this->normalizeMarginKeyword($styleMap['margin-right'] ?? null);
        if ($left === 'auto' && $right === 'auto') {
            return true;
        }

        $margin = $styleMap['margin'] ?? null;
        if (!is_string($margin)) {
            return false;
        }

        $tokens = preg_split('/\s+/', trim($margin)) ?: [];
        $tokens = array_values(array_filter(array_map('trim', $tokens), static fn($token) => $token !== ''));
        if ($tokens === []) {
            return false;
        }

        $count = count($tokens);
        if ($count === 1) {
            $tokens = [$tokens[0], $tokens[0], $tokens[0], $tokens[0]];
        } elseif ($count === 2) {
            $tokens = [$tokens[0], $tokens[1], $tokens[0], $tokens[1]];
        } elseif ($count === 3) {
            $tokens = [$tokens[0], $tokens[1], $tokens[2], $tokens[1]];
        } else {
            $tokens = array_slice($tokens, 0, 4);
        }

        return strtolower($tokens[1]) === 'auto' && strtolower($tokens[3]) === 'auto';
    }

    private function normalizeMarginKeyword(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        return $normalized === '' ? null : $normalized;
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
}
