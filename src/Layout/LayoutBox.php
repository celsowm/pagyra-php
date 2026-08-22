<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Geometry\Edges;
use Pagyra\Geometry\Rect;

final readonly class LayoutBox implements \JsonSerializable
{
    public function __construct(
        public Rect $content,
        public Edges $padding = new Edges(),
        public Edges $border = new Edges(),
        public Edges $margin = new Edges(),
    ) {
    }

    public function paddingBox(): Rect
    {
        return new Rect(
            $this->content->x - $this->padding->left,
            $this->content->y - $this->padding->top,
            $this->content->width + $this->padding->horizontal(),
            $this->content->height + $this->padding->vertical(),
        );
    }

    public function borderBox(): Rect
    {
        $padding = $this->paddingBox();

        return new Rect(
            $padding->x - $this->border->left,
            $padding->y - $this->border->top,
            $padding->width + $this->border->horizontal(),
            $padding->height + $this->border->vertical(),
        );
    }

    public function marginBox(): Rect
    {
        $border = $this->borderBox();

        return new Rect(
            $border->x - $this->margin->left,
            $border->y - $this->margin->top,
            $border->width + $this->margin->horizontal(),
            $border->height + $this->margin->vertical(),
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'content' => $this->content,
            'paddingBox' => $this->paddingBox(),
            'borderBox' => $this->borderBox(),
            'marginBox' => $this->marginBox(),
            'padding' => [
                'top' => $this->padding->top,
                'right' => $this->padding->right,
                'bottom' => $this->padding->bottom,
                'left' => $this->padding->left,
            ],
            'border' => [
                'top' => $this->border->top,
                'right' => $this->border->right,
                'bottom' => $this->border->bottom,
                'left' => $this->border->left,
            ],
            'margin' => [
                'top' => $this->margin->top,
                'right' => $this->margin->right,
                'bottom' => $this->margin->bottom,
                'left' => $this->margin->left,
            ],
        ];
    }
}
