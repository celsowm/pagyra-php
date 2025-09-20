<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Html\Style\Layout;

use Celsowm\PagyraPhp\Html\HtmlDocument;

final class FlowComposer
{
    /**
     * @param array<string, ComputedStyle> $styles
     * @return array<int, array<string, mixed>>
     */
    public function compose(HtmlDocument $document, array $styles): array
    {
        $flows = [];

        $document->eachElement(function (array $node) use (&$flows, $styles): void {
            $tag = strtolower((string)($node['tag'] ?? ''));
            if ($tag === 'style' || $tag === 'script') {
                return;
            }

            if ($tag === 'table') {
                $rows = $this->extractTableRows($node);
                if ($rows === []) {
                    return;
                }
                $nodeId = (string)($node['nodeId'] ?? '');
                $flows[] = [
                    'type' => 'table',
                    'tag' => 'table',
                    'nodeId' => $nodeId,
                    'rows' => $rows,
                    'style' => $styles[$nodeId] ?? null,
                ];
                return;
            }

            if (!$this->isBlockTag($tag)) {
                return;
            }

            if ($this->hasAncestorTag($node, 'table')) {
                return;
            }

            if ($tag === 'div' && $this->hasNestedBlockChild($node)) {
                return;
            }

            $nodeId = (string)($node['nodeId'] ?? '');
            if ($nodeId === '') {
                return;
            }

            $runs = $this->collectRuns($node);
            if ($runs === []) {
                return;
            }

            $flows[] = [
                'type' => 'block',
                'tag' => $tag,
                'nodeId' => $nodeId,
                'runs' => $runs,
                'style' => $styles[$nodeId] ?? null,
            ];
        });

        return $flows;
    }

    private function isBlockTag(string $tag): bool
    {
        return in_array($tag, ['p', 'div', 'li', 'blockquote', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true);
    }

    private function hasNestedBlockChild(array $node): bool
    {
        foreach ($node['children'] ?? [] as $child) {
            if (($child['type'] ?? '') !== 'element') {
                continue;
            }
            $childTag = strtolower((string)($child['tag'] ?? ''));
            if ($this->isBlockTag($childTag) || $childTag === 'table') {
                return true;
            }
        }
        return false;
    }

    private function hasAncestorTag(array $node, string $tag): bool
    {
        foreach ($node['ancestors'] ?? [] as $ancestor) {
            if (strtolower((string)($ancestor['tag'] ?? '')) === $tag) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, array{text: string, styleChain: array<int, string>, linkNodeId: string|null}>
     */
    private function collectRuns(array $node): array
    {
        $runs = [];
        $nodeId = (string)($node['nodeId'] ?? '');
        if ($nodeId === '') {
            return [];
        }
        $this->collectRunsRecursive($node, [$nodeId], null, $runs);
        return $this->mergeAdjacentRuns($runs);
    }

    /**
     * @param array<int, array{text: string, styleChain: array<int, string>, linkNodeId: string|null}> $runs
     * @return array<int, array{text: string, styleChain: array<int, string>, linkNodeId: string|null}>
     */
    private function mergeAdjacentRuns(array $runs): array
    {
        if ($runs === []) {
            return [];
        }
        $merged = [];
        foreach ($runs as $run) {
            if ($merged !== [] && $merged[count($merged) - 1]['styleChain'] === $run['styleChain'] && $merged[count($merged) - 1]['linkNodeId'] === $run['linkNodeId']) {
                $merged[count($merged) - 1]['text'] .= $run['text'];
                continue;
            }
            $merged[] = $run;
        }
        return $merged;
    }

    /**
     * @param array<int, array{text: string, styleChain: array<int, string>, linkNodeId: string|null}> $runs
     */
    private function collectRunsRecursive(array $node, array $chain, ?string $linkNodeId, array &$runs): void
    {
        foreach ($node['children'] ?? [] as $child) {
            $type = $child['type'] ?? '';
            if ($type === 'text') {
                $text = (string)($child['text'] ?? '');
                if ($text !== '') {
                    $runs[] = [
                        'text' => $text,
                        'styleChain' => $chain,
                        'linkNodeId' => $linkNodeId,
                    ];
                }
                continue;
            }
            if ($type !== 'element') {
                continue;
            }

            $childTag = strtolower((string)($child['tag'] ?? ''));
            if ($this->isBlockTag($childTag) || $childTag === 'table') {
                continue;
            }

            if ($childTag === 'br') {
                $runs[] = [
                    'text' => "\n",
                    'styleChain' => $chain,
                    'linkNodeId' => $linkNodeId,
                ];
                continue;
            }

            $childId = (string)($child['nodeId'] ?? '');
            if ($childId === '') {
                continue;
            }

            $newChain = $chain;
            $newChain[] = $childId;
            $nextLink = $linkNodeId;
            if ($childTag === 'a') {
                $href = $child['attributes']['href'] ?? null;
                if ($href !== null && trim((string)$href) !== '') {
                    $nextLink = $childId;
                }
            }

            $this->collectRunsRecursive($child, $newChain, $nextLink, $runs);
        }
    }

    /**
     * @return array<int, array{cells: array<int, string>, isHeader: bool}>
     */
    private function extractTableRows(array $node): array
    {
        $rows = [];
        $this->collectTableRows($node, $rows);
        return $rows;
    }

    /**
     * @param array<int, array{cells: array<int, string>, isHeader: bool}> $rows
     */
    private function collectTableRows(array $node, array &$rows): void
    {
        if (($node['type'] ?? '') !== 'element') {
            return;
        }
        $tag = strtolower((string)($node['tag'] ?? ''));
        if ($tag === 'tr') {
            $row = [];
            $isHeader = true;
            foreach ($node['children'] ?? [] as $child) {
                if (($child['type'] ?? '') !== 'element') {
                    continue;
                }
                $cellTag = strtolower((string)($child['tag'] ?? ''));
                if ($cellTag !== 'td' && $cellTag !== 'th') {
                    continue;
                }
                if ($cellTag === 'td') {
                    $isHeader = false;
                }
                $row[] = $this->normalizeWhitespace($this->collectPlainText($child));
            }
            if ($row !== []) {
                $rows[] = [
                    'cells' => $row,
                    'isHeader' => $isHeader,
                ];
            }
            return;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (($child['type'] ?? '') === 'element') {
                $this->collectTableRows($child, $rows);
            }
        }
    }

    private function collectPlainText(array $node): string
    {
        $buffer = '';
        foreach ($node['children'] ?? [] as $child) {
            $type = $child['type'] ?? '';
            if ($type === 'text') {
                $buffer .= ' ' . ($child['text'] ?? '');
            } elseif ($type === 'element') {
                $buffer .= ' ' . $this->collectPlainText($child);
            }
        }
        return $buffer;
    }

    private function normalizeWhitespace(string $text): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }
        return preg_replace('/\s+/', ' ', $trimmed) ?? '';
    }
}
