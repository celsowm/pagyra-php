<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Layout\LayoutNode;
use Pagyra\Pagination\BlockFragment;
use Pagyra\Pagination\PhysicalPageEntry;

/**
 * Resolves the simplified stacking-context model used by pagyra-js:
 *
 * - a positioned element with numeric z-index establishes a context;
 * - context roots compete in negative / normal-auto / non-negative phases;
 * - descendants whose ancestors do not establish a context participate directly in the nearest
 *   ancestor context instead of being trapped in recursive DOM paint order;
 * - equal z-index values keep document order.
 *
 * It accepts both top-level PhysicalPageEntry objects and descendant BlockFragments, so the page
 * itself acts as the root stacking context instead of each top-level placement being an isolated
 * paint island.
 *
 * @phpstan-type PaintSubject BlockFragment|PhysicalPageEntry
 * @phpstan-type Entry array{subject:PaintSubject,ancestors:list<PaintSubject>,z:int,order:int}
 */
final class StackingOrderResolver
{
    /**
     * Resolve all subjects participating in one already-painted stacking-context root.
     *
     * @param list<BlockFragment|PhysicalPageEntry> $subjects
     * @return list<StackingPaintStep>
     */
    public function plan(array $subjects): array
    {
        $negative = [];
        $normal = [];
        $positive = [];
        $order = 0;

        foreach ($subjects as $subject) {
            $this->collectInContext($subject, [], $negative, $normal, $positive, $order);
        }

        return $this->orderedSteps($negative, $normal, $positive);
    }

    /**
     * @param BlockFragment|PhysicalPageEntry $subject
     * @param list<BlockFragment|PhysicalPageEntry> $ancestors
     * @param list<array{subject:BlockFragment|PhysicalPageEntry,ancestors:list<BlockFragment|PhysicalPageEntry>,z:int,order:int}> $negative
     * @param list<array{subject:BlockFragment|PhysicalPageEntry,ancestors:list<BlockFragment|PhysicalPageEntry>,z:int,order:int}> $normal
     * @param list<array{subject:BlockFragment|PhysicalPageEntry,ancestors:list<BlockFragment|PhysicalPageEntry>,z:int,order:int}> $positive
     */
    private function collectInContext(
        BlockFragment|PhysicalPageEntry $subject,
        array $ancestors,
        array &$negative,
        array &$normal,
        array &$positive,
        int &$order,
    ): void {
        $z = $this->contextZIndex($subject);
        $entry = [
            'subject' => $subject,
            'ancestors' => $ancestors,
            'z' => $z ?? 0,
            'order' => $order++,
        ];

        if ($z !== null) {
            if ($z < 0) $negative[] = $entry;
            else $positive[] = $entry;
            return;
        }

        $normal[] = $entry;
        $nextAncestors = [...$ancestors, $subject];
        foreach ($this->childrenOf($subject) as $child) {
            $this->collectInContext($child, $nextAncestors, $negative, $normal, $positive, $order);
        }
    }

    /**
     * @param list<array{subject:BlockFragment|PhysicalPageEntry,ancestors:list<BlockFragment|PhysicalPageEntry>,z:int,order:int}> $negative
     * @param list<array{subject:BlockFragment|PhysicalPageEntry,ancestors:list<BlockFragment|PhysicalPageEntry>,z:int,order:int}> $normal
     * @param list<array{subject:BlockFragment|PhysicalPageEntry,ancestors:list<BlockFragment|PhysicalPageEntry>,z:int,order:int}> $positive
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
            array_push($steps, ...$this->contextSteps($entry['subject'], $entry['ancestors']));
        }
        foreach ($normal as $entry) {
            $steps[] = new StackingPaintStep($entry['subject'], $entry['ancestors']);
        }
        foreach ($positive as $entry) {
            array_push($steps, ...$this->contextSteps($entry['subject'], $entry['ancestors']));
        }

        return $steps;
    }

    /**
     * @param BlockFragment|PhysicalPageEntry $root
     * @param list<BlockFragment|PhysicalPageEntry> $ancestors
     * @return list<StackingPaintStep>
     */
    private function contextSteps(BlockFragment|PhysicalPageEntry $root, array $ancestors): array
    {
        $steps = [new StackingPaintStep($root, $ancestors)];

        $negative = [];
        $normal = [];
        $positive = [];
        $order = 0;
        $nextAncestors = [...$ancestors, $root];

        foreach ($this->childrenOf($root) as $child) {
            $this->collectInContext($child, $nextAncestors, $negative, $normal, $positive, $order);
        }

        array_push($steps, ...$this->orderedSteps($negative, $normal, $positive));
        return $steps;
    }

    /** @return list<BlockFragment> */
    private function childrenOf(BlockFragment|PhysicalPageEntry $subject): array
    {
        return $subject instanceof PhysicalPageEntry
            ? $subject->fragment->blocks
            : $subject->children;
    }

    private function nodeOf(BlockFragment|PhysicalPageEntry $subject): LayoutNode
    {
        return $subject instanceof PhysicalPageEntry
            ? $subject->placement->node
            : $subject->node;
    }

    private function contextZIndex(BlockFragment|PhysicalPageEntry $subject): ?int
    {
        $style = $this->nodeOf($subject)->source->style;
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
