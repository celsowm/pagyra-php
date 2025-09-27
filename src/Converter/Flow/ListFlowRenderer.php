<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter\Flow;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Font\Resolve\FontResolver;
use Celsowm\PagyraPhp\Html\HtmlDocument;
use Celsowm\PagyraPhp\Html\Style\ComputedStyle;

final class ListFlowRenderer
{
    public function __construct(
        private ParagraphBuilder $paragraphBuilder,
        private MarginCalculator $marginCalculator,
        private FontResolver $fontResolver
    ) {
    }

    /**
     * @param array<string, mixed> $flow
     * @param array<string, ComputedStyle> $computedStyles
     */
    public function render(
        array $flow,
        PdfBuilder $pdf,
        array $computedStyles,
        HtmlDocument $document
    ): void {
        if (!is_array($flow['items'] ?? null) || ($flow['items'] ?? []) === []) {
            return;
        }

        $listTag = strtolower((string)($flow['tag'] ?? ''));
        if ($listTag !== 'ul' && $listTag !== 'ol') {
            $listTag = 'ul';
        }

        $style = $flow['style'] ?? null;
        if (!($style instanceof ComputedStyle)) {
            $nodeId = (string)($flow['nodeId'] ?? '');
            if ($nodeId !== '' && isset($computedStyles[$nodeId]) && $computedStyles[$nodeId] instanceof ComputedStyle) {
                $style = $computedStyles[$nodeId];
            } else {
                $style = null;
            }
        }

        $this->paragraphBuilder->beginFontContext($pdf, $this->fontResolver);
        try {
            $startByLevel = [];
            $conversion = $this->convertListSpec($flow, $computedStyles, $document, 0, $startByLevel);
            $items = $conversion['items'];
            if ($items === []) {
                return;
            }

            $typeByLevel = $conversion['types'];
            $options = $this->buildListOptions(
                $listTag,
                $style instanceof ComputedStyle ? $style : null,
                $typeByLevel,
                $startByLevel
            );

            $marginTop = (float)($options['marginTop'] ?? 0.0);
            $marginBottom = (float)($options['marginBottom'] ?? 0.0);
            unset($options['marginTop'], $options['marginBottom']);

            if ($marginTop > 0.0) {
                $pdf->addSpacer($marginTop);
            }

            $pdf->addList($items, $options);

            if ($marginBottom > 0.0) {
                $pdf->addSpacer($marginBottom);
            }
        } finally {
            $this->paragraphBuilder->endFontContext();
        }
    }

    /**
     * @param array<string, mixed> $listSpec
     * @param array<string, ComputedStyle> $computedStyles
     * @param array<int, int> $startByLevel
     * @return array{items: array<int, array<string, mixed>>, types: array<int, string>}
     */
    private function convertListSpec(
        array $listSpec,
        array $computedStyles,
        HtmlDocument $document,
        int $level,
        array &$startByLevel
    ): array {
        $tag = strtolower((string)($listSpec['tag'] ?? ''));
        if ($tag !== 'ul' && $tag !== 'ol') {
            $tag = 'ul';
        }
        $attributes = is_array($listSpec['attributes'] ?? null) ? $listSpec['attributes'] : [];
        $nodeId = (string)($listSpec['nodeId'] ?? '');
        $style = $listSpec['style'] ?? null;
        if (!($style instanceof ComputedStyle) && $nodeId !== '' && isset($computedStyles[$nodeId]) && $computedStyles[$nodeId] instanceof ComputedStyle) {
            $style = $computedStyles[$nodeId];
        }

        $listType = $this->detectListType($tag, $attributes, $style instanceof ComputedStyle ? $style : null);
        $types = [$level => $listType];

        if ($tag === 'ol') {
            $startValue = $this->parseListStart($attributes);
            if ($startValue !== null && !isset($startByLevel[$level])) {
                $startByLevel[$level] = $startValue;
            }
        }

        $items = [];
        $itemSpecs = is_array($listSpec['items'] ?? null) ? $listSpec['items'] : [];
        foreach ($itemSpecs as $itemSpec) {
            if (!is_array($itemSpec)) {
                continue;
            }
            $itemId = (string)($itemSpec['nodeId'] ?? '');
            $itemStyle = $itemSpec['style'] ?? null;
            if (!($itemStyle instanceof ComputedStyle) && $itemId !== '' && isset($computedStyles[$itemId]) && $computedStyles[$itemId] instanceof ComputedStyle) {
                $itemStyle = $computedStyles[$itemId];
            }

            $paragraphOptions = ($itemStyle instanceof ComputedStyle)
                ? $this->paragraphBuilder->buildParagraphOptions($itemStyle)
                : [];
            $baseMarkers = $this->paragraphBuilder->styleMarkersFromOptions($paragraphOptions);
            $baseFontSize = (float)($paragraphOptions['size'] ?? 12.0);
            $runSpecs = is_array($itemSpec['runs'] ?? null) ? $itemSpec['runs'] : [];
            $runs = $this->paragraphBuilder->buildRunsFromFlow(
                $runSpecs,
                $computedStyles,
                $document,
                $baseMarkers,
                $baseFontSize
            );

            $node = [
                'type' => $listType,
                'runs' => $runs,
            ];

            $childrenSpecs = $itemSpec['children'] ?? [];
            if (is_array($childrenSpecs) && $childrenSpecs !== []) {
                $childItems = [];
                foreach ($childrenSpecs as $childListSpec) {
                    if (!is_array($childListSpec)) {
                        continue;
                    }
                    $childTag = strtolower((string)($childListSpec['tag'] ?? ''));
                    if ($childTag !== 'ul' && $childTag !== 'ol') {
                        continue;
                    }
                    $childResult = $this->convertListSpec(
                        $childListSpec,
                        $computedStyles,
                        $document,
                        $level + 1,
                        $startByLevel
                    );
                    if ($childResult['items'] !== []) {
                        $childItems = array_merge($childItems, $childResult['items']);
                    }
                    foreach ($childResult['types'] as $childLevel => $childType) {
                        if (!isset($types[$childLevel])) {
                            $types[$childLevel] = $childType;
                        }
                    }
                }
                if ($childItems !== []) {
                    $node['children'] = $childItems;
                }
            }

            $items[] = $node;
        }

        return ['items' => $items, 'types' => $types];
    }

