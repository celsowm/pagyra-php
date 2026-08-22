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

    public function __construct(private readonly TextMetrics $metrics = new HeuristicTextMetrics()) {}

    public function layout(StyledNode $block, float $x, float $y, float $availableWidth, float $fontSize): InlineTextLayout
    {
        $tokens = $this->collectTokens($block, $fontSize, $availableWidth);
        if ($tokens === []) return new InlineTextLayout([], 0.0);

        $whiteSpace = strtolower($block->style->get('white-space', 'normal') ?? 'normal');
        $overflowWrap = strtolower($block->style->get('overflow-wrap', 'normal') ?? 'normal');
        $wordBreak = strtolower($block->style->get('word-break', 'normal') ?? 'normal');
        $allowSoftWrap = !in_array($whiteSpace, ['nowrap', 'pre'], true);

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
                if ($current === [] || ($current[array_key_last($current)]['kind'] ?? null) === 'space') continue;
                $token['text'] = ' ';
                $token['width'] = $this->metrics->measure(' ', $token['style'], $token['fontSize'])->inlineSize;
            }
            if ($allowSoftWrap && $availableWidth > 0 && $current !== [] && $currentWidth + $token['width'] > $availableWidth) {
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
            if ($allowSoftWrap && $availableWidth > 0 && $token['kind'] === 'word' && $token['width'] > $availableWidth && $this->canBreakInsideWord($overflowWrap, $wordBreak)) {
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
        if ($current !== [] || $lines === []) $lines[] = $current;

        $lineBoxes = [];
        $cursorY = $y;
        foreach ($lines as $lineIndex => $lineTokens) {
            $lineWidth = array_sum(array_column($lineTokens, 'width'));
            $nominalHeight = $this->metrics->lineHeight($block->style, $fontSize);
            foreach ($lineTokens as $token) $nominalHeight = max($nominalHeight, $token['lineHeight']);

            $isLastLine = $lineIndex === count($lines) - 1;
            $alignment = strtolower($block->style->get('text-align', 'left') ?? 'left');
            $justify = $alignment === 'justify' && !$isLastLine && $availableWidth > $lineWidth;
            $spaceCount = $justify ? $this->countSpaceTokens($lineTokens) : 0;
            $extraPerSpace = $spaceCount > 0 ? ($availableWidth - $lineWidth) / $spaceCount : 0.0;
            $offset = $justify ? 0.0 : $this->alignmentOffset($alignment, $lineWidth, $availableWidth);

            // pagyra-js fallback baseline: ascent ~= 0.75 * font-size plus half-leading.
            $lineBaseline = $this->ownBaseline($fontSize, $nominalHeight);
            $placements = [];
            $minTop = 0.0;
            $maxBottom = $nominalHeight;
            foreach ($lineTokens as $token) {
                if ($token['kind'] === 'box') {
                    $top = $this->boxTopOffset($token, $nominalHeight, $fontSize);
                    $placements[] = ['token' => $token, 'top' => $top, 'baseline' => null];
                    $minTop = min($minTop, $top);
                    $maxBottom = max($maxBottom, $top + $token['lineHeight']);
                    continue;
                }
                $itemBaseline = $this->textBaseline($token, $lineBaseline, $nominalHeight);
                $top = $itemBaseline - $this->ownBaseline($token['fontSize'], $token['lineHeight']);
                $placements[] = ['token' => $token, 'top' => $top, 'baseline' => $itemBaseline];
                $minTop = min($minTop, $top);
                $maxBottom = max($maxBottom, $top + $token['lineHeight']);
            }

            $lineHeight = $maxBottom - $minTop;
            $baseline = $cursorY + ($lineBaseline - $minTop);
            $runX = $x + $offset;
            $runs = [];
            $boxes = [];
            $usedWidth = 0.0;
            foreach ($placements as $placement) {
                $token = $placement['token'];
                $width = $token['width'] + (($justify && $token['kind'] === 'space') ? $extraPerSpace : 0.0);
                $itemY = $cursorY + ($placement['top'] - $minTop);
                if ($token['kind'] === 'box') {
                    $boxes[] = new AtomicInlineBox(
                        source: $token['source'],
                        x: $runX,
                        y: $itemY,
                        width: $width,
                        height: $token['lineHeight'],
                        style: $token['style'],
                        contentWidth: $token['contentWidth'],
                        contentHeight: $token['contentHeight'],
                        margin: $token['margin'],
                        padding: $token['padding'],
                        border: $token['border'],
                    );
                } else {
                    $runBaseline = $cursorY + (($placement['baseline'] ?? $lineBaseline) - $minTop);
                    $this->appendRun($runs, new TextRun($runX, $itemY, $width, $token['lineHeight'], $runBaseline, $token['text'], $token['fontSize'], $token['style']));
                }
                $runX += $width;
                $usedWidth += $width;
            }
            $text = implode('', array_map(static fn(TextRun $run): string => $run->text, $runs));
            $lineBoxes[] = new LineBox($x + $offset, $cursorY, $usedWidth, $lineHeight, $baseline, $text, $runs, $boxes);
            $cursorY += $lineHeight;
        }
        return new InlineTextLayout($lineBoxes, $cursorY - $y);
    }

    private function collectTokens(StyledNode $node, float $nodeFontSize, float $referenceWidth): array
    {
        $tokens = [];
        foreach ($node->children as $child) {
            if ($child->node->type === 'text') {
                $text = $this->applyTextTransform($child->node->text ?? '', $node->style);
                array_push($tokens, ...$this->tokenizeText($text, $node->style, $nodeFontSize));
                continue;
            }
            $display = strtolower($child->style->get('display', 'inline') ?? 'inline');
            if ($display === 'none' || in_array($display, ['block', 'flow-root', 'list-item', 'table', 'table-row', 'table-cell'], true)) continue;
            $fontSize = $this->resolveFontSize($child->style, $nodeFontSize);
            if ($child->node->isImage() || in_array($display, ['inline-block', 'inline-flex', 'inline-grid', 'inline-table'], true)) {
                $metrics = $this->atomicBoxMetrics($child, $referenceWidth, $fontSize);
                $tokens[] = [
                    'kind' => 'box',
                    'text' => '',
                    'style' => $child->style,
                    'fontSize' => $fontSize,
                    'width' => $metrics['outerWidth'],
                    'lineHeight' => $metrics['outerHeight'],
                    'source' => $child,
                    'contentWidth' => $metrics['contentWidth'],
                    'contentHeight' => $metrics['contentHeight'],
                    'margin' => $metrics['margin'],
                    'padding' => $metrics['padding'],
                    'border' => $metrics['border'],
                ];
                continue;
            }
            array_push($tokens, ...$this->collectTokens($child, $fontSize, $referenceWidth));
        }
        return $tokens;
    }

    private function tokenizeText(string $text, ComputedStyle $style, float $fontSize): array
    {
        if ($text === '') return [];
        $whiteSpace = strtolower($style->get('white-space', 'normal') ?? 'normal');
        $parts = preg_split('/(\r\n|\r|\n|[\t\f ]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            if (preg_match('/^(\r\n|\r|\n)$/u', $part) === 1) {
                $preserve = in_array($whiteSpace, ['pre', 'pre-wrap', 'pre-line'], true);
                $tokens[] = $this->textToken($preserve ? 'newline' : 'space', $preserve ? '' : ' ', $style, $fontSize);
            } elseif (preg_match('/^[\t\f ]+$/u', $part) === 1) {
                $tokens[] = $this->textToken('space', in_array($whiteSpace, ['pre', 'pre-wrap'], true) ? $part : ' ', $style, $fontSize);
            } else {
                $tokens[] = $this->textToken('word', $part, $style, $fontSize);
            }
        }
        return $tokens;
    }

    private function textToken(string $kind, string $text, ComputedStyle $style, float $fontSize): array
    {
        return [
            'kind' => $kind,
            'text' => $text,
            'style' => $style,
            'fontSize' => $fontSize,
            'width' => $kind === 'newline' ? 0.0 : $this->metrics->measure($text, $style, $fontSize)->inlineSize,
            'lineHeight' => $this->metrics->lineHeight($style, $fontSize),
            'source' => null,
        ];
    }

    private function textBaseline(array $token, float $lineBaseline, float $lineHeight): float
    {
        $value = strtolower(trim($token['style']->get('vertical-align', 'baseline') ?? 'baseline'));
        $fontSize = $token['fontSize'];
        $ownHeight = $token['lineHeight'];
        return match ($value) {
            'sub' => $lineBaseline + $fontSize * 0.2,
            'super' => $lineBaseline - $fontSize * 0.4,
            'top', 'text-top' => $this->ownBaseline($fontSize, $ownHeight),
            'bottom', 'text-bottom' => max($lineHeight - $ownHeight, 0.0) + $this->ownBaseline($fontSize, $ownHeight),
            'middle' => $lineBaseline + $fontSize * 0.25 + ($this->ownBaseline($fontSize, $ownHeight) - $ownHeight / 2.0),
            default => $lineBaseline - $this->numericVerticalShift($value, $fontSize, $ownHeight),
        };
    }

    private function boxTopOffset(array $token, float $lineHeight, float $parentFontSize): float
    {
        $value = strtolower(trim($token['style']->get('vertical-align', 'baseline') ?? 'baseline'));
        $height = $token['lineHeight'];
        return match ($value) {
            'bottom', 'text-bottom' => max($lineHeight - $height, 0.0),
            'middle' => ($lineHeight - $height) / 2.0,
            'sub' => $parentFontSize * 0.2,
            'super' => -$parentFontSize * 0.4,
            'top', 'text-top', 'baseline' => 0.0,
            default => -$this->numericVerticalShift($value, $parentFontSize, $height),
        };
    }

    private function ownBaseline(float $fontSize, float $lineHeight): float
    {
        return (($lineHeight - $fontSize) / 2.0) + ($fontSize * 0.75);
    }

    private function numericVerticalShift(string $value, float $fontSize, float $lineHeight): float
    {
        if (preg_match('/^(-?\d+(?:\.\d+)?)px$/', $value, $m) === 1) return (float) $m[1];
        if (preg_match('/^(-?\d+(?:\.\d+)?)pt$/', $value, $m) === 1) return Units::ptToPx((float) $m[1]);
        if (preg_match('/^(-?\d+(?:\.\d+)?)em$/', $value, $m) === 1) return (float) $m[1] * $fontSize;
        if (preg_match('/^(-?\d+(?:\.\d+)?)rem$/', $value, $m) === 1) return (float) $m[1] * self::ROOT_FONT_SIZE;
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $value, $m) === 1) return ((float) $m[1] / 100.0) * $lineHeight;
        return 0.0;
    }

    /** @return array{contentWidth:float,contentHeight:float,outerWidth:float,outerHeight:float,margin:array{top:float,right:float,bottom:float,left:float},padding:array{top:float,right:float,bottom:float,left:float},border:array{top:float,right:float,bottom:float,left:float}} */
    private function atomicBoxMetrics(StyledNode $node, float $referenceWidth, float $fontSize): array
    {
        $fallbackHeight = $this->metrics->lineHeight($node->style, $fontSize);
        $fallbackWidth = $node->node->isImage() ? 0.0 : $fallbackHeight;
        $contentWidth = $this->atomicDimension($node, 'width', $fallbackWidth, $fontSize, $referenceWidth);
        $contentHeight = $this->atomicDimension($node, 'height', $fallbackHeight, $fontSize, $referenceWidth);
        $margin = $this->edgeMetrics($node, 'margin', $referenceWidth, $fontSize);
        $padding = $this->edgeMetrics($node, 'padding', $referenceWidth, $fontSize);
        $border = $this->borderMetrics($node, $referenceWidth, $fontSize);

        return [
            'contentWidth' => $contentWidth,
            'contentHeight' => $contentHeight,
            'outerWidth' => $contentWidth + $margin['left'] + $margin['right'] + $padding['left'] + $padding['right'] + $border['left'] + $border['right'],
            'outerHeight' => $contentHeight + $margin['top'] + $margin['bottom'] + $padding['top'] + $padding['bottom'] + $border['top'] + $border['bottom'],
            'margin' => $margin,
            'padding' => $padding,
            'border' => $border,
        ];
    }

    /** @return array{top:float,right:float,bottom:float,left:float} */
    private function edgeMetrics(StyledNode $node, string $property, float $referenceWidth, float $fontSize): array
    {
        $parts = $this->expandFour($node->style->get($property));
        foreach (['top' => 0, 'right' => 1, 'bottom' => 2, 'left' => 3] as $side => $index) {
            $parts[$index] = $node->style->get($property . '-' . $side, $parts[$index]);
        }
        return [
            'top' => $this->resolveAtomicLength($parts[0], $referenceWidth, $fontSize),
            'right' => $this->resolveAtomicLength($parts[1], $referenceWidth, $fontSize),
            'bottom' => $this->resolveAtomicLength($parts[2], $referenceWidth, $fontSize),
            'left' => $this->resolveAtomicLength($parts[3], $referenceWidth, $fontSize),
        ];
    }

    /** @return array{top:float,right:float,bottom:float,left:float} */
    private function borderMetrics(StyledNode $node, float $referenceWidth, float $fontSize): array
    {
        $parts = $this->expandFour($node->style->get('border-width'));
        foreach (['top' => 0, 'right' => 1, 'bottom' => 2, 'left' => 3] as $side => $index) {
            $parts[$index] = $node->style->get('border-' . $side . '-width', $parts[$index]);
        }
        return [
            'top' => $this->resolveAtomicLength($parts[0], $referenceWidth, $fontSize),
            'right' => $this->resolveAtomicLength($parts[1], $referenceWidth, $fontSize),
            'bottom' => $this->resolveAtomicLength($parts[2], $referenceWidth, $fontSize),
            'left' => $this->resolveAtomicLength($parts[3], $referenceWidth, $fontSize),
        ];
    }

    /** @return array{0:?string,1:?string,2:?string,3:?string} */
    private function expandFour(?string $value): array
    {
        $parts = $value !== null ? (preg_split('/\s+/', trim($value)) ?: []) : [];
        return match (count($parts)) {
            1 => [$parts[0], $parts[0], $parts[0], $parts[0]],
            2 => [$parts[0], $parts[1], $parts[0], $parts[1]],
            3 => [$parts[0], $parts[1], $parts[2], $parts[1]],
            default => [$parts[0] ?? null, $parts[1] ?? null, $parts[2] ?? null, $parts[3] ?? null],
        };
    }

    private function atomicDimension(StyledNode $node, string $property, float $fallback, float $fontSize, float $referenceWidth): float
    {
        $raw = $node->style->get($property);
        if (($raw === null || strtolower(trim($raw)) === 'auto') && $node->node->isImage()) $raw = $node->node->attribute($property);
        if ($raw === null || strtolower(trim($raw)) === 'auto') return $fallback;
        return max(0.0, $this->resolveAtomicLength($raw, $referenceWidth, $fontSize, $fallback));
    }

    private function resolveAtomicLength(?string $raw, float $referenceWidth, float $fontSize, float $fallback = 0.0): float
    {
        if ($raw === null) return $fallback;
        $value = strtolower(trim($raw));
        if ($value === '' || $value === 'auto') return $fallback;
        if (preg_match('/^(-?\d+(?:\.\d+)?)px$/', $value, $m) === 1) return (float) $m[1];
        if (preg_match('/^(-?\d+(?:\.\d+)?)pt$/', $value, $m) === 1) return Units::ptToPx((float) $m[1]);
        if (preg_match('/^(-?\d+(?:\.\d+)?)em$/', $value, $m) === 1) return (float) $m[1] * $fontSize;
        if (preg_match('/^(-?\d+(?:\.\d+)?)rem$/', $value, $m) === 1) return (float) $m[1] * self::ROOT_FONT_SIZE;
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $value, $m) === 1) return ((float) $m[1] / 100.0) * $referenceWidth;
        return is_numeric($value) ? (float) $value : $fallback;
    }

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
        if ($buffer !== '') $chunks[] = $this->retoken($token, $buffer);
        return $chunks === [] ? [$token] : $chunks;
    }

    private function retoken(array $token, string $text): array
    {
        $token['text'] = $text;
        $token['width'] = $this->metrics->measure($text, $token['style'], $token['fontSize'])->inlineSize;
        return $token;
    }

    private function collapsesSpaces(string $whiteSpace): bool { return !in_array($whiteSpace, ['pre', 'pre-wrap'], true); }
    private function canBreakInsideWord(string $overflowWrap, string $wordBreak): bool { return $wordBreak === 'break-all' || in_array($overflowWrap, ['anywhere', 'break-word'], true); }
    private function countSpaceTokens(array $tokens): int { return count(array_filter($tokens, static fn(array $t): bool => $t['kind'] === 'space')); }
    private function alignmentOffset(string $alignment, float $lineWidth, float $availableWidth): float
    {
        $slack = max(0.0, $availableWidth - $lineWidth);
        return match ($alignment) { 'center' => $slack / 2.0, 'right', 'end' => $slack, default => 0.0 };
    }

    private function appendRun(array &$runs, TextRun $run): void
    {
        $key = array_key_last($runs);
        $last = $key !== null ? $runs[$key] : null;
        if ($last instanceof TextRun
            && $last->style === $run->style
            && abs($last->fontSize - $run->fontSize) < 1e-9
            && abs(($last->x + $last->width) - $run->x) < 1e-9
            && abs($last->baseline - $run->baseline) < 1e-9) {
            $runs[$key] = new TextRun($last->x, min($last->y, $run->y), $last->width + $run->width, max($last->height, $run->height), $run->baseline, $last->text . $run->text, $run->fontSize, $run->style);
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
        return is_numeric($raw) ? max(0.0, (float) $raw) : $parentFontSize;
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

    private function upper(string $value): string { return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value); }
    private function lower(string $value): string { return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value); }
}
