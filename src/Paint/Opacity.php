<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\Rgba;
use Pagyra\Style\ComputedStyle;

/**
 * `opacity` (CSS Color 3 §3.2) applied at paint time. The cascade folds each element's opacity
 * into the product with its ancestors' and carries it down as `x-opacity`, and every colour an
 * element paints — text, background, border, bullet, image — has its alpha multiplied by it.
 *
 * The spec composites the element as one group and then fades the group, which this per-item
 * fade only differs from where the element's own content overlaps itself; for text over its own
 * background, which is what documents do, the result is the same. It used to be ignored, so
 * `opacity: .3` watermarks and faded notes printed at full strength.
 */
final class Opacity
{
    public static function of(ComputedStyle $style): float
    {
        $raw = $style->get('x-opacity');

        return $raw === null ? 1.0 : max(0.0, min(1.0, (float) $raw));
    }

    public static function apply(?Rgba $color, ComputedStyle $style): ?Rgba
    {
        $opacity = self::of($style);
        if ($color === null || $opacity >= 1.0) {
            return $color;
        }

        return new Rgba($color->r, $color->g, $color->b, $color->a * $opacity);
    }
}
