<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Pagination\BlockFragment;

/**
 * Resolves the simplified stacking-context model already used by pagyra-js:
 *
 * - a positioned element with numeric z-index establishes a context;
 * - context roots compete in negative / normal-auto / non-negative phases;
 * - descendants whose ancestors do not establish a context participate directly in the nearest
 *   ancestor context instead of being trapped in recursive DOM paint order;
 * - equal z-index values keep document order.
 *
 * The output is structural paint steps rather than a reordered tree. That lets DisplayListBuilder
 * paint a promoted descendant independently while re-applying overflow clips from the ancestors
 * it crossed.
 */
final class StackingOrderResolver
{
    /**
     * Resolve all descendants of one already-painted stacking-context root.
     *
     * @param list<BlockFragment> $fragments
     * @return list<StackingPaintStep>
     */
    public function plan(array $fragments): array
    {
        $negative = [];
        $normal = [];
        $positive = [];
        $order = 0;

        foreach ($fragments as $fragment) {
            $this->collectInContext($fragment, [], $negative, $normal, $positive, $order);
        }

        return $this->orderedSteps($negative, $normal, $positive);
    }

    /**
     * @param list<BlockFragment> $ancestors
     * @param list<array{fragment:BlockFragment,ancestors:list<BlockFragment>,z:int,order:int}> $negative
     * @param list<array{fragment:BlockFragment,ancestors:list<BlockFragment>,z:int,order:int}> $normal
     * @param list<array{fragment:BlockFragment,ancestors:list<BlockFragment>,z:int,order:int}> $positive
     */
    private function collectInContext(
        BlockFragment $fragment,
        array $ancestors,
        array &$negative,
        array &$normal,
        array &$positive,
        int &$order,
    ): void {
        $z = $this->contextZIndex($fragment);
        $entry = [
            'fragment' => $fragment,
            'ancestors' => $ancestors,
            'z' => $z ?? 0,
            'order' => $order++,
        ];

        if ($z !== null) {
            if ($z < 0) $negative[] = $entry;
            else $positive[] = $entry;
            // A nested context is atomic to this context. Its descendants are resolved only when
            // that context itself is emitted.
            return;
        }

        $normal[] = $entry;
        $nextAncestors = [...$ancestors, $fragment];
        foreach ($fragment->children as $child) {
            $this->collectInContext($child, $nextAncestors, $negative, $normal, $positive, $order);
        }
    }

    /**
     * @param list<array{fragment:BlockFragment,ancestors:list<BlockFragment>,z:int,order:int}> $negative
     * @param list<array{fragment:BlockFragment,ancestors:list<BlockFragment>,z:int,order:int}> $normal
     * @param list<array{fragment:BlockFragment,ancestors:list<BlockFragment>,z:int,order:int}> $positive
     * @return list<StackingPaintStep>
     */
    private function orderedSteps(array $negative, array $normal, array $positive): array
    {
        $byZThenDocumentOrder = static function (array $a, array $b): int {
            $z = $a['z'] <=> $b['z'];
            return $z !== 0 ? $z : ($a['order'] <=> $b['order']);
        };
        usort($negative, $byZThenDocumentOrder);
        usort($positive, $byZThenDocumentOrder);

        $steps = [];
        foreach ($negative as $entry) {
            array_push($steps, ...$this->contextSteps($entry['fragment'], $entry['ancestors']));
        }
        foreach ($normal as $entry) {
            $steps[] = new StackingPaintStep($entry['fragment'], $entry['ancestors']);
        }
        foreach ($positive as $entry) {
            array_push($steps, ...$this->contextSteps($entry['fragment'], $entry['ancestors']));
        }

        return $steps;
    }

    /**
     * Resolve one nested stacking context. Its root paints atomically as the context anchor, then
     * its descendants are resolved against that root as a fresh context.
     *
     * @param list<BlockFragment> $ancestors
     * @return list<StackingPaintStep>
     */
    private function contextSteps(BlockFragment $root, array $ancestors): array
    {
        $steps = [new StackingPaintStep($root, $ancestors)];

        $negative = [];
        $normal = [];
        $positive = [];
        $order = 0;
        $nextAncestors = [...$ancestors, $root];

        foreach ($root->children as $child) {
            $this->collectInContext($child, $nextAncestors, $negative, $normal, $positive, $order);
        }

        array_push($steps, ...$this->orderedSteps($negative, $normal, $positive));
        return $steps;
    }

    /**
     * Numeric z-index only creates a context on positioned boxes, matching the current
     * pagyra-js getStackingFlags() contract.
     */
    private function contextZIndex(BlockFragment $fragment): ?int
    {
        $style = $fragment->node->source->style;
        $position = strtolower(trim($style->get('position', 'static') ?? 'static'));
        if (!in_array($position, ['relative', 'absolute', 'fixed', 'sticky'], true)) {
            return null;
        }

        $raw = strtolower(trim($style->get('z-index', 'auto') ?? 'auto'));
        if ($raw === 'auto' || preg_match('/^-?\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }
}
