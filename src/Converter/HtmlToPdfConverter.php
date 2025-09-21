<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Css\CssParser;
use Celsowm\PagyraPhp\Html\HtmlParser;
use Celsowm\PagyraPhp\Html\HtmlDocument;
use Celsowm\PagyraPhp\Html\Style\CssCascade;
use Celsowm\PagyraPhp\Html\Style\ComputedStyle;
use Celsowm\PagyraPhp\Html\Style\Layout\FlowComposer;
use Celsowm\PagyraPhp\Text\PdfRun;

final class HtmlToPdfConverter
{
    private HtmlParser $htmlParser;
    private CssParser $cssParser;
    private CssCascade $cssCascade;
    private FlowComposer $flowComposer;

    public function __construct(
        ?HtmlParser $htmlParser = null,
        ?CssParser $cssParser = null,
        ?CssCascade $cssCascade = null,
        ?FlowComposer $flowComposer = null
    ) {
        $this->htmlParser = $htmlParser ?? new HtmlParser();
        $this->cssParser = $cssParser ?? new CssParser();
        $this->cssCascade = $cssCascade ?? new CssCascade();
        $this->flowComposer = $flowComposer ?? new FlowComposer();
    }

    public function convert(string $html, PdfBuilder $pdf, ?string $css = null): void
    {
        $document = $this->htmlParser->parse($html);
        $stylesheet = $css ?? $this->extractEmbeddedCss($document);
        $stylesheet = $this->mergeWithDefaultStylesheet($stylesheet);
        $cssOm = $this->cssParser->parse($stylesheet);
        $computedStyles = $this->cssCascade->compute($document, $cssOm);
        $flows = $this->flowComposer->compose($document, $computedStyles);

        foreach ($flows as $flow) {
            $type = $flow['type'] ?? '';
            if ($type === 'table') {
                $this->renderTableFlow($flow, $pdf, $computedStyles);
                continue;
            }
            if ($type === 'list') {
                $this->renderListFlow($flow, $pdf, $computedStyles, $document);
                continue;
            }
            if ($type !== 'block') {
                continue;
            }

            $style = $flow['style'] ?? null;
            $paragraphOptions = ($style instanceof ComputedStyle)
                ? $this->buildParagraphOptions($style)
                : [];
            $baseMarkers = $this->styleMarkersFromOptions($paragraphOptions);
            $baseFontSize = (float)($paragraphOptions['size'] ?? 12.0);
            $runs = $this->buildRunsFromFlow(
                $flow['runs'] ?? [],
                $computedStyles,
                $document,
                $baseMarkers,
                $baseFontSize
            );

            if ($runs === []) {
                continue;
            }

            $marginTop = 0.0;
            $marginBottom = 0.0;
            if ($style instanceof ComputedStyle) {
                $margins = $this->extractMarginBox($style, $baseFontSize);
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

    private function buildParagraphOptions(ComputedStyle $style): array
    {
        $options = [];
        $map = $style->toArray();

        if (isset($map['text-align'])) {
            $align = strtolower($map['text-align']);
            if (in_array($align, ['left', 'right', 'center', 'justify'], true)) {
                $options['align'] = $align;
            }
        }

        if (isset($map['font-size']) && strtolower($map['font-size']) !== 'inherit') {
            $options['size'] = $this->parseLength($map['font-size'], 12.0);
        }

        if (isset($map['line-height'])) {
            $fontSize = (float)($options['size'] ?? 12.0);
            $options['lineHeight'] = $this->parseLengthOptional($map['line-height'], $fontSize, 1.2 * $fontSize, true);
        }

        if (isset($map['color']) && strtolower($map['color']) !== 'inherit') {
            $options['color'] = $map['color'];
        }

        if (isset($map['font-weight'])) {
            $weight = strtolower($map['font-weight']);
            if ($weight === 'bold' || $weight === 'bolder' || (is_numeric($map['font-weight']) && (int)$map['font-weight'] >= 600)) {
                $options['style'] = 'bold';
            }
        }

        if (isset($map['letter-spacing'])) {
            $fontSize = (float)($options['size'] ?? 12.0);
            $options['letterSpacing'] = $this->parseLengthOptional($map['letter-spacing'], $fontSize, 0.0);
        }

        if (isset($map['word-spacing'])) {
            $fontSize = (float)($options['size'] ?? 12.0);
            $options['wordSpacing'] = $this->parseLengthOptional($map['word-spacing'], $fontSize, 0.0);
        }

        if (isset($map['text-decoration']) && str_contains(strtolower($map['text-decoration']), 'underline')) {
            $options['style'] = $this->appendStyleMarker($options['style'] ?? '', 'underline');
        }

        return $options;
    }

    private function parseLength(string $value, float $default): float
    {
        return $this->convertLength($value, $default, $default);
    }

    private function parseLengthOptional(string $value, float $reference, float $fallback, bool $unitlessIsMultiplier = false): float
    {
        return $this->convertLength($value, $reference, $fallback, $unitlessIsMultiplier);
    }

    private function convertLength(string $value, float $reference, float $fallback, bool $unitlessIsMultiplier = false): float
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'normal') {
            return $fallback;
        }
        if (is_numeric($value)) {
            $number = (float)$value;
            return $unitlessIsMultiplier ? $number * $reference : $number;
        }
        if (preg_match('/^([0-9]*\.?[0-9]+)(px|pt|em|rem)?$/i', $value, $m) === 1) {
            $number = (float)$m[1];
            $unit = strtolower($m[2] ?? '');
            if ($unit === '') {
                return $unitlessIsMultiplier ? $number * $reference : $number;
            }
            return match ($unit) {
                'px' => $number * 0.75,
                'em', 'rem' => $number * $reference,
                default => $number,
            };
        }
        return $fallback;
    }
    private function buildRunsFromFlow(
        array $runSpecs,
        array $computedStyles,
        HtmlDocument $document,
        array $baseMarkers,
        float $baseFontSize
    ): array {
        $runs = [];
        foreach ($runSpecs as $spec) {
            $text = (string)($spec['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $styleChain = $spec['styleChain'] ?? [];
            $linkNodeId = $spec['linkNodeId'] ?? null;
            $options = $this->buildRunOptions($styleChain, $computedStyles, $document, $baseMarkers, $baseFontSize, $linkNodeId);
            $runs[] = new PdfRun($text, $options);
        }
        return $runs;
    }

    private function buildRunOptions(
        array $styleChain,
        array $computedStyles,
        HtmlDocument $document,
        array $baseMarkers,
        float $baseFontSize,
        ?string $linkNodeId
    ): array {
        $styleMap = $this->mergeStyleMapChain($styleChain, $computedStyles);
        $markers = $baseMarkers;
        $options = $this->mapInlineStyleMapToRunOptions($styleMap, $markers, $baseFontSize);
        $href = $this->extractHrefFromChain($styleChain, $document, $linkNodeId);
        if ($href !== null) {
            $options['href'] = $href;
        }
        $styleString = $this->markersToStyleString($markers);
        if ($styleString !== '' || $this->hasStyleDirective($styleMap) || $href !== null) {
            $options['style'] = $styleString;
        }
        return $options;
    }

    private function renderListFlow(array $flow, PdfBuilder $pdf, array $computedStyles, HtmlDocument $document): void
    {
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

            $paragraphOptions = ($itemStyle instanceof ComputedStyle) ? $this->buildParagraphOptions($itemStyle) : [];
            $baseMarkers = $this->styleMarkersFromOptions($paragraphOptions);
            $baseFontSize = (float)($paragraphOptions['size'] ?? 12.0);
            $runSpecs = is_array($itemSpec['runs'] ?? null) ? $itemSpec['runs'] : [];
            $runs = $this->buildRunsFromFlow($runSpecs, $computedStyles, $document, $baseMarkers, $baseFontSize);

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
                    $childResult = $this->convertListSpec($childListSpec, $computedStyles, $document, $level + 1, $startByLevel);
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
            $paragraph = $this->buildParagraphOptions($style);
            if (isset($paragraph['align'])) {
                $options['align'] = $paragraph['align'];
            }
            if (isset($paragraph['lineHeight'])) {
                $options['lineHeight'] = $paragraph['lineHeight'];
            }
            if (isset($paragraph['size'])) {
                $fontSize = (float)$paragraph['size'];
            }
            $margin = $this->extractMarginBox($style, $fontSize);
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

    /**
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    private function extractMarginBox(ComputedStyle $style, float $reference): array
    {
        $map = $style->toArray();
        $margins = ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0];

        if (isset($map['margin'])) {
            $rawValues = preg_split('/\s+/', trim((string)$map['margin'])) ?: [];
            $values = [];
            foreach ($rawValues as $value) {
                $value = trim((string)$value);
                if ($value === '') {
                    continue;
                }
                $values[] = $value;
            }
            if ($values !== []) {
                $count = count($values);
                if ($count === 1) {
                    $values = array_fill(0, 4, $values[0]);
                } elseif ($count === 2) {
                    $values = [$values[0], $values[1], $values[0], $values[1]];
                } elseif ($count === 3) {
                    $values = [$values[0], $values[1], $values[2], $values[1]];
                } else {
                    $values = array_slice($values, 0, 4);
                }
                $sides = ['top', 'right', 'bottom', 'left'];
                foreach ($sides as $index => $side) {
                    $margins[$side] = $this->parseMarginComponent($values[$index], $reference, $margins[$side]);
                }
            }
        }

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $prop = 'margin-' . $side;
            if (!isset($map[$prop])) {
                continue;
            }
            $margins[$side] = $this->parseMarginComponent($map[$prop], $reference, $margins[$side]);
        }

        return $margins;
    }

    private function parseMarginComponent(string $value, float $reference, float $fallback): float
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || $normalized === 'auto' || $normalized === 'inherit') {
            return $fallback;
        }

        return $this->parseLengthOptional($value, $reference, $fallback);
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
        if ($candidate === '' || $candidate === 'disc' || $candidate === 'circle' || $candidate === 'square' || $candidate === 'bullet') {
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


    private function mergeStyleMapChain(array $styleChain, array $computedStyles): array
    {
        $merged = [];
        foreach ($styleChain as $nodeId) {
            if (!isset($computedStyles[$nodeId])) {
                continue;
            }
            foreach ($computedStyles[$nodeId]->toArray() as $prop => $value) {
                $merged[strtolower($prop)] = $value;
            }
        }
        return $merged;
    }

    private function mapInlineStyleMapToRunOptions(array $styleMap, array &$markers, float $baseFontSize): array
    {
        $options = [];

        if (isset($styleMap['color']) && strtolower($styleMap['color']) !== 'inherit') {
            $options['color'] = $styleMap['color'];
        }
        if (isset($styleMap['background-color']) && strtolower($styleMap['background-color']) !== 'transparent') {
            $options['bgcolor'] = $styleMap['background-color'];
        }

        if (isset($styleMap['font-weight'])) {
            $weight = strtolower($styleMap['font-weight']);
            if ($weight === 'normal' || $weight === 'lighter') {
                $markers['bold'] = false;
            } elseif ($weight === 'bold' || $weight === 'bolder' || (is_numeric($styleMap['font-weight']) && (int)$styleMap['font-weight'] >= 600)) {
                $markers['bold'] = true;
            }
        }

        if (isset($styleMap['font-style'])) {
            $fontStyle = strtolower($styleMap['font-style']);
            if ($fontStyle === 'normal') {
                $markers['italic'] = false;
            } elseif (in_array($fontStyle, ['italic', 'oblique'], true)) {
                $markers['italic'] = true;
            }
        }

        if (isset($styleMap['text-decoration'])) {
            $decoration = strtolower($styleMap['text-decoration']);
            if ($decoration === 'none') {
                $markers['underline'] = false;
            } elseif (str_contains($decoration, 'underline')) {
                $markers['underline'] = true;
            }
        }

        if (isset($styleMap['text-decoration-line'])) {
            $line = strtolower($styleMap['text-decoration-line']);
            if ($line === 'none') {
                $markers['underline'] = false;
            } elseif (str_contains($line, 'underline')) {
                $markers['underline'] = true;
            }
        }

        if (isset($styleMap['font-size']) && strtolower($styleMap['font-size']) !== 'inherit') {
            $options['size'] = $this->parseLengthOptional($styleMap['font-size'], $baseFontSize, $baseFontSize);
        }

        if (isset($styleMap['line-height'])) {
            $ref = (float)($options['size'] ?? $baseFontSize);
            $options['lineHeight'] = $this->parseLengthOptional($styleMap['line-height'], $ref, 1.2 * $ref, true);
        }

        $refSize = (float)($options['size'] ?? $baseFontSize);
        if (isset($styleMap['letter-spacing'])) {
            $options['letterSpacing'] = $this->parseLengthOptional($styleMap['letter-spacing'], $refSize, 0.0);
        }

        if (isset($styleMap['word-spacing'])) {
            $options['wordSpacing'] = $this->parseLengthOptional($styleMap['word-spacing'], $refSize, 0.0);
        }

        if (isset($styleMap['vertical-align'])) {
            $valign = strtolower($styleMap['vertical-align']);
            if ($valign === 'super' || $valign === 'top') {
                $options['script'] = 'sup';
            } elseif ($valign === 'sub' || $valign === 'bottom') {
                $options['script'] = 'sub';
            }
        }

        return $options;
    }

    private function extractHrefFromChain(array $styleChain, HtmlDocument $document, ?string $linkNodeId): ?string
    {
        $candidates = $styleChain;
        if ($linkNodeId !== null) {
            $candidates[] = $linkNodeId;
        }
        for ($i = count($candidates) - 1; $i >= 0; $i--) {
            $element = $document->getElement($candidates[$i]);
            if ($element === null) {
                continue;
            }
            if (strtolower((string)($element['tag'] ?? '')) !== 'a') {
                continue;
            }
            $href = $element['attributes']['href'] ?? null;
            if ($href === null) {
                continue;
            }
            $trim = trim((string)$href);
            if ($trim !== '') {
                return $trim;
            }
        }
        return null;
    }

    private function styleMarkersFromOptions(array $options): array
    {
        $markers = ['bold' => false, 'italic' => false, 'underline' => false];
        $raw = trim((string)($options['style'] ?? ''));
        if ($raw === '') {
            return $markers;
        }

        $lettersOnly = preg_replace('/[^A-Za-z]/', '', $raw);
        if ($lettersOnly !== '' && preg_match('/^[BIUbiu]+$/', $lettersOnly) === 1) {
            foreach (str_split(strtoupper($lettersOnly)) as $letter) {
                if ($letter === 'B') {
                    $markers['bold'] = true;
                } elseif ($letter === 'I') {
                    $markers['italic'] = true;
                } elseif ($letter === 'U') {
                    $markers['underline'] = true;
                }
            }
            return $markers;
        }

        $tokens = preg_split('/[\s,;]+/', strtolower($raw)) ?: [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if ($token === 'bold' || $token === 'b') {
                $markers['bold'] = true;
                continue;
            }
            if ($token === 'italic' || $token === 'i') {
                $markers['italic'] = true;
                continue;
            }
            if ($token === 'underline' || $token === 'u') {
                $markers['underline'] = true;
                continue;
            }

            if (str_contains($token, 'bold')) {
                $markers['bold'] = true;
            }
            if (str_contains($token, 'italic') || str_contains($token, 'oblique')) {
                $markers['italic'] = true;
            }
            if (str_contains($token, 'underline')) {
                $markers['underline'] = true;
            }
        }

        return $markers;
    }

    private function markersToStyleString(array $markers): string
    {
        $letters = '';
        if (!empty($markers['bold'])) {
            $letters .= 'B';
        }
        if (!empty($markers['italic'])) {
            $letters .= 'I';
        }
        if (!empty($markers['underline'])) {
            $letters .= 'U';
        }
        return $letters;
    }

    private function hasStyleDirective(array $styleMap): bool
    {
        foreach (['font-weight', 'font-style', 'text-decoration', 'text-decoration-line'] as $key) {
            if (array_key_exists($key, $styleMap)) {
                return true;
            }
        }
        return false;
    }

    private function appendStyleMarker(string $original, string $marker): string
    {
        $markers = $this->styleMarkersFromOptions(['style' => $original]);
        $normalized = strtolower($marker);
        if ($normalized === 'bold' || $normalized === 'b') {
            $markers['bold'] = true;
        } elseif ($normalized === 'italic' || $normalized === 'i' || $normalized === 'oblique') {
            $markers['italic'] = true;
        } elseif ($normalized === 'underline' || $normalized === 'u') {
            $markers['underline'] = true;
        } else {
            $letter = strtoupper($marker)[0] ?? null;
            if ($letter === 'B') {
                $markers['bold'] = true;
            } elseif ($letter === 'I') {
                $markers['italic'] = true;
            } elseif ($letter === 'U') {
                $markers['underline'] = true;
            }
        }
        return $this->markersToStyleString($markers);
    }

    private function renderTableFlow(array $flow, PdfBuilder $pdf, array $computedStyles): void
    {
        $rows = $flow['rows'] ?? [];
        if ($rows === []) {
            return;
        }

        $tableData = [];
        $headerRowIndex = null;
        $headerMarkers = ['bold' => false, 'italic' => false, 'underline' => false];
        $headerBgCandidates = [];
        $headerColorCandidates = [];

        $tableStyle = $flow['style'] ?? null;
        $tableFontSize = 12.0;
        $tableFontSizeDeclared = false;
        $tableLineHeight = null;
        $tableLineHeightDeclared = false;

        if ($tableStyle instanceof ComputedStyle) {
            $map = $tableStyle->toArray();
            if (isset($map['font-size']) && strtolower($map['font-size']) !== 'inherit') {
                $tableFontSize = $this->parseLengthOptional($map['font-size'], 12.0, 12.0);
                $tableFontSizeDeclared = true;
            }
            if (isset($map['line-height'])) {
                $tableLineHeight = $this->parseLengthOptional($map['line-height'], $tableFontSize, 1.2 * $tableFontSize, true);
                $tableLineHeightDeclared = true;
            }
        }

        foreach ($rows as $index => $row) {
            if (!isset($row['cells'])) {
                continue;
            }
            $isHeader = !empty($row['isHeader']);
            $cells = [];
            foreach ($row['cells'] as $cell) {
                if (is_array($cell)) {
                    $text = is_string($cell['text'] ?? null) ? $cell['text'] : (string)($cell['text'] ?? '');
                    $nodeId = (string)($cell['nodeId'] ?? '');
                    $styleMap = ($nodeId !== '' && isset($computedStyles[$nodeId]))
                        ? $computedStyles[$nodeId]->toArray()
                        : [];
                    $cellOptions = $this->mapCellStyleToOptions($styleMap, $tableFontSize);
                    if ($cellOptions !== []) {
                        $cells[] = [
                            'text' => $text,
                            'options' => $cellOptions,
                        ];
                    } else {
                        $cells[] = $text;
                    }
                    if ($isHeader && $styleMap !== []) {
                        $this->collectHeaderStyleData($styleMap, $tableFontSize, $headerMarkers, $headerBgCandidates, $headerColorCandidates);
                    }
                } else {
                    $cells[] = is_string($cell) ? $cell : (string)$cell;
                }
            }
            $tableData[] = $cells;
            if ($isHeader && $headerRowIndex === null) {
                $headerRowIndex = $index;
            }
        }

        if ($tableData === []) {
            return;
        }

        $options = [];
        if ($headerRowIndex !== null) {
            $options['headerRow'] = $headerRowIndex;
            $headerStyle = $this->markersToStyleString($headerMarkers);
            if ($headerStyle !== '') {
                $options['headerStyle'] = $headerStyle;
            }
            $headerBg = $this->pickUniformColor($headerBgCandidates);
            if ($headerBg !== null) {
                $options['headerBgColor'] = $headerBg;
            }
            $headerColor = $this->pickUniformColor($headerColorCandidates);
            if ($headerColor !== null) {
                $options['headerColor'] = $headerColor;
            }
        }

        if ($tableStyle instanceof ComputedStyle) {
            $map = $tableStyle->toArray();
            if (isset($map['text-align'])) {
                $align = strtolower($map['text-align']);
                if (in_array($align, ['left', 'right', 'center'], true)) {
                    $options['align'] = $align;
                }
            }
            if (!isset($options['headerBgColor']) && isset($map['background-color']) && strtolower($map['background-color']) !== 'transparent') {
                $options['headerBgColor'] = $map['background-color'];
            }
        }

        if (!isset($options['fontSize']) && $tableFontSizeDeclared) {
            $options['fontSize'] = $tableFontSize;
        }
        if ($tableLineHeightDeclared && $tableLineHeight !== null) {
            $options['lineHeight'] = $tableLineHeight;
        }

        $pdf->addTable($tableData, $options);
        $marginBottom = 0.0;
        if ($tableStyle instanceof ComputedStyle) {
            $map = $tableStyle->toArray();
            if (isset($map['margin-bottom']) && strtolower($map['margin-bottom']) !== 'auto') {
                $marginBottom = max(0.0, $this->parseLengthOptional($map['margin-bottom'], $tableFontSize, 0.0));
            }
        }

        $lineGap = $pdf->getStyleManager()->getLineHeight();
        $spacer = max($marginBottom, $lineGap);
        if ($spacer > 0.0) {
            $pdf->addSpacer($spacer);
        }
    }

    /**
     * @param array<string, string> $styleMap
     */
    private function mapCellStyleToOptions(array $styleMap, float $baseFontSize): array
    {
        if ($styleMap === []) {
            return [];
        }

        $markers = ['bold' => false, 'italic' => false, 'underline' => false];
        $options = $this->mapInlineStyleMapToRunOptions($styleMap, $markers, $baseFontSize);
        $styleString = $this->markersToStyleString($markers);
        if ($styleString !== '' || $this->hasStyleDirective($styleMap)) {
            $options['style'] = $styleString;
        }

        return $options;
    }

    /**
     * @param array<string, string> $styleMap
     * @param array<string, bool> $markers
     * @param array<int, string> $bgCandidates
     * @param array<int, string> $colorCandidates
     */
    private function collectHeaderStyleData(
        array $styleMap,
        float $baseFontSize,
        array &$markers,
        array &$bgCandidates,
        array &$colorCandidates
    ): void {
        if (isset($styleMap['background-color'])) {
            $bg = strtolower($styleMap['background-color']);
            if ($bg !== '' && $bg !== 'transparent') {
                $bgCandidates[] = $styleMap['background-color'];
            }
        }
        if (isset($styleMap['color'])) {
            $color = strtolower($styleMap['color']);
            if ($color !== '' && $color !== 'inherit') {
                $colorCandidates[] = $styleMap['color'];
            }
        }

        $cellMarkers = ['bold' => false, 'italic' => false, 'underline' => false];
        $this->mapInlineStyleMapToRunOptions($styleMap, $cellMarkers, $baseFontSize);
        foreach ($cellMarkers as $key => $value) {
            if ($value) {
                $markers[$key] = true;
            }
        }
    }

    /**
     * @param array<int, string> $values
     */
    private function pickUniformColor(array $values): ?string
    {
        $filtered = [];
        foreach ($values as $value) {
            $trimmed = trim((string)$value);
            if ($trimmed === '') {
                continue;
            }
            $lower = strtolower($trimmed);
            if ($lower === 'inherit' || $lower === 'transparent') {
                continue;
            }
            $filtered[] = $trimmed;
        }

        if ($filtered === []) {
            return null;
        }

        $first = strtolower($filtered[0]);
        foreach ($filtered as $candidate) {
            if (strtolower($candidate) !== $first) {
                return null;
            }
        }

        return $filtered[0];
    }

    private function mergeWithDefaultStylesheet(string $stylesheet): string
    {
        $defaults = $this->getDefaultStylesheet();
        $stylesheet = trim($stylesheet);
        if ($defaults === '') {
            return $stylesheet;
        }
        if ($stylesheet === '') {
            return $defaults;
        }
        return $defaults . "\n" . $stylesheet;
    }

    private function getDefaultStylesheet(): string
    {
        return <<<'CSS'
strong, b { font-weight: bold; }
em, i { font-style: italic; }
CSS;
    }
    private function extractEmbeddedCss(HtmlDocument $document): string
    {
        $stylesheets = $document->getEmbeddedStylesheets();
        if ($stylesheets !== []) {
            return trim(implode("\n", $stylesheets));
        }

        $css = [];
        $document->eachElement(function (array $node) use (&$css): void {
            if (strtolower((string)($node['tag'] ?? '')) !== 'style') {
                return;
            }
            $css[] = $this->collectText($node);
        });

        return trim(implode("\n", array_filter($css)));
    }

    private function collectText(array $node): string
    {
        $buffer = '';
        foreach ($node['children'] ?? [] as $child) {
            $type = $child['type'] ?? '';
            if ($type === 'text') {
                $buffer .= $child['text'] ?? '';
            } elseif ($type === 'element') {
                $buffer .= $this->collectText($child);
            }
        }
        return trim($buffer);
    }
}
