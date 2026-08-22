<?php

declare(strict_types=1);

namespace Pagyra\Image;

use Pagyra\Geometry\Rect;

final class ObjectFitResolver
{
    public static function resolve(
        float $intrinsicWidth,
        float $intrinsicHeight,
        Rect $contentBox,
        ObjectFit $fit = ObjectFit::Fill,
        ?ObjectPosition $position = null,
    ): Rect {
        $position ??= new ObjectPosition();

        $intrinsicWidth = max(0.0, $intrinsicWidth);
        $intrinsicHeight = max(0.0, $intrinsicHeight);
        $containerWidth = max(0.0, $contentBox->width);
        $containerHeight = max(0.0, $contentBox->height);

        if (
            $fit === ObjectFit::Fill
            || $intrinsicWidth === 0.0
            || $intrinsicHeight === 0.0
            || $containerWidth === 0.0
            || $containerHeight === 0.0
        ) {
            return new Rect($contentBox->x, $contentBox->y, $contentBox->width, $contentBox->height);
        }

        $containScale = min(
            $containerWidth / $intrinsicWidth,
            $containerHeight / $intrinsicHeight,
        );
        $coverScale = max(
            $containerWidth / $intrinsicWidth,
            $containerHeight / $intrinsicHeight,
        );

        $scale = match ($fit) {
            ObjectFit::Contain => $containScale,
            ObjectFit::Cover => $coverScale,
            ObjectFit::ScaleDown => min(1.0, $containScale),
            ObjectFit::None => 1.0,
            ObjectFit::Fill => 1.0,
        };

        $width = $intrinsicWidth * $scale;
        $height = $intrinsicHeight * $scale;

        return new Rect(
            $contentBox->x + ($containerWidth - $width) * $position->x,
            $contentBox->y + ($containerHeight - $height) * $position->y,
            $width,
            $height,
        );
    }

    public static function needsClip(Rect $rect, Rect $contentBox): bool
    {
        return $rect->x < $contentBox->x
            || $rect->y < $contentBox->y
            || $rect->right() > $contentBox->right()
            || $rect->bottom() > $contentBox->bottom();
    }
}
