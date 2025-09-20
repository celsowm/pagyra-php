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
        $cssOm = $this->cssParser->parse($stylesheet);
        $computedStyles = $this->cssCascade->compute($document, $cssOm);
        $flows = $this->flowComposer->compose($document, $computedStyles);

        foreach ($flows as $flow) {
            $type = $flow['type'] ?? '';
            if ($type === 'table') {
                $this->renderTableFlow($flow, $pdf);
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

            $pdf->addParagraphRuns($runs, $paragraphOptions);
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
            $options['lineHeight'] = $this->parseLengthOptional($map['line-height'], $fontSize, 1.2 * $fontSize);
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

    private function parseLengthOptional(string $value, float $reference, float $fallback): float
    {
        return $this->convertLength($value, $reference, $fallback);
    }

    private function convertLength(string $value, float $reference, float $fallback): float
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'normal') {
            return $fallback;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
        if (preg_match('/^([0-9]*\.?[0-9]+)(px|pt|em|rem)?$/i', $value, $m) === 1) {
            $number = (float)$m[1];
            $unit = strtolower($m[2] ?? 'pt');
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
            $options['lineHeight'] = $this->parseLengthOptional($styleMap['line-height'], $ref, 1.2 * $ref);
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
        $style = strtoupper((string)($options['style'] ?? ''));
        return [
            'bold' => str_contains($style, 'B'),
            'italic' => str_contains($style, 'I'),
            'underline' => str_contains($style, 'U'),
        ];
    }

    private function markersToStyleString(array $markers): string
    {
        $parts = [];
        if (!empty($markers['bold'])) {
            $parts[] = 'bold';
        }
        if (!empty($markers['italic'])) {
            $parts[] = 'italic';
        }
        if (!empty($markers['underline'])) {
            $parts[] = 'underline';
        }
        return implode(' ', $parts);
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
        $markers = array_filter(explode(' ', strtolower($original)));
        if (!in_array($marker, $markers, true)) {
            $markers[] = $marker;
        }
        return implode(' ', $markers);
    }

    private function renderTableFlow(array $flow, PdfBuilder $pdf): void
    {
        $rows = $flow['rows'] ?? [];
        if ($rows === []) {
            return;
        }

        $tableData = [];
        $headerRowIndex = null;
        foreach ($rows as $index => $row) {
            if (!isset($row['cells'])) {
                continue;
            }
            $tableData[] = $row['cells'];
            if (($row['isHeader'] ?? false) && $headerRowIndex === null) {
                $headerRowIndex = $index;
            }
        }

        if ($tableData === []) {
            return;
        }

        $options = [];
        if ($headerRowIndex !== null) {
            $options['headerRow'] = $headerRowIndex;
        }

        $style = $flow['style'] ?? null;
        if ($style instanceof ComputedStyle) {
            $map = $style->toArray();
            if (isset($map['text-align'])) {
                $align = strtolower($map['text-align']);
                if (in_array($align, ['left', 'right', 'center'], true)) {
                    $options['align'] = $align;
                }
            }
            if (isset($map['background-color']) && strtolower($map['background-color']) !== 'transparent') {
                $options['headerBgColor'] = $map['background-color'];
            }
        }

        $pdf->addTable($tableData, $options);
    }

    private function extractEmbeddedCss(HtmlDocument $document): string
    {
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
