<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Html\Style\Layout;

use Celsowm\PagyraPhp\Html\HtmlDocument;

final class FlowComposer
{
    /** @var array<string, array<string, mixed>> */
    private array $imageResources = [];
    /**
     * @param array<string, ComputedStyle> $styles
     * @return array<int, array<string, mixed>>
     */
    public function compose(HtmlDocument $document, array $styles, array $imageResources = []): array
    {
        $flows = [];
        $__registry = [];
        $this->imageResources = $imageResources;
        $document->eachElement(function (array $node) use (&$flows, $styles, & $__registry): void {
            $tag = strtolower((string)($node['tag'] ?? ''));
            $nidTmp = (string)($node['nodeId'] ?? ''); if ($nidTmp !== '') { $__registry[$nidTmp] = $node; }
            if ($tag === 'style' || $tag === 'script') {
                return;
            }

            if ($tag === 'img') {
                $nodeId = (string)($node['nodeId'] ?? '');
                if ($nodeId === '') {
                    return;
                }

                $ancestorIds = [];
                foreach (($node['ancestors'] ?? []) as $__anc) {
                    $aid = (string)($__anc['nodeId'] ?? '');
                    if ($aid !== '') {
                        $ancestorIds[] = $aid;
                    }
                }

                $flows[] = [
                    'type' => 'block',
                    'tag' => 'img',
                    'nodeId' => $nodeId,
                    'runs' => [],
                    'style' => $styles[$nodeId] ?? null,
                    'attributes' => $node['attributes'] ?? [],
                    'image' => $this->imageResources[$nodeId] ?? null,
                    'ancestorIds' => $ancestorIds,
                ];
                return;
            }

            if ($tag === 'table') {
                $rows = $this->extractTableRows($node);
                if ($rows === []) {
                    return;
                }
                $nodeId = (string)($node['nodeId'] ?? '');
                $ancestorIds = [];
            foreach (($node['ancestors'] ?? []) as $__anc) {
                $aid = (string)($__anc['nodeId'] ?? '');
                if ($aid !== '') { $ancestorIds[] = $aid; }
            }
            // compute ancestorIds
            $ancestorIds = [];
            foreach (($node['ancestors'] ?? []) as $__anc) {
                $aid = (string)($__anc['nodeId'] ?? ''); if ($aid !== '') { $ancestorIds[] = $aid; }
            }
            $flows[] = [
                    'type' => 'table',
                    'tag' => 'table',
                    'nodeId' => $nodeId,
                    'rows' => $rows,
                    'style' => $styles[$nodeId] ?? null,
                'ancestorIds' => $ancestorIds,
                'ancestorIds' => $ancestorIds,
                ];
                return;
            }

            if ($tag === 'ul' || $tag === 'ol') {
                if ($this->hasAncestorTag($node, 'table') || $this->hasAncestorTag($node, 'ul') || $this->hasAncestorTag($node, 'ol')) {
                    return;
                }
                $nodeId = (string)($node['nodeId'] ?? '');
                if ($nodeId === '') {
                    return;
                }
                $items = $this->collectListItems($node, $styles);
                if ($items === []) {
                    return;
                }
                $flows[] = [
                    'type' => 'list',
                    'tag' => $tag,
                    'nodeId' => $nodeId,
                    'items' => $items,
                    'style' => $styles[$nodeId] ?? null,
                    'attributes' => $node['attributes'] ?? [],
                ];
                return;
            }

            if ($tag === 'li') {
                return;
            }

            if (!$this->isBlockTag($tag)) {
                return;
            }

            if ($this->hasAncestorTag($node, 'table')) {
                return;
            }

            // REMOVED THE DIV CHECK that was here before.

            $nodeId = (string)($node['nodeId'] ?? '');
            if ($nodeId === '') {
                return;
            }

            $runs = $this->collectRuns($node);

            // --- START OF THE CRITICAL FIX ---
            // A block is valid if it has text (runs) OR if it has styling
            // that makes it visible (background, padding, border etc.)
            $style = $styles[$nodeId] ?? null;
            $hasVisibleStyle = false;
            if ($style) {
                $map = $style->toArray();
                $hasVisibleStyle = isset($map['background']) || isset($map['background-color']) || isset($map['background-image']) || !empty($map['padding']) || !empty($map['border']);
            }

            if ($runs === [] && !$hasVisibleStyle) {
                return;
            }
            // --- END OF THE CRITICAL FIX ---

            $flows[] = [
                'type' => 'block',
                'tag' => $tag,
                'nodeId' => $nodeId,
                'runs' => $runs,
                'style' => $styles[$nodeId] ?? null,
            ];
        });

        $flows = $this->reparentUsingAncestors($flows);
        return $flows;
    }

