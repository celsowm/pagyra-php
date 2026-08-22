<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Fonts\HeuristicTextMetrics;
use Pagyra\Fonts\TextMetrics;
use Pagyra\Style\StyledNode;

final class InlineTextFormatter
{
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
        $text = $this->collectInlineText($block);
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        if ($text === '') {
            return new InlineTextLayout([], 0.0);
        }

        $lineHeight = $this->metrics->lineHeight($block->style, $fontSize);
        $words = preg_split('/\s+/u', $text) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            $candidateWidth = $this->metrics->measure($candidate, $block->style, $fontSize)->inlineSize;

            if ($current !== '' && $availableWidth > 0 && $candidateWidth > $availableWidth) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        $result = [];
        foreach ($lines as $index => $line) {
            $measurement = $this->metrics->measure($line, $block->style, $fontSize);
            $lineY = $y + ($index * $lineHeight);
            $result[] = new LineBox(
                x: $x,
                y: $lineY,
                width: $measurement->inlineSize,
                height: $lineHeight,
                baseline: $lineY + ($lineHeight * 0.8),
                text: $line,
            );
        }

        return new InlineTextLayout($result, count($result) * $lineHeight);
    }

    private function collectInlineText(StyledNode $node): string
    {
        $text = '';
        foreach ($node->children as $child) {
            if ($child->node->type === 'text') {
                $text .= $child->node->text ?? '';
                continue;
            }

            $display = strtolower($child->style->get('display', 'inline') ?? 'inline');
            if (in_array($display, ['block', 'flow-root', 'list-item', 'table', 'table-row', 'table-cell'], true)) {
                continue;
            }
            $text .= $this->collectInlineText($child);
        }
        return $text;
    }
}
