<?php

declare(strict_types=1);

namespace Pagyra\Paint;

/**
 * 2D affine matrix in CSS/SVG coordinates:
 * x' = a*x + c*y + e; y' = b*x + d*y + f.
 */
final readonly class TransformMatrix implements \JsonSerializable
{
    public function __construct(
        public float $a = 1.0,
        public float $b = 0.0,
        public float $c = 0.0,
        public float $d = 1.0,
        public float $e = 0.0,
        public float $f = 0.0,
    ) {
    }

    public static function identity(): self
    {
        return new self();
    }

    public function multiply(self $other): self
    {
        return new self(
            a: $this->a * $other->a + $this->c * $other->b,
            b: $this->b * $other->a + $this->d * $other->b,
            c: $this->a * $other->c + $this->c * $other->d,
            d: $this->b * $other->c + $this->d * $other->d,
            e: $this->a * $other->e + $this->c * $other->f + $this->e,
            f: $this->b * $other->e + $this->d * $other->f + $this->f,
        );
    }

    public function isIdentity(float $epsilon = 1e-9): bool
    {
        return abs($this->a - 1.0) <= $epsilon
            && abs($this->b) <= $epsilon
            && abs($this->c) <= $epsilon
            && abs($this->d - 1.0) <= $epsilon
            && abs($this->e) <= $epsilon
            && abs($this->f) <= $epsilon;
    }

    public function jsonSerialize(): array
    {
        return ['a'=>$this->a,'b'=>$this->b,'c'=>$this->c,'d'=>$this->d,'e'=>$this->e,'f'=>$this->f];
    }
}
