<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Fonts\HeuristicTextMetrics;
use Pagyra\Fonts\TextMetrics;
use Pagyra\Style\ComputedStyle;
use Pagyra\Style\StyledNode;
use Pagyra\Units\Units;

final class InlineTextFormatter
{
    private const ROOT_FONT_SIZE = 16.0;

    public function __construct(
        private readonly TextMetrics $metrics = new HeuristicTextMetrics(),
    ) {
    }

    public function layout(
        StyledNode $block,
        float $x,
        float $y,
        float $availableWidth,
        float $fontSize,
    ): InlineTextLayout {
        $fragments = $this->collectFragments($block, $fontSize);
        $tokens = $this->tokenize($fragments);
        if ($tokens === []) {
            return new InlineTextLayout([], 0.0);
        }

        $lines = [];
        $current = [];
        $currentWidth = 0.0;
        $pendingSpace = null;

        foreach ($tokens as $token) {
            if ($token['kind'] === 'space') {
                if ($current !== []) {
                    $pendingSpace = $token;
                }
                continue;
            }

            $spaceWidth = $pendingSpace !== null ? $pendingSpace['width'] : 0.0;
            $candidateWidth = $currentWidth + $spaceWidth + $token['width'];
            if ($current !== [] && $availableWidth > 0.0 && $candidateWidth > $availableWidth) {
                $lines[] = $current;
                $current = [];
                $currentWidth = 0.0;
                $pendingSpace = null;
            } else {
                if ($pendingSpace !== null && $current !== []) {
                    $current[] = $pendingSpace;
                    $currentWidth += $pendingSpace['width'];
                    $pendingSpace = null;
                }
            }

            $current[] = $token;
            $currentWidth += $token['width'];
        }

        if ($current !== []) {
            $lines[] = $current;
        }

        $lineBoxes = [];
        $cursorY = $y;
        foreach ($lines as $lineTokens) {
            $lineHeight = 0.0;
            $lineWidth = 0.0;
            foreach ($lineTokens as $token) {
                $lineHeight = max($lineHeight, $token['lineHeight']);
                $lineWidth += $token['width'];
            }
            if ($lineHeight <= 0.0) {
                $lineHeight = $this->metrics->lineHeight($block->style, $fontSize);
            }

            $baseline = $cursorY + ($lineHeight * 0.8);
            $runX = $x;
            $runs = [];
            foreach ($lineTokens as $token) {
                $runHeight = $token['lineHeight'];
                $runY = $baseline - ($runHeight * 0.8);
                $this->appendRun(
                    $runs,
                    new TextRun(
                        x: $runX,
                        y: $runY,
                        width: $token['width'],
                        height: $runHeight,
                        baseline: $baseline,
                        text: $token['text'],
                        fontSize: $token['fontSize'],
                        style: $token['style'],
                    ),
                );
                $runX += $token['width'];
            }

            $text = implode('', array_map(static fn(TextRun $run): string => $run->text, $runs));
            $lineBoxes[] = new LineBox(
                x: $x,
                y: $cursorY,
                width: $lineWidth,
                height: $lineHeight,
                baseline: $baseline,
                text: $text,
                runs: $runs,
            );
            $cursorY += $lineHeight;
        }

        return new InlineTextLayout($lineBoxes, $cursorY - $y);
    }

    /** @return list<InlineFragment> */
    private function collectFragments(StyledNode $node, float $parentFontSize): array
    {
        $fragments = [];
        foreach ($node->children as $child) {
            if ($child->node->type === 'text') {
                $text = $child->node->text ?? '';
                if ($text !== '') {
                    $childFontSize = $this->resolveFontSize($child->style, $parentFontSize);
                    $fragments[] = new InlineFragment(
                        text: $this->applyTextTransform($text, $child->style),
                        style: $child->style,
                        fontSize: $childFontSize,
                    );
                }
                continue;
            }

            $display = strtolower($child->style->get('display', 'inline') ?? 'inline');
            if ($display === 'none' || in_array($display, ['block', 'flow-root', 'list-item', 'table', 'table-row', 'table-cell'], true)) {
                continue;
            }

            $childFontSize = $this->resolveFontSize($child->style, $parentFontSize);
            array_push($fragments, ...$this->collectFragments($child, $childFontSize));
        }
        return $fragments;
    }

    /** @param list<InlineFragment> $fragments @return list<array{kind:string,text:string,style:ComputedStyle,fontSize:float,width:float,lineHeight:float}> */
    private function tokenize(array $fragments): array
    {
        $tokens = [];
        foreach ($fragments as $fragment) {
            $parts = preg_split('/(\s+)/u', $fragment->text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as $part) {
                $isSpace = preg_match('/^\s+$/u', $part) === 1;
                $text = $isSpace ? ' ' : $part;
                $measurement = $this->metrics->measure($text, $fragment->style, $fragment->fontSize);
                $tokens[] = [
                    'kind' => $isSpace ? 'space' : 'word',
                    'text' => $text,
                    'style' => $fragment->style,
                    'fontSize' => $fragment->fontSize,
                    'width' => $measurement->inlineSize,
                    'lineHeight' => $this->metrics->lineHeight($fragment->style, $fragment->fontSize),
                ];
            }
        }
        return $tokens;
    }

    /** @param list<TextRun> $runs */
    private function appendRun(array &$runs, TextRun $run): void
    {
        $last = $runs[array_key_last($runs)] ?? null;
        if ($last instanceof TextRun
            && $last->style === $run->style
            && abs($last->fontSize - $run->fontSize) < 1e-9
            && abs(($last->x + $last->width) - $run->x) < 1e-9) {
            $runs[array_key_last($runs)] = new TextRun(
                x: $last->x,
                y: min($last->y, $run->y),
                width: $last->width + $run->width,
                height: max($last->height, $run->height),
                baseline: $run->baseline,
                text: $last->text . $run->text,
                fontSize: $run->fontSize,
                style: $run->style,
            );
            return;
        }
        $runs[] = $run;
    }

    private function resolveFontSize(ComputedStyle $style, float $parentFontSize): float
    {
        $raw = strtolower(trim($style->get('font-size') ?? ''));
        if ($raw === '') {
            return $parentFontSize;
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)px$/', $raw, $m) === 1) {
            return max(0.0, (float) $m[1]);
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)pt$/', $raw, $m) === 1) {
            return max(0.0, Units::ptToPx((float) $m[1]));
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)em$/', $raw, $m) === 1) {
            return max(0.0, (float) $m[1] * $parentFontSize);
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)rem$/', $raw, $m) === 1) {
            return max(0.0, (float) $m[1] * self::ROOT_FONT_SIZE);
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $raw, $m) === 1) {
            return max(0.0, ((float) $m[1] / 100.0) * $parentFontSize);
        }
        if (is_numeric($raw)) {
            return max(0.0, (float) $raw);
        }
        return $parentFontSize;
    }

    private function applyTextTransform(string $text, ComputedStyle $style): string
    {
        return match (strtolower($style->get('text-transform', 'none') ?? 'none')) {
            'uppercase' => mb_strtoupper($text, 'UTF-8'),
            'lowercase' => mb_strtolower($text, 'UTF-8'),
            'capitalize' => preg_replace_callback('/(^|\s)(\p{L})/u', static fn(array $m): string => $m[1] . mb_strtoupper($m[2], 'UTF-8'), $text) ?? $text,
            default => $text,
        };
    }
}
