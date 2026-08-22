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

        $whiteSpace = strtolower($block->style->get('white-space', 'normal') ?? 'normal');
        $overflowWrap = strtolower($block->style->get('overflow-wrap', 'normal') ?? 'normal');
        $wordBreak = strtolower($block->style->get('word-break', 'normal') ?? 'normal');
        $allowSoftWrap = $whiteSpace !== 'nowrap' && $whiteSpace !== 'pre';

        $lines = [];
        $current = [];
        $currentWidth = 0.0;

        foreach ($tokens as $token) {
            if ($token['kind'] === 'newline') {
                $lines[] = $current;
                $current = [];
                $currentWidth = 0.0;
                continue;
            }

            if ($token['kind'] === 'space' && $this->collapsesSpaces($whiteSpace)) {
                if ($current === [] || ($current[array_key_last($current)]['kind'] ?? null) === 'space') {
                    continue;
                }
                $token['text'] = ' ';
                $token['width'] = $this->metrics->measure(' ', $token['style'], $token['fontSize'])->inlineSize;
            }

            if ($allowSoftWrap && $availableWidth > 0.0 && $current !== [] && $currentWidth + $token['width'] > $availableWidth) {
                if ($token['kind'] === 'space' && $this->collapsesSpaces($whiteSpace)) {
                    $lines[] = $current;
                    $current = [];
                    $currentWidth = 0.0;
                    continue;
                }

                $lines[] = $current;
                $current = [];
                $currentWidth = 0.0;
            }

            if ($allowSoftWrap && $availableWidth > 0.0 && $token['kind'] === 'word' && $token['width'] > $availableWidth && $this->canBreakInsideWord($overflowWrap, $wordBreak)) {
                foreach ($this->splitWordToken($token, $availableWidth) as $index => $chunk) {
                    if ($index > 0) {
                        $lines[] = $current;
                        $current = [];
                        $currentWidth = 0.0;
                    }
                    $current[] = $chunk;
                    $currentWidth += $chunk['width'];
                }
                continue;
            }

            $current[] = $token;
            $currentWidth += $token['width'];
        }

        if ($current !== [] || $lines === []) {
            $lines[] = $current;
        }

        $lineBoxes = [];
        $cursorY = $y;
        $lineCount = count($lines);
        foreach ($lines as $lineIndex => $lineTokens) {
            $lineHeight = 0.0;
            $lineWidth = 0.0;
            foreach ($lineTokens as $token) {
                $lineHeight = max($lineHeight, $token['lineHeight']);
                $lineWidth += $token['width'];
            }
            if ($lineHeight <= 0.0) {
                $lineHeight = $this->metrics->lineHeight($block->style, $fontSize);
            }

            $isLastLine = $lineIndex === $lineCount - 1;
            $alignment = strtolower($block->style->get('text-align', 'left') ?? 'left');
            $justify = $alignment === 'justify' && !$isLastLine && $availableWidth > $lineWidth;
            $spaceCount = $justify ? $this->countSpaceTokens($lineTokens) : 0;
            $extraPerSpace = $spaceCount > 0 ? ($availableWidth - $lineWidth) / $spaceCount : 0.0;
            $offset = $justify ? 0.0 : $this->alignmentOffset($alignment, $lineWidth, $availableWidth);

            $baseline = $cursorY + ($lineHeight * 0.8);
            $runX = $x + $offset;
            $runs = [];
            $usedWidth = 0.0;
            foreach ($lineTokens as $token) {
                $runHeight = $token['lineHeight'];
                $runY = $baseline - ($runHeight * 0.8);
                $runWidth = $token['width'] + (($justify && $token['kind'] === 'space') ? $extraPerSpace : 0.0);
                $this->appendRun(
                    $runs,
                    new TextRun(
                        x: $runX,
                        y: $runY,
                        width: $runWidth,
                        height: $runHeight,
                        baseline: $baseline,
                        text: $token['text'],
                        fontSize: $token['fontSize'],
                        style: $token['style'],
                    ),
                );
                $runX += $runWidth;
                $usedWidth += $runWidth;
            }

            $text = implode('', array_map(static fn(TextRun $run): string => $run->text, $runs));
            $lineBoxes[] = new LineBox(
                x: $x + $offset,
                y: $cursorY,
                width: $usedWidth,
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
            $whiteSpace = strtolower($fragment->style->get('white-space', 'normal') ?? 'normal');
            $parts = preg_split('/(\r\n|\r|\n|[\t\f ]+)/u', $fragment->text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($parts as $part) {
                if (preg_match('/^(\r\n|\r|\n)$/u', $part) === 1) {
                    if (in_array($whiteSpace, ['pre', 'pre-wrap', 'pre-line'], true)) {
                        $tokens[] = $this->token('newline', '', $fragment);
                    } else {
                        $tokens[] = $this->token('space', ' ', $fragment);
                    }
                    continue;
                }

                if (preg_match('/^[\t\f ]+$/u', $part) === 1) {
                    $text = in_array($whiteSpace, ['pre', 'pre-wrap'], true) ? $part : ' ';
                    $tokens[] = $this->token('space', $text, $fragment);
                    continue;
                }

                $tokens[] = $this->token('word', $part, $fragment);
            }
        }
        return $tokens;
    }

    /** @return array{kind:string,text:string,style:ComputedStyle,fontSize:float,width:float,lineHeight:float} */
    private function token(string $kind, string $text, InlineFragment $fragment): array
    {
        $width = $kind === 'newline' ? 0.0 : $this->metrics->measure($text, $fragment->style, $fragment->fontSize)->inlineSize;
        return [
            'kind' => $kind,
            'text' => $text,
            'style' => $fragment->style,
            'fontSize' => $fragment->fontSize,
            'width' => $width,
            'lineHeight' => $this->metrics->lineHeight($fragment->style, $fragment->fontSize),
        ];
    }

    /** @param array{kind:string,text:string,style:ComputedStyle,fontSize:float,width:float,lineHeight:float} $token
     *  @return list<array{kind:string,text:string,style:ComputedStyle,fontSize:float,width:float,lineHeight:float}> */
    private function splitWordToken(array $token, float $availableWidth): array
    {
        $chars = preg_split('//u', $token['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [$token['text']];
        $chunks = [];
        $buffer = '';
        foreach ($chars as $char) {
            $candidate = $buffer . $char;
            $candidateWidth = $this->metrics->measure($candidate, $token['style'], $token['fontSize'])->inlineSize;
            if ($buffer !== '' && $candidateWidth > $availableWidth) {
                $chunks[] = $this->retoken($token, $buffer);
                $buffer = $char;
            } else {
                $buffer = $candidate;
            }
        }
        if ($buffer !== '') {
            $chunks[] = $this->retoken($token, $buffer);
        }
        return $chunks === [] ? [$token] : $chunks;
    }

    /** @param array{kind:string,text:string,style:ComputedStyle,fontSize:float,width:float,lineHeight:float} $token
     *  @return array{kind:string,text:string,style:ComputedStyle,fontSize:float,width:float,lineHeight:float} */
    private function retoken(array $token, string $text): array
    {
        $token['text'] = $text;
        $token['width'] = $this->metrics->measure($text, $token['style'], $token['fontSize'])->inlineSize;
        return $token;
    }

    private function collapsesSpaces(string $whiteSpace): bool
    {
        return !in_array($whiteSpace, ['pre', 'pre-wrap'], true);
    }

    private function canBreakInsideWord(string $overflowWrap, string $wordBreak): bool
    {
        return $wordBreak === 'break-all' || in_array($overflowWrap, ['anywhere', 'break-word'], true);
    }

    /** @param list<array{kind:string,text:string,style:ComputedStyle,fontSize:float,width:float,lineHeight:float}> $tokens */
    private function countSpaceTokens(array $tokens): int
    {
        $count = 0;
        foreach ($tokens as $token) {
            if ($token['kind'] === 'space') {
                $count++;
            }
        }
        return $count;
    }

    private function alignmentOffset(string $alignment, float $lineWidth, float $availableWidth): float
    {
        $slack = max(0.0, $availableWidth - $lineWidth);
        return match ($alignment) {
            'center' => $slack / 2.0,
            'right', 'end' => $slack,
            default => 0.0,
        };
    }

    /** @param list<TextRun> $runs */
    private function appendRun(array &$runs, TextRun $run): void
    {
        $lastKey = array_key_last($runs);
        $last = $lastKey !== null ? $runs[$lastKey] : null;
        if ($last instanceof TextRun
            && $last->style === $run->style
            && abs($last->fontSize - $run->fontSize) < 1e-9
            && abs(($last->x + $last->width) - $run->x) < 1e-9) {
            $runs[$lastKey] = new TextRun(
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
        if ($raw === '') return $parentFontSize;
        if (preg_match('/^(-?\d+(?:\.\d+)?)px$/', $raw, $m) === 1) return max(0.0, (float) $m[1]);
        if (preg_match('/^(-?\d+(?:\.\d+)?)pt$/', $raw, $m) === 1) return max(0.0, Units::ptToPx((float) $m[1]));
        if (preg_match('/^(-?\d+(?:\.\d+)?)em$/', $raw, $m) === 1) return max(0.0, (float) $m[1] * $parentFontSize);
        if (preg_match('/^(-?\d+(?:\.\d+)?)rem$/', $raw, $m) === 1) return max(0.0, (float) $m[1] * self::ROOT_FONT_SIZE);
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $raw, $m) === 1) return max(0.0, ((float) $m[1] / 100.0) * $parentFontSize);
        if (is_numeric($raw)) return max(0.0, (float) $raw);
        return $parentFontSize;
    }

    private function applyTextTransform(string $text, ComputedStyle $style): string
    {
        return match (strtolower($style->get('text-transform', 'none') ?? 'none')) {
            'uppercase' => $this->upper($text),
            'lowercase' => $this->lower($text),
            'capitalize' => preg_replace_callback('/(^|\s)(\p{L})/u', fn(array $m): string => $m[1] . $this->upper($m[2]), $text) ?? $text,
            default => $text,
        };
    }

    private function upper(string $value): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
