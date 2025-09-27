<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter\Flow;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Font\Resolve\FontResolver;
use Celsowm\PagyraPhp\Html\Style\ComputedStyle;

final class TableFlowRenderer
{
    public function __construct(
        private ParagraphBuilder $paragraphBuilder,
        private LengthConverter $lengthConverter,
        private FontResolver $fontResolver
    ) {
    }

    /**
     * @param array<string, mixed> $flow
     * @param array<string, ComputedStyle> $computedStyles
     */
    public function render(array $flow, PdfBuilder $pdf, array $computedStyles): void
    {
        $rows = $flow['rows'] ?? [];
        if ($rows === []) {
            return;
        }

        $this->paragraphBuilder->beginFontContext($pdf, $this->fontResolver);
        try {
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
                    $tableFontSize = $this->lengthConverter->parseLengthOptional($map['font-size'], 12.0, 12.0);
                    $tableFontSizeDeclared = true;
                }
                if (isset($map['line-height'])) {
                    $tableLineHeight = $this->lengthConverter->parseLengthOptional(
                        $map['line-height'],
                        $tableFontSize,
                        1.2 * $tableFontSize,
                        true
                    );
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
                            $cells[] = ['text' => $text, 'options' => $cellOptions];
                        } else {
                            $cells[] = $text;
                        }
                        if ($isHeader && $styleMap !== []) {
                            $this->collectHeaderStyleData(
                                $styleMap,
                                $tableFontSize,
                                $headerMarkers,
                                $headerBgCandidates,
                                $headerColorCandidates
                            );
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
                $headerStyle = $this->paragraphBuilder->markersToStyleString($headerMarkers);
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
                    $marginBottom = max(
                        0.0,
                        $this->lengthConverter->parseLengthOptional($map['margin-bottom'], $tableFontSize, 0.0)
                    );
                }
            }

            $lineGap = $pdf->getStyleManager()->getLineHeight();
            $spacer = max($marginBottom, $lineGap);
            if ($spacer > 0.0) {
                $pdf->addSpacer($spacer);
            }
        } finally {
            $this->paragraphBuilder->endFontContext();
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
        $options = $this->paragraphBuilder->mapInlineStyleMapToRunOptions($styleMap, $markers, $baseFontSize);
        $styleString = $this->paragraphBuilder->markersToStyleString($markers);
        if ($styleString !== '' || $this->paragraphBuilder->hasStyleDirective($styleMap)) {
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
        $this->paragraphBuilder->mapInlineStyleMapToRunOptions($styleMap, $cellMarkers, $baseFontSize);
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
}
