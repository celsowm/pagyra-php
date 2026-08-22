<?php

declare(strict_types=1);

namespace Pagyra\Image;

final class ReplacedElementSizingResolver
{
    public function resolve(
        float $intrinsicWidth,
        float $intrinsicHeight,
        ?float $specifiedWidth = null,
        ?float $specifiedHeight = null,
        string $boxSizing = 'content-box',
        float $horizontalExtras = 0.0,
        float $verticalExtras = 0.0,
        ?float $minWidth = null,
        ?float $maxWidth = null,
        ?float $minHeight = null,
        ?float $maxHeight = null,
    ): ReplacedElementSize {
        $intrinsicWidth = max(0.0, $intrinsicWidth);
        $intrinsicHeight = max(0.0, $intrinsicHeight);
        $horizontalExtras = max(0.0, $horizontalExtras);
        $verticalExtras = max(0.0, $verticalExtras);

        $hasIntrinsic = $intrinsicWidth > 0.0 && $intrinsicHeight > 0.0;
        $hasWidth = $specifiedWidth !== null;
        $hasHeight = $specifiedHeight !== null;

        $width = $hasWidth
            ? $this->contentDimension($specifiedWidth, $boxSizing, $horizontalExtras)
            : $intrinsicWidth;
        $height = $hasHeight
            ? $this->contentDimension($specifiedHeight, $boxSizing, $verticalExtras)
            : $intrinsicHeight;

        if ($hasIntrinsic && $hasWidth && !$hasHeight) {
            $height = $this->scaled($intrinsicHeight, $width, $intrinsicWidth);
        } elseif ($hasIntrinsic && !$hasWidth && $hasHeight) {
            $width = $this->scaled($intrinsicWidth, $height, $intrinsicHeight);
        }

        [$width, $height] = $this->applyWidthConstraint(
            $width,
            $height,
            $minWidth,
            true,
            $boxSizing,
            $horizontalExtras,
            $hasIntrinsic && !$hasHeight,
        );
        [$width, $height] = $this->applyWidthConstraint(
            $width,
            $height,
            $maxWidth,
            false,
            $boxSizing,
            $horizontalExtras,
            $hasIntrinsic && !$hasHeight,
        );
        [$width, $height] = $this->applyHeightConstraint(
            $width,
            $height,
            $minHeight,
            true,
            $boxSizing,
            $verticalExtras,
            $hasIntrinsic && !$hasWidth,
        );
        [$width, $height] = $this->applyHeightConstraint(
            $width,
            $height,
            $maxHeight,
            false,
            $boxSizing,
            $verticalExtras,
            $hasIntrinsic && !$hasWidth,
        );

        return new ReplacedElementSize(max(0.0, $width), max(0.0, $height));
    }

    private function contentDimension(float $specified, string $boxSizing, float $extras): float
    {
        $specified = max(0.0, $specified);
        return strtolower($boxSizing) === 'border-box'
            ? max(0.0, $specified - $extras)
            : $specified;
    }

    private function scaled(float $value, float $target, float $source): float
    {
        if ($source <= 0.0) {
            return 0.0;
        }

        return max(1.0, round($value * ($target / $source)));
    }

    private function applyWidthConstraint(
        float $width,
        float $height,
        ?float $constraint,
        bool $minimum,
        string $boxSizing,
        float $extras,
        bool $lockAspect,
    ): array {
        if ($constraint === null) {
            return [$width, $height];
        }

        $limit = $this->contentDimension($constraint, $boxSizing, $extras);
        $violates = $minimum ? $width < $limit : $width > $limit;
        if (!$violates) {
            return [$width, $height];
        }

        if ($lockAspect && $width > 0.0) {
            $height = max(1.0, round($height * ($limit / $width)));
        }

        return [$limit, $height];
    }

    private function applyHeightConstraint(
        float $width,
        float $height,
        ?float $constraint,
        bool $minimum,
        string $boxSizing,
        float $extras,
        bool $lockAspect,
    ): array {
        if ($constraint === null) {
            return [$width, $height];
        }

        $limit = $this->contentDimension($constraint, $boxSizing, $extras);
        $violates = $minimum ? $height < $limit : $height > $limit;
        if (!$violates) {
            return [$width, $height];
        }

        if ($lockAspect && $height > 0.0) {
            $width = max(1.0, round($width * ($limit / $height)));
        }

        return [$width, $limit];
    }
}
