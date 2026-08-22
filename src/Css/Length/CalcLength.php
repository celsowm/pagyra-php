<?php

declare(strict_types=1);

namespace Pagyra\Css\Length;

final readonly class CalcLength
{
    public function __construct(
        public float $px = 0.0,
        public float $percent = 0.0,
        public float $em = 0.0,
        public float $rem = 0.0,
        public float $cqw = 0.0,
        public float $cqh = 0.0,
        public float $cqi = 0.0,
        public float $cqb = 0.0,
        public float $cqmin = 0.0,
        public float $cqmax = 0.0,
    ) {
    }

    public function withResolvedFonts(float $fontSize, float $rootFontSize): self
    {
        return new self(
            px: $this->px + ($this->em * $fontSize) + ($this->rem * $rootFontSize),
            percent: $this->percent,
            cqw: $this->cqw,
            cqh: $this->cqh,
            cqi: $this->cqi,
            cqb: $this->cqb,
            cqmin: $this->cqmin,
            cqmax: $this->cqmax,
        );
    }

    public function resolve(float $reference, float $containerWidth, float $containerHeight): float
    {
        return $this->px
            + ($this->percent * $reference)
            + (($this->cqw + $this->cqi) * $containerWidth)
            + (($this->cqh + $this->cqb) * $containerHeight)
            + ($this->cqmin * min($containerWidth, $containerHeight))
            + ($this->cqmax * max($containerWidth, $containerHeight));
    }
}
