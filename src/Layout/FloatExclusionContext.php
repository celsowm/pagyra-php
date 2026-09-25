<?php

declare(strict_types=1);

namespace Pagyra\Layout;

/**
 * Float state local to one block formatting context.
 *
 * The old engine kept raw exclusion rectangles directly on BlockLayoutEngine. That made BFC
 * lifetime, inherited exclusions and side-specific clearance implicit global state. This object
 * owns those concerns and can be copied when a descendant participates in the same surrounding
 * float context, or replaced when a new BFC isolates itself.
 */
final class FloatExclusionContext
{
    /** @var list<array{top:float,bottom:float,left:float,right:float}> */
    private array $exclusions;

    public function __construct(
        array $exclusions = [],
        private float $leftBottom = 0.0,
        private float $rightBottom = 0.0,
    ) {
        $this->exclusions = $exclusions;
    }

    public function copy(): self
    {
        return new self($this->exclusions, $this->leftBottom, $this->rightBottom);
    }

    public function register(FloatRun $float): void
    {
        if (!$float->active) return;

        $exclusion = [
            'top' => $float->startY,
            'bottom' => $float->bottom,
            'left' => $float->leftX,
            'right' => $float->rightX,
        ];
        if (!in_array($exclusion, $this->exclusions, true)) {
            $this->exclusions[] = $exclusion;
        }

        if ($float->leftBottom !== null) {
            $this->leftBottom = max($this->leftBottom, $float->leftBottom);
        }
        if ($float->rightBottom !== null) {
            $this->rightBottom = max($this->rightBottom, $float->rightBottom);
        }
    }

    /** @return list<array{top:float,bottom:float,left:float,right:float}> */
    public function exclusions(): array
    {
        return $this->exclusions;
    }

    public function clearanceBottom(string $clear): float
    {
        return match (strtolower(trim($clear))) {
            'left' => $this->leftBottom,
            'right' => $this->rightBottom,
            'both' => max($this->leftBottom, $this->rightBottom),
            default => 0.0,
        };
    }
}