    private function isBlockTag(string $tag): bool
    {
        return in_array($tag, ['p', 'div', 'li', 'blockquote', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol'], true);
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

    

    private function hasBlockAncestor(array $node): bool
    {
        foreach ($node['ancestors'] ?? [] as $ancestor) {
            $atag = strtolower((string)($ancestor['tag'] ?? ''));
            if ($this->isBlockTag($atag)) {
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

            if ($childTag === 'img') {
                $alt = trim((string)($child['attributes']['alt'] ?? ''));
                if ($alt !== '') {
                    $runs[] = [
                        'text' => $alt,
                        'styleChain' => $chain,
                        'linkNodeId' => $linkNodeId,
                    ];
                }
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
     * @param array<string, mixed> $node
     * @param array<string, ComputedStyle> $styles
     * @return array<int, array<string, mixed>>
     */
    private function collectListItems(array $node, array $styles): array
    {
        $items = [];
        foreach ($node['children'] ?? [] as $child) {
            if (($child['type'] ?? '') !== 'element') {
                continue;
            }
            $childTag = strtolower((string)($child['tag'] ?? ''));
            if ($childTag !== 'li') {
                continue;
            }
            $itemId = (string)($child['nodeId'] ?? '');
            if ($itemId === '') {
                continue;
            }
            $runs = $this->collectRuns($child);
            $item = [
                'nodeId' => $itemId,
                'runs' => $runs,
                'attributes' => $child['attributes'] ?? [],
                'style' => $styles[$itemId] ?? null,
            ];
            $nestedLists = [];
            foreach ($child['children'] ?? [] as $grandchild) {
                if (($grandchild['type'] ?? '') !== 'element') {
                    continue;
                }
                $grandTag = strtolower((string)($grandchild['tag'] ?? ''));
                if ($grandTag !== 'ul' && $grandTag !== 'ol') {
                    continue;
                }
                $grandId = (string)($grandchild['nodeId'] ?? '');
                if ($grandId === '') {
                    continue;
                }
                $grandItems = $this->collectListItems($grandchild, $styles);
                if ($grandItems === []) {
                    continue;
                }
                $nestedLists[] = [
                    'tag' => $grandTag,
                    'nodeId' => $grandId,
                    'items' => $grandItems,
                    'attributes' => $grandchild['attributes'] ?? [],
                    'style' => $styles[$grandId] ?? null,
                ];
            }
            if ($nestedLists !== []) {
                $item['children'] = $nestedLists;
            }
            if ($runs === [] && $nestedLists === []) {
                continue;
            }
            $items[] = $item;
        }

        return $items;
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
            $nidTmp = (string)($node['nodeId'] ?? ''); if ($nidTmp !== '') { $__registry[$nidTmp] = $node; }
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
                $cellId = (string)($child['nodeId'] ?? '');
                $row[] = [
                    'text' => $this->normalizeWhitespace($this->collectPlainText($child)),
                    'nodeId' => $cellId,
                    'tag' => $cellTag,
                ];
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


    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectBlockChildren(array $node, array $styles): array
    {
        $childrenFlows = [];
        foreach ($node['children'] ?? [] as $child) {
            if (($child['type'] ?? '') !== 'element') {
                continue;
            }
            $tag = strtolower((string)($child['tag'] ?? ''));

            if ($tag === 'style' || $tag === 'script') {
                continue;
            }

            if ($tag === 'img') {
                $childrenFlows[] = [
                    'type'       => 'block',
                    'tag'        => 'img',
                    'nodeId'     => $childId,
                    'runs'       => [],
                    'style'      => $styles[$childId] ?? null,
                    'attributes' => $child['attributes'] ?? [],
                    'image'      => $this->imageResources[$childId] ?? null,
                ];
                continue;
            }

            $childId = (string)($child['nodeId'] ?? '');
            if ($childId === '') continue;

            // Lists
            if ($tag === 'ul' || $tag === 'ol') {
                $items = $this->collectListItems($child, $styles);
                if ($items !== []) {
                    $childrenFlows[] = [
                        'type'       => 'list',
                        'tag'        => $tag,
                        'nodeId'     => $childId,
                        'items'      => $items,
                        'style'      => $styles[$childId] ?? null,
                        'attributes' => $child['attributes'] ?? [],
                    ];
                }
                continue;
            }

            // Tables
            if ($tag === 'table') {
                $rows = $this->extractTableRows($child);
                if ($rows !== []) {
                    $childrenFlows[] = [
                        'type'       => 'table',
                        'tag'        => $tag,
                        'nodeId'     => $childId,
                        'rows'       => $rows,
                        'style'      => $styles[$childId] ?? null,
                        'attributes' => $child['attributes'] ?? [],
                    ];
                }
                continue;
            }

            // Generic block elements
            if ($this->isBlockTag($tag)) {
                $runs = $this->collectRuns($child);
                $childFlow = [
                    'type'       => 'block',
                    'tag'        => $tag,
                    'nodeId'     => $childId,
                    'runs'       => $runs,
                    'style'      => $styles[$childId] ?? null,
                    'attributes' => $child['attributes'] ?? [],
                ];
                $grand = $this->collectBlockChildren($child, $styles);
                if ($grand !== []) {
                    $childFlow['children'] = $grand;
                }
                if ($runs !== [] || $grand !== []) {
                    $childrenFlows[] = $childFlow;
                }
            }
        }
        return $childrenFlows;
    }



    /**
     * Post-process flat top-level flows to re-parent child blocks based on ancestorIds (nearest ancestor among flows).
     * This recovers hierarchy even if Document::eachElement() yielded only top-level elements.
     *
     * @param array<int, array<string,mixed>> $flows
     * @return array<int, array<string,mixed>>
     */
    private function reparentUsingAncestors(array $flows): array
    {
        // Map nodeId => index
        $byId = [];
        foreach ($flows as $idx => $f) {
            $nid = (string)($f['nodeId'] ?? '');
            if ($nid !== '') $byId[$nid] = $idx;
        }

        $toRemove = [];
        for ($i = 0; $i < count($flows); $i++) {
            $f = $flows[$i];
            $nid = (string)($f['nodeId'] ?? '');
            if ($nid === '') continue;
            $anc = $f['ancestorIds'] ?? null;
            $parentIdx = null;

            // Prefer the nearest ancestor (last in list) that is at top-level
            if (is_array($anc) && $anc !== []) {
                for ($k = count($anc)-1; $k >= 0; $k--) {
                    $cand = (string)$anc[$k];
                    if ($cand !== '' && isset($byId[$cand])) {
                        $parentIdx = $byId[$cand];
                        break;
                    }
                }
            }

            // Fallback heuristic: if previous flow looks like a container (div/section/etc.) with visible style, adopt
            if ($parentIdx === null && $i > 0) {
                $prev = $flows[$i-1];
                $ptag = strtolower((string)($prev['tag'] ?? ''));
                $pstyle = $prev['style'] ?? null;
                $visible = false;
                if ($pstyle && method_exists($pstyle, 'toArray')) {
                    $map = $pstyle->toArray();
                    $visible = !empty($map['padding']) || !empty($map['background']) || !empty($map['background-image']) || !empty($map['border']);
                }
                $isContainer = in_array($ptag, ['div','section','article','main','header','footer','nav','aside'], true);
                if ($isContainer && $visible) {
                    $parentIdx = $i-1;
                }
            }

            if ($parentIdx !== null && $parentIdx !== $i) {
                if (!isset($flows[$parentIdx]['children']) || !is_array($flows[$parentIdx]['children'])) {
                    $flows[$parentIdx]['children'] = [];
                }
                $flows[$parentIdx]['children'][] = $f;
                $toRemove[$i] = true;
            }
        }

        // Build final list without moved children
        $out = [];
        foreach ($flows as $i => $f) {
            if (!isset($toRemove[$i])) $out[] = $f;
        }
        return $out;
    }
}