    /**
     * @param array<int, string> $typeByLevelMap
     * @param array<int, int> $startByLevel
     */
    private function buildListOptions(
        string $listTag,
        ?ComputedStyle $style,
        array $typeByLevelMap,
        array $startByLevel
    ): array {
        $options = [];
        $paragraph = [];
        $fontSize = 12.0;

        if ($style instanceof ComputedStyle) {
            $paragraph = $this->paragraphBuilder->buildParagraphOptions($style);
            if (isset($paragraph['align'])) {
                $options['align'] = $paragraph['align'];
            }
            if (isset($paragraph['lineHeight'])) {
                $options['lineHeight'] = $paragraph['lineHeight'];
            }
            if (isset($paragraph['size'])) {
                $fontSize = (float)$paragraph['size'];
            }
            $margin = $this->marginCalculator->extractMarginBox($style, $fontSize);
            if (abs($margin['left']) > 1e-6) {
                $options['indent'] = ($options['indent'] ?? 0.0) + $margin['left'];
            }
            if (abs($margin['top']) > 1e-6) {
                $options['marginTop'] = $margin['top'];
            }
            if (abs($margin['bottom']) > 1e-6) {
                $options['marginBottom'] = $margin['bottom'];
            }
        }

        if ($typeByLevelMap !== []) {
            ksort($typeByLevelMap);
            $normalized = [];
            $defaultType = ($listTag === 'ol') ? 'decimal' : 'bullet';
            $maxLevel = (int)max(array_keys($typeByLevelMap));
            for ($level = 0; $level <= $maxLevel; $level++) {
                if (isset($typeByLevelMap[$level])) {
                    $normalized[] = $typeByLevelMap[$level];
                } elseif ($level > 0 && isset($normalized[$level - 1])) {
                    $normalized[] = $normalized[$level - 1];
                } else {
                    $normalized[] = $defaultType;
                }
            }
            $options['typeByLevel'] = $normalized;
        } else {
            $options['typeByLevel'] = ($listTag === 'ol')
                ? ['decimal', 'lower-alpha', 'lower-roman', 'bullet']
                : ['bullet', 'bullet', 'bullet', 'bullet'];
        }

        $filteredStart = [];
        foreach ($startByLevel as $level => $startValue) {
            if (is_numeric($startValue)) {
                $intValue = (int)$startValue;
                if ($intValue > 0) {
                    $filteredStart[(int)$level] = $intValue;
                }
            }
        }
        if ($filteredStart !== []) {
            $options['startByLevel'] = $filteredStart;
        }

        return $options;
    }

    private function detectListType(string $tag, array $attributes, ?ComputedStyle $style): string
    {
        $map = $style instanceof ComputedStyle ? $style->toArray() : [];
        $cssType = strtolower((string)($map['list-style-type'] ?? ''));
        $attrRaw = (string)($attributes['type'] ?? '');
        $attrLower = strtolower($attrRaw);

        $candidate = $cssType !== '' ? $cssType : $attrLower;

        if ($tag === 'ol') {
            return $this->normalizeOrderedListType($candidate, $attrRaw);
        }

        $unordered = $this->normalizeUnorderedListType($candidate);
        if ($unordered !== null) {
            return $unordered;
        }

        return $this->normalizeOrderedListType($candidate, $attrRaw);
    }

    private function normalizeOrderedListType(string $candidate, string $raw): string
    {
        if ($candidate === 'lower-alpha' || $candidate === 'a' || $candidate === 'lower-latin') {
            return 'lower-alpha';
        }
        if ($candidate === 'upper-alpha' || $candidate === 'upper-latin' || $raw === 'A') {
            return 'upper-alpha';
        }
        if ($candidate === 'lower-roman' || $candidate === 'i') {
            return 'lower-roman';
        }
        if ($candidate === 'upper-roman' || $raw === 'I') {
            return 'upper-roman';
        }
        if ($candidate === 'decimal-leading-zero' || $candidate === 'decimal' || $candidate === '1') {
            return 'decimal';
        }

        return 'decimal';
    }

    private function normalizeUnorderedListType(string $candidate): ?string
    {
        if (
            $candidate === ''
            || $candidate === 'disc'
            || $candidate === 'circle'
            || $candidate === 'square'
            || $candidate === 'bullet'
        ) {
            return 'bullet';
        }
        if ($candidate === 'none') {
            return 'bullet';
        }

        return null;
    }

    private function parseListStart(array $attributes): ?int
    {
        $raw = $attributes['start'] ?? null;
        if ($raw === null) {
            return null;
        }
        if (is_numeric($raw)) {
            $value = (int)$raw;
            return $value > 0 ? $value : null;
        }
        if (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            $value = (int)$raw;
            return $value > 0 ? $value : null;
        }

        return null;
    }
}
