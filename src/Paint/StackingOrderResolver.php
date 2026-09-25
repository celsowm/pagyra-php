<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Pagination\BlockFragment;

/**
 * Resolves the paint phases of sibling block fragments inside one local stacking scope.
 *
 * This is deliberately structural rather than a global display-list sort: each positioned box
 * with a numeric z-index remains atomic with its subtree, clips and nested ordering. The next
 * stacking slice can flatten non-context ancestors to match the reference's full context graph;
 * this class establishes the stable negative / normal / non-negative phase semantics first.
 */
final class StackingOrderResolver
{
    /**
     * @param list<BlockFragment> $fragments
     * @return list<BlockFragment>
     */
    public function order(array $fragments): array
    {
        if (count($fragments) < 2) return $fragments;

        $negative = [];
        $normal = [];
        $positive = [];

        foreach ($fragments as $index => $fragment) {
            $z = $this->numericPositionedZIndex($fragment);
            $entry = ['index' => $index, 'z' => $z ?? 0, 'fragment' => $fragment];

            if ($z === null) {
                $normal[] = $entry;
            } elseif ($z < 0) {
                $negative[] = $entry;
            } else {
                $positive[] = $entry;
            }
        }

        $byZThenDocumentOrder = static function (array $a, array $b): int {
            $z = $a['z'] <=> $b['z'];
            return $z !== 0 ? $z : ($a['index'] <=> $b['index']);
        };
        usort($negative, $byZThenDocumentOrder);
        usort($positive, $byZThenDocumentOrder);

        return array_map(
            static fn(array $entry): BlockFragment => $entry['fragment'],
            [...$negative, ...$normal, ...$positive],
        );
    }

    private function numericPositionedZIndex(BlockFragment $fragment): ?int
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
