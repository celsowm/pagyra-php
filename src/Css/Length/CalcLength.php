<?php

declare(strict_types=1);

namespace Pagyra\Css\Length;

final readonly class CalcLength
{
    public function __construct(
        public float $px = 0.0,
        public float $percent = 0.0,
        public float $cqw = 0.0,
        public float $cqh = 0.0,
        public float $cqi = 0.0,
        public float $cqb = 0.0,
        public float $cqmin = 0.0,
        public float $cqmax = 0.0,
    ) {
    }

    public function resolve(
        float $percentBasis,
        float $containerWidth,
        float $containerHeight,
        ?float $inlineSize = null,
        ?float $blockSize = null,
    ): float {
        $inline = $inlineSize ?? $containerWidth;
        $block = $blockSize ?? $containerHeight;

        return $this->px
            + ($this->percent * $percentBasis)
            + ($this->cqw * $containerWidth)
            + ($this->cqh * $containerHeight)
            + ($this->cqi * $inline)
            + ($this->cqb * $block)
            + ($this->cqmin * min($inline, $block))
            + ($this->cqmax * max($inline, $block));
    }
}
