<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Pagination\BlockFragment;

/**
 * One independently painted block in a resolved stacking-context order.
 *
 * @param list<BlockFragment> $ancestors Ancestor fragments between the current stacking-context
 * root and this block. They are retained so paint can re-apply overflow clips after a descendant
 * is promoted out of the ancestor's recursive paint position.
 */
final readonly class StackingPaintStep
{
    /** @param list<BlockFragment> $ancestors */
    public function __construct(
        public BlockFragment $fragment,
        public array $ancestors = [],
    ) {
    }
}
