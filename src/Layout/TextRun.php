<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Style\ComputedStyle;

final readonly class TextRun implements \JsonSerializable
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $baseline,
        public string $text,
        public float $fontSize,
        public ComputedStyle $style,
        /**
         * Extra advance each space in this run carries because the line is justified. It only
         * exists in the layout's x arithmetic, so the serializer has to reproduce it (as `Tw`,
         * or as a TJ adjustment for embedded fonts) or the drawn line falls short of the margin.
         */
        public float $justificationWordSpacing = 0.0,
        /**
         * The `background-color` of the innermost inline element (`<span>`, `<mark>`...) this
         * text sits in, if any. The run's own style is the text node's, which only receives
         * inherited properties, so a non-inherited background has to be carried here for
         * DisplayListBuilder to paint it behind the glyphs.
         */
        public ?string $inlineBackground = null,
        /**
         * Border and horizontal padding of that same innermost inline element, painted around
         * the same ascent/descent band `inlineBackground` uses. Like the background, this is a
         * paint-only decoration: it does not widen the box the way a block element's border and
         * padding do, so `padding-left`/`padding-right` on a `<span>` do not actually push
         * neighbouring inline content aside — a border or background band drawn snug against the
         * text, with no breathing room around it, is what would otherwise result, which is why
         * this exists rather than leaving inline border/padding unpainted altogether.
         */
        public ?string $inlineBorderColor = null,
        public float $inlineBorderWidth = 0.0,
        public float $inlinePaddingLeft = 0.0,
        public float $inlinePaddingRight = 0.0,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'baseline' => $this->baseline,
            'text' => $this->text,
            'fontSize' => $this->fontSize,
            'style' => $this->style,
            'justificationWordSpacing' => $this->justificationWordSpacing,
            'inlineBackground' => $this->inlineBackground,
            'inlineBorderColor' => $this->inlineBorderColor,
            'inlineBorderWidth' => $this->inlineBorderWidth,
            'inlinePaddingLeft' => $this->inlinePaddingLeft,
            'inlinePaddingRight' => $this->inlinePaddingRight,
        ];
    }
}
