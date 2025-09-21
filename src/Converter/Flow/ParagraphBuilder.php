<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter\Flow;

use Celsowm\PagyraPhp\Html\HtmlDocument;
use Celsowm\PagyraPhp\Html\Style\ComputedStyle;
use Celsowm\PagyraPhp\Text\PdfRun;

final class ParagraphBuilder
{
    public function __construct(private LengthConverter $lengthConverter)
    {
    }

    public function buildParagraphOptions(ComputedStyle $style): array
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
            $options['size'] = $this->lengthConverter->parseLength($map['font-size'], 12.0);
        }

        if (isset($map['line-height'])) {
            $fontSize = (float)($options['size'] ?? 12.0);
            $options['lineHeight'] = $this->lengthConverter->parseLengthOptional(
                $map['line-height'],
                $fontSize,
                1.2 * $fontSize,
                true
            );
        }

        if (isset($map['color']) && strtolower($map['color']) !== 'inherit') {
            $options['color'] = $map['color'];
        }

        if (isset($map['font-weight'])) {
            $weight = strtolower($map['font-weight']);
            if (
                $weight === 'bold'
                || $weight === 'bolder'
                || (is_numeric($map['font-weight']) && (int)$map['font-weight'] >= 600)
            ) {
                $options['style'] = 'bold';
            }
        }

        if (isset($map['letter-spacing'])) {
            $fontSize = (float)($options['size'] ?? 12.0);
            $options['letterSpacing'] = $this->lengthConverter->parseLengthOptional(
                $map['letter-spacing'],
                $fontSize,
                0.0
            );
        }

        if (isset($map['word-spacing'])) {
            $fontSize = (float)($options['size'] ?? 12.0);
            $options['wordSpacing'] = $this->lengthConverter->parseLengthOptional(
                $map['word-spacing'],
                $fontSize,
                0.0
            );
        }

        if (isset($map['text-decoration']) && str_contains(strtolower($map['text-decoration']), 'underline')) {
            $options['style'] = $this->appendStyleMarker($options['style'] ?? '', 'underline');
        }

        return $options;
    }

    /**
     * @param array<int, array<string, mixed>> $runSpecs
     * @param array<string, ComputedStyle> $computedStyles
     * @return array<int, PdfRun>
     */
    public function buildRunsFromFlow(
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
            $options = $this->buildRunOptions(
                is_array($styleChain) ? $styleChain : [],
                $computedStyles,
                $document,
                $baseMarkers,
                $baseFontSize,
                is_string($linkNodeId) ? $linkNodeId : null
            );
            $runs[] = new PdfRun($text, $options);
        }

        return $runs;
    }

    /**
     * @param array<string, string> $styleMap
     * @param array<string, bool> $markers
     */
    public function mapInlineStyleMapToRunOptions(
        array $styleMap,
        array &$markers,
        float $baseFontSize
    ): array {
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
            } elseif (
                $weight === 'bold'
                || $weight === 'bolder'
                || (is_numeric($styleMap['font-weight']) && (int)$styleMap['font-weight'] >= 600)
            ) {
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
            $options['size'] = $this->lengthConverter->parseLengthOptional(
                $styleMap['font-size'],
                $baseFontSize,
                $baseFontSize
            );
        }

        if (isset($styleMap['line-height'])) {
            $reference = (float)($options['size'] ?? $baseFontSize);
            $options['lineHeight'] = $this->lengthConverter->parseLengthOptional(
                $styleMap['line-height'],
                $reference,
                1.2 * $reference,
                true
            );
        }

        $referenceSize = (float)($options['size'] ?? $baseFontSize);
        if (isset($styleMap['letter-spacing'])) {
            $options['letterSpacing'] = $this->lengthConverter->parseLengthOptional(
                $styleMap['letter-spacing'],
                $referenceSize,
                0.0
            );
        }

        if (isset($styleMap['word-spacing'])) {
            $options['wordSpacing'] = $this->lengthConverter->parseLengthOptional(
                $styleMap['word-spacing'],
                $referenceSize,
                0.0
            );
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

    public function styleMarkersFromOptions(array $options): array
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

    public function markersToStyleString(array $markers): string
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

    public function hasStyleDirective(array $styleMap): bool
    {
        foreach (['font-weight', 'font-style', 'text-decoration', 'text-decoration-line'] as $key) {
            if (array_key_exists($key, $styleMap)) {
                return true;
            }
        }

        return false;
    }

    public function appendStyleMarker(string $original, string $marker): string
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

    /**
     * @param array<int, string> $styleChain
     * @param array<string, ComputedStyle> $computedStyles
     */
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

    /**
     * @param array<int, string> $styleChain
     */
    private function extractHrefFromChain(
        array $styleChain,
        HtmlDocument $document,
        ?string $linkNodeId
    ): ?string {
        $candidates = $styleChain;
        if ($linkNodeId !== null) {
            $candidates[] = $linkNodeId;
        }
        for ($index = count($candidates) - 1; $index >= 0; $index--) {
            $element = $document->getElement($candidates[$index]);
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
            $trimmed = trim((string)$href);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }
}
