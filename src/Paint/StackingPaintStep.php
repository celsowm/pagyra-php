<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Pagination\BlockFragment;
use Pagyra\Pagination\PhysicalPageEntry;

/**
 * One independently painted subject in resolved stacking-context order.
 *
 * A subject is either a top-level physical-page entry or a descendant block fragment. The
 * ancestor chain preserves the structural path crossed by context flattening so overflow clips
 * can be re-applied even when a positioned descendant paints far away from its DOM parent.
 *
 * @phpstan-type PaintSubject BlockFragment|PhysicalPageEntry
 */
final readonly class StackingPaintStep
{
    /**
     * @param BlockFragment|PhysicalPageEntry $subject
     * @param list<BlockFragment|PhysicalPageEntry> $ancestors
     */
    public function __construct(
        public BlockFragment|PhysicalPageEntry $subject,
        public array $ancestors = [],
    ) {
    }
}
