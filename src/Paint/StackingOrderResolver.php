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
     * @param list<array{subject:BlockFragment|PhysicalPageEntry,ancestors:list<BlockFragment|PhysicalPageEntry>,z:int,order:int,context:bool}> $negative
     * @param list<array{subject:BlockFragment|PhysicalPageEntry,ancestors:list<BlockFragment|PhysicalPageEntry>,z:int,order:int,context:bool}> $normal
     * @param list<array{subject:BlockFragment|PhysicalPageEntry,ancestors:list<BlockFragment|PhysicalPageEntry>,z:int,order:int,context:bool}> $positive
     */
    private function collectInContext(
        BlockFragment|PhysicalPageEntry $subject,
        array $ancestors,
        array &$negative,
        array &$normal,
        array &$positive,
        int &$order,
    ): void {
        [$establishesContext, $z] = $this->stackingFlags($subject);
        $entry = [
            'subject' => $subject,
            'ancestors' => $ancestors,
            'z' => $z ?? 0,
            'order' => $order++,
            'context' => $establishesContext,
        ];

        if ($establishesContext) {
            if ($z !== null && $z < 0) $negative[] = $entry;
            elseif ($z !== null) $positive[] = $entry;
            else $normal[] = $entry;
            return;
        }

        $normal[] = $entry;
        $nextAncestors = [...$ancestors, $subject];
        foreach ($this->childrenOf($subject) as $child) {
            $this->collectInContext($child, $nextAncestors, $negative, $normal, $positive, $order);
        }
    }

    /**
     * @param list<array{subject:BlockFragment|PhysicalPageEntry,ancestors:list<BlockFragment|PhysicalPageEntry>,z:int,order:int,context:bool}> $negative
     * @param list<array{subject:BlockFragment|PhysicalPageEntry,ancestors:list<BlockFragment|PhysicalPageEntry>,z:int,order:int,context:bool}> $normal
     * @param list<array{subject:BlockFragment|PhysicalPageEntry,ancestors:list<BlockFragment|PhysicalPageEntry>,z:int,order:int,context:bool}> $positive
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
            if ($entry['context'] ?? false) {
                array_push($steps, ...$this->contextSteps($entry['subject'], $entry['ancestors']));
            } else {
                $steps[] = new StackingPaintStep($entry['subject'], $entry['ancestors']);
            }
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

    /**
     * @return array{0:bool,1:?int} establishesContext, positioned numeric z-index
     */
    private function stackingFlags(BlockFragment|PhysicalPageEntry $subject): array
    {
        $style = $this->nodeOf($subject)->source->style;
        $position = strtolower(trim($style->get('position', 'static') ?? 'static'));
        $positioned = in_array($position, ['relative', 'absolute', 'fixed', 'sticky'], true);

        $rawZ = strtolower(trim($style->get('z-index', 'auto') ?? 'auto'));
        $numericZ = $positioned && preg_match('/^-?\d+$/', $rawZ) === 1
            ? (int) $rawZ
            : null;

        // CSS Color: any own opacity below 1 creates a stacking context. Use the authored/computed
        // opacity property, not x-opacity, because x-opacity is the inherited product used by the
        // current per-command alpha fallback; an ancestor's opacity must not make every descendant
        // establish another context.
        $rawOpacity = strtolower(trim($style->get('opacity', '1') ?? '1'));
        $ownOpacity = 1.0;
        if (preg_match('/^(\d*\.?\d+)(%)?$/', $rawOpacity, $m) === 1) {
            $ownOpacity = max(0.0, min(1.0, (float) $m[1] / (isset($m[2]) && $m[2] !== '' ? 100.0 : 1.0)));
        }

        return [$numericZ !== null || $ownOpacity < 1.0, $numericZ];
    }

}
