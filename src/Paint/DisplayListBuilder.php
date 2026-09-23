<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\ColorParser;
use Pagyra\Css\Color\Rgba;
use Pagyra\Fonts\TextMetrics;
use Pagyra\Geometry\Rect;
use Pagyra\Image\ImageMetadataReader;
use Pagyra\Image\ImageSourceBytesResolver;
use Pagyra\Image\ObjectFit;
use Pagyra\Image\ObjectFitResolver;
use Pagyra\Image\ObjectPositionParser;
use Pagyra\Layout\AtomicInlineBox;
use Pagyra\Layout\LayoutNode;
use Pagyra\Layout\LineBox;
use Pagyra\Layout\TextRun;
use Pagyra\Pagination\BlockFragment;
use Pagyra\Pagination\LineFragment;
use Pagyra\Pagination\PaginationResult;
use Pagyra\Pagination\PhysicalPageEntry;
use Pagyra\Style\ComputedStyle;
use Pagyra\Style\ListMarker;
use Pagyra\Style\StyledNode;

final class DisplayListBuilder
{
    private const EPSILON = 0.000001;
    private const INLINE_BACKGROUND_ASCENT = 0.9;
    private const INLINE_BACKGROUND_DESCENT = 0.22;

    private readonly ImageMetadataReader $imageMetadata;

    public function __construct(
        private readonly ?ImageSourceBytesResolver $imageBytes = null,
        private readonly ?TextMetrics $textMetrics = null,
    ) {
        $this->imageMetadata = new ImageMetadataReader();
    }

    /** @param array<string,mixed> $margins */
    public function build(
        PaginationResult $pagination,
        float $pageWidth,
        float $pageHeight,
        array $margins,
    ): DisplayList {
        $pages = [];
        foreach ($pagination->pages as $page) {
            $commands = [];
            $pageMargins = $this->marginsForPage($margins, $page->pageIndex);
            foreach ($page->entries as $entry) {
                $this->appendEntry($commands, $entry, $pagination, $pageMargins);
            }
            $pages[] = new PageDisplayList($page->pageIndex, $pageWidth, $pageHeight, $commands);
        }
        return new DisplayList($pages);
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendEntry(array &$commands, PhysicalPageEntry $entry, PaginationResult $pagination, array $margins): void
    {
        $node = $entry->placement->node;
        $pageIndex = $entry->fragment->pageIndex;
        $clip = $this->appendTopLevelBox($commands, $node, $pageIndex, $entry->placement->offsetY, $pagination, $margins);
        $this->appendLines($commands, $entry->fragment->lines, $margins);
        foreach ($entry->fragment->blocks as $block) $this->appendBlock($commands, $block, $margins);
        if ($clip) $commands[] = new ClipPaintCommand($pageIndex);
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendTopLevelBox(
        array &$commands,
        LayoutNode $node,
        int $pageIndex,
        float $offsetY,
        PaginationResult $pagination,
        array $margins,
    ): bool {
        $border = $node->box->borderBox();
        $continuousStart = $border->y + $offsetY;
        $continuousEnd = $border->bottom() + $offsetY;
        $pageStart = $pagination->flow->contentStartForPage($pageIndex);
        $pageEnd = $pageStart + $pagination->flow->usableHeightForPage($pageIndex);
        $start = max($continuousStart, $pageStart);
        $end = min($continuousEnd, $pageEnd);
        if ($end <= $start) return false;

        $x = $border->x + $margins['left'];
        $y = ($start - $pageStart) + $margins['top'];
        $width = $border->width;
        $height = $end - $start;
        $drawTop = abs($start - $continuousStart) <= self::EPSILON;
        $drawBottom = abs($end - $continuousEnd) <= self::EPSILON;
        $radius = $this->fragmentRadius(
            BorderRadiusResolver::resolve($node->source->style, $border->width, $border->height),
            $drawTop,
            $drawBottom,
        );

        $this->appendBoxShadows($commands, $node, $node->source->style, $pageIndex, $x, $y, $width, $height, BorderRadiusResolver::normalize($radius, $width, $height));
        $commands[] = new BoxPaintCommand(
            node: $node,
            pageIndex: $pageIndex,
            x: $x,
            y: $y,
            width: $width,
            height: $height,
            backgroundColor: Opacity::apply(ColorParser::parse($node->source->style->get('background-color')), $node->source->style),
            borderRadius: BorderRadiusResolver::normalize($radius, $width, $height),
        );
        $this->appendBackgroundImage($commands, $node->source, $node->source->style, $pageIndex, $x, $y, $width, $height, $node->box->border, $node->fontSize);
        $this->appendBorders($commands, $node, $pageIndex, $x, $y, $width, $height, $drawTop, $drawBottom);
        $this->appendOutline($commands, $node, $node->source->style, $pageIndex, $x, $y, $width, $height);

        return $this->openOverflowClip($commands, $node, $pageIndex, $x, $y, $width, $height, $drawTop, $drawBottom);
    }

    /**
     * `overflow: hidden` (or `clip`) on a box clips everything painted inside it to its padding
     * box (CSS Overflow 3 §3); the content of a box with a fixed height used to spill over the
     * boxes below it. Returns whether a clip was opened, which the caller closes after the
     * content.
     *
     * @param list<object> $commands
     */
    private function openOverflowClip(array &$commands, LayoutNode $node, int $pageIndex, float $x, float $y, float $width, float $height, bool $drawTop, bool $drawBottom): bool
    {
        $overflow = strtolower(trim($node->source->style->get('overflow') ?? 'visible'));
        $parts = preg_split('/\s+/', $overflow) ?: [];
        $clipX = in_array($parts[0] ?? '', ['hidden', 'clip', 'scroll', 'auto'], true);
        $clipY = in_array($parts[1] ?? $parts[0] ?? '', ['hidden', 'clip', 'scroll', 'auto'], true);
        foreach (['overflow-x' => &$clipX, 'overflow-y' => &$clipY] as $property => &$flag) {
            $value = strtolower(trim($node->source->style->get($property) ?? ''));
            if ($value !== '') $flag = in_array($value, ['hidden', 'clip', 'scroll', 'auto'], true);
        }
        unset($flag);
        if (!$clipX && !$clipY) return false;
        // A box split across pages is not clipped: pagination can push a line or a child past
        // the end of the fragment it belongs to, and clipping the fragment would hide that
        // content instead of the overflow the author meant to hide.
        if (!$drawTop || !$drawBottom) return false;

        $box = $node->box;
        $top = $drawTop ? $box->border->top : 0.0;
        $bottom = $drawBottom ? $box->border->bottom : 0.0;
        $unbounded = 1.0e5;
        $commands[] = new ClipPaintCommand(
            $pageIndex,
            $clipX ? $x + $box->border->left : $x - $unbounded,
            $clipY ? $y + $top : $y - $unbounded,
            $clipX ? max(0.0, $width - $box->border->horizontal()) : $width + 2 * $unbounded,
            $clipY ? max(0.0, $height - $top - $bottom) : $height + 2 * $unbounded,
        );

        return true;
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendBlock(array &$commands, BlockFragment $block, array $margins): void
    {
        $border = $block->node->box->borderBox();
        if ($block->height > 0.0) {
            $x = $border->x + $margins['left'];
            $y = $block->pageY + $margins['top'];
            $wholeBox = $block->height + self::EPSILON >= $border->height;
            $radius = $wholeBox
                ? BorderRadiusResolver::resolve($block->node->source->style, $border->width, $border->height)
                : new BorderRadius();
            $this->appendBoxShadows($commands, $block->node, $block->node->source->style, $block->pageIndex, $x, $y, $border->width, $block->height, BorderRadiusResolver::normalize($radius, $border->width, $block->height));
            $commands[] = new BoxPaintCommand(
                node: $block->node,
                pageIndex: $block->pageIndex,
                x: $x,
                y: $y,
                width: $border->width,
                height: $block->height,
                backgroundColor: Opacity::apply(ColorParser::parse($block->node->source->style->get('background-color')), $block->node->source->style),
                borderRadius: BorderRadiusResolver::normalize($radius, $border->width, $block->height),
            );
            $this->appendBackgroundImage($commands, $block->node->source, $block->node->source->style, $block->pageIndex, $x, $y, $border->width, $block->height, $block->node->box->border, $block->node->fontSize);

            $this->appendBorders(
                $commands,
                $block->node,
                $block->pageIndex,
                $x,
                $y,
                $border->width,
                $block->height,
                $wholeBox,
                $wholeBox,
            );
            $this->appendOutline($commands, $block->node, $block->node->source->style, $block->pageIndex, $x, $y, $border->width, $block->height);
            $clip = $this->openOverflowClip($commands, $block->node, $block->pageIndex, $x, $y, $border->width, $block->height, $wholeBox, $wholeBox);
        }
        $this->appendListMarker($commands, $block, $margins);
        $this->appendLines($commands, $block->lines, $margins);
        foreach ($block->children as $child) $this->appendBlock($commands, $child, $margins);
        if ($clip ?? false) $commands[] = new ClipPaintCommand($block->pageIndex);
    }

    /**
     * Paints the list-item marker string computed by StyleComputer (`x-list-marker`)
     * to the left of the item's first line, inside the list's left padding. This is
     * `list-style-position: outside` only; the marker is paint-only and adds no box,
     * mirroring pagyra-js's createListMarkerRun.
     *
     * @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands
     */
    private function appendListMarker(array &$commands, BlockFragment $block, array $margins): void
    {
        if ($this->textMetrics === null) return;
        $style = $block->node->source->style;
        $marker = $style->get('x-list-marker');
        if ($marker === null || $marker === '') return;

        $target = $this->firstLineFragmentForMarker($block);
        if ($target === null || $target->lineIndex !== 0) return;
        $run = $this->firstTextRunInLine($target->line);
        if ($run === null) return;

        $fontSize = $run->fontSize;
        $textStartX = $run->x + $margins['left'];
        $shape = ListMarker::bulletShape($marker);
        if ($shape !== null) {
            $this->appendBulletShape($commands, $shape, $style, $run, $target, $textStartX, $margins);
            return;
        }
        $markerWidth = max($this->textMetrics->measure($marker, $style, $fontSize)->inlineSize, 0.0);
        $gap = max($fontSize * 0.5, 6.0);
        $markerX = $textStartX - $gap - $markerWidth;

        $weightRaw = strtolower(trim($style->get('font-weight', '400') ?? '400'));
        $fontWeight = $weightRaw === 'bold' ? 700 : ($weightRaw === 'normal' ? 400 : (is_numeric($weightRaw) ? (int) $weightRaw : 400));

        $syntheticRun = new TextRun(
            x: $run->x - $gap - $markerWidth,
            y: $run->y,
            width: $markerWidth,
            height: $run->height,
            baseline: $run->baseline,
            text: $marker,
            fontSize: $fontSize,
            style: $style,
        );

        $commands[] = new TextPaintCommand(
            run: $syntheticRun,
            pageIndex: $target->pageIndex,
            x: $markerX,
            y: $target->pageY + ($run->y - $target->line->y) + $margins['top'],
            baseline: $target->pageBaseline + ($run->baseline - $target->line->baseline) + $margins['top'],
            text: $marker,
            fontSize: $fontSize,
            fontFamily: $style->get('font-family'),
            fontWeight: max(100, min(900, $fontWeight)),
            fontStyle: strtolower(trim($style->get('font-style', 'normal') ?? 'normal')),
            color: Opacity::apply(ColorParser::parse($style->get('color', 'black')), $style),
        );
    }

    /**
     * A disc, circle or square bullet drawn as a shape: 0.3em across, its top 0.45em above the
     * baseline and its right edge 0.5em before the text, which is where Chrome puts the bullet of
     * a 40px list item measured pixel by pixel. The circle is a ring of 0.06em.
     *
     * @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands
     */
    private function appendBulletShape(array &$commands, string $shape, ComputedStyle $style, TextRun $run, LineFragment $target, float $textStartX, array $margins): void
    {
        $color = Opacity::apply(ColorParser::parse($style->get('color', 'black')), $style);
        if ($color === null) return;
        $fontSize = $run->fontSize;
        $size = 0.3 * $fontSize;
        $x = $textStartX - 0.5 * $fontSize - $size;
        $baseline = $target->pageBaseline + ($run->baseline - $target->line->baseline) + $margins['top'];
        $y = $baseline - 0.45 * $fontSize;
        $bullet = new TextRun($x - $margins['left'], $run->y, $size, $run->height, $run->baseline, '', $fontSize, $style);
        $half = new CornerRadius($size / 2, $size / 2);
        $round = new BorderRadius($half, $half, $half, $half);

        if ($shape === 'circle') {
            $stroke = max(0.06 * $fontSize, 0.5);
            $innerHalf = new CornerRadius(max(0.0, $size / 2 - $stroke), max(0.0, $size / 2 - $stroke));
            $commands[] = new RoundedBorderPaintCommand(
                $bullet, $target->pageIndex, $x, $y, $size, $size, $stroke, $color,
                $round, new BorderRadius($innerHalf, $innerHalf, $innerHalf, $innerHalf),
            );
            return;
        }

        $commands[] = new BoxPaintCommand(
            node: $bullet,
            pageIndex: $target->pageIndex,
            x: $x,
            y: $y,
            width: $size,
            height: $size,
            backgroundColor: $color,
            borderRadius: $shape === 'disc' ? $round : new BorderRadius(),
        );
    }

    /**
     * `background-image: url(...)` (CSS Backgrounds 3 §3), positioned in the padding box by
     * `background-position` and `background-size` (`auto`, `cover`, `contain`, lengths and
     * percentages), tiled by `background-repeat` and clipped to the border box. The image is
     * drawn as ImagePaintCommands between a ClipPaintCommand pair; a box whose tiles would run
     * into the thousands is left with its background colour. Background images used to be
     * ignored entirely: the paint layer only knew `background-color`.
     *
     * @param list<object> $commands
     */
    private function appendBackgroundImage(array &$commands, StyledNode $source, ComputedStyle $style, int $pageIndex, float $x, float $y, float $width, float $height, \Pagyra\Geometry\Edges $border, float $fontSize): void
    {
        $raw = trim($style->get('background-image') ?? '');
        if ($raw === '' || strtolower($raw) === 'none' || $width <= 0.0 || $height <= 0.0) return;
        if (preg_match('/^(repeating-)?(linear|radial)-gradient\(/i', $raw) === 1) {
            $this->appendGradient($commands, $source, $style, $pageIndex, $x, $y, $width, $height, $raw);
            return;
        }
        if ($this->imageBytes === null || preg_match('/^url\(\s*([\'"]?)(.*?)\1\s*\)$/is', $raw, $m) !== 1) return;
        $bytes = $this->imageBytes->resolve($m[2]);
        if ($bytes === null) return;
        try {
            $metadata = $this->imageMetadata->read($bytes);
        } catch (\InvalidArgumentException) {
            return;
        }
        if (!in_array($metadata->format, ['jpeg', 'png'], true) || $metadata->width <= 0 || $metadata->height <= 0) return;

        // Positioning area: the padding box.
        $areaX = $x + $border->left;
        $areaY = $y + $border->top;
        $areaW = max(0.0, $width - $border->horizontal());
        $areaH = max(0.0, $height - $border->vertical());
        [$tileW, $tileH] = $this->backgroundTileSize($style->get('background-size') ?? 'auto', (float) $metadata->width, (float) $metadata->height, $areaW, $areaH, $fontSize);
        if ($tileW <= 0.0 || $tileH <= 0.0) return;
        [$posX, $posY] = $this->backgroundPosition($style->get('background-position') ?? '0% 0%', $areaW - $tileW, $areaH - $tileH, $fontSize);

        $repeat = preg_split('/\s+/', strtolower(trim($style->get('background-repeat') ?? 'repeat'))) ?: ['repeat'];
        [$repeatX, $repeatY] = match ($repeat[0]) {
            'repeat-x' => [true, false],
            'repeat-y' => [false, true],
            'no-repeat' => [false, false],
            default => [$repeat[0] !== 'no-repeat', ($repeat[1] ?? $repeat[0]) !== 'no-repeat'],
        };
        $startX = $areaX + $posX;
        $startY = $areaY + $posY;
        $xs = [$startX];
        $ys = [$startY];
        if ($repeatX) {
            $first = $startX - ceil(($startX - $x) / $tileW) * $tileW;
            $xs = range(0, (int) ceil(($x + $width - $first) / $tileW) - 1);
            $xs = array_map(static fn(int $i): float => $first + $i * $tileW, $xs);
        }
        if ($repeatY) {
            $first = $startY - ceil(($startY - $y) / $tileH) * $tileH;
            $ys = range(0, (int) ceil(($y + $height - $first) / $tileH) - 1);
            $ys = array_map(static fn(int $i): float => $first + $i * $tileH, $ys);
        }
        if (count($xs) * count($ys) > 2000) return;

        $box = new AtomicInlineBox($source, $x, $y, $width, $height, $style, $width, $height);
        $source = is_string($m[2]) ? $m[2] : '';
        $commands[] = new ClipPaintCommand($pageIndex, $x, $y, $width, $height);
        foreach ($ys as $tileY) {
            foreach ($xs as $tileX) {
                if ($tileX >= $x + $width || $tileY >= $y + $height || $tileX + $tileW <= $x || $tileY + $tileH <= $y) continue;
                $commands[] = new ImagePaintCommand($box, $pageIndex, $tileX, $tileY, $tileW, $tileH, $bytes, $metadata, $source, opacity: Opacity::of($style));
            }
        }
        $commands[] = new ClipPaintCommand($pageIndex);
    }

    /**
     * `linear-gradient()` and `radial-gradient()` backgrounds (CSS Images 3 §3), over the border
     * box. Linear gradients take an angle or a `to <side-or-corner>` (the corner case uses the
     * direction perpendicular to the other diagonal, as the spec defines it); radial ones take
     * `circle`/`ellipse`, the four extent keywords and `at <position>`. Stops take colours with
     * optional positions, the missing ones spread evenly. Two approximations: the PDF shading is
     * opaque, so a stop's alpha is folded into its colour over white paper; and the
     * `repeating-` variants are drawn once, without repeating.
     *
     * @param list<object> $commands
     */
    private function appendGradient(array &$commands, StyledNode $source, ComputedStyle $style, int $pageIndex, float $x, float $y, float $width, float $height, string $raw): void
    {
        if (preg_match('/^(?:repeating-)?(linear|radial)-gradient\((.*)\)$/is', trim($raw), $m) !== 1) return;
        $arguments = self::splitTopLevel($m[2], ',');
        if ($arguments === []) return;
        $kind = strtolower($m[1]);
        $first = strtolower(trim($arguments[0]));
        $firstIsStop = ColorParser::parse(self::splitTopLevel($arguments[0], ' ')[0] ?? '') !== null
            || str_starts_with($first, 'transparent') || str_starts_with($first, 'currentcolor');
        $shapeArgument = $firstIsStop ? null : array_shift($arguments);
        $opacity = Opacity::of($style);
        $currentColor = ColorParser::parse($style->get('color', 'black'));

        if ($kind === 'linear') {
            $direction = $this->gradientDirection($shapeArgument, $width, $height);
            if ($direction === null) return;
            [$dx, $dy] = $direction;
            $length = abs($width * $dx) + abs($height * $dy);
            $cx = $x + $width / 2;
            $cy = $y + $height / 2;
            $geometry = [$cx - $dx * $length / 2, $cy - $dy * $length / 2, $cx + $dx * $length / 2, $cy + $dy * $length / 2];
            $lineLength = $length;
        } else {
            [$cx, $cy, $rx, $ry] = $this->radialShape($shapeArgument, $x, $y, $width, $height);
            if ($rx <= 0.0 || $ry <= 0.0) return;
            $geometry = [$cx, $cy, $rx, $ry];
            $lineLength = $rx;
        }

        $stops = [];
        foreach ($arguments as $argument) {
            $parts = self::splitTopLevel($argument, ' ');
            if ($parts === []) continue;
            $colorToken = strtolower($parts[0]);
            $color = $colorToken === 'transparent' ? new \Pagyra\Css\Color\Rgba(255, 255, 255, 0.0)
                : ($colorToken === 'currentcolor' ? $currentColor : ColorParser::parse($parts[0]));
            if ($color === null) return;
            $alpha = $color->a * $opacity;
            $color = new \Pagyra\Css\Color\Rgba(
                $color->r * $alpha + 255.0 * (1.0 - $alpha),
                $color->g * $alpha + 255.0 * (1.0 - $alpha),
                $color->b * $alpha + 255.0 * (1.0 - $alpha),
                1.0,
            );
            $positions = array_slice($parts, 1, 2);
            if ($positions === []) {
                $stops[] = [null, $color];
            }
            foreach ($positions as $position) {
                $offset = str_ends_with($position, '%') ? (float) $position / 100.0 : ($lineLength > 0.0 ? $this->shadowLength($position, 16.0) / $lineLength : 0.0);
                $stops[] = [$offset, $color];
            }
        }
        if (count($stops) < 2) return;
        $stops[0][0] ??= 0.0;
        $stops[count($stops) - 1][0] ??= 1.0;
        // Fill the unpositioned stops evenly between their positioned neighbours, and keep the
        // positions from going backwards (CSS Images 3 §3.5.3).
        for ($i = 1, $count = count($stops); $i < $count; $i++) {
            if ($stops[$i][0] !== null) {
                $stops[$i][0] = max($stops[$i][0], $stops[$i - 1][0]);
                continue;
            }
            $next = $i;
            while ($stops[$next][0] === null) $next++;
            $end = max($stops[$next][0], $stops[$i - 1][0]);
            for ($k = $i; $k < $next; $k++) {
                $stops[$k][0] = $stops[$i - 1][0] + ($end - $stops[$i - 1][0]) * ($k - $i + 1) / ($next - $i + 1);
            }
        }

        $commands[] = new GradientPaintCommand($source, $pageIndex, $x, $y, $width, $height, $kind, $geometry, $stops);
    }

    /** @return array{0:float,1:float}|null unit vector of the gradient line, y pointing down */
    private function gradientDirection(?string $argument, float $width, float $height): ?array
    {
        $value = strtolower(trim($argument ?? 'to bottom'));
        if (str_starts_with($value, 'to ')) {
            $words = preg_split('/\s+/', substr($value, 3)) ?: [];
            $sx = in_array('right', $words, true) ? 1.0 : (in_array('left', $words, true) ? -1.0 : 0.0);
            $sy = in_array('bottom', $words, true) ? 1.0 : (in_array('top', $words, true) ? -1.0 : 0.0);
            if ($sx !== 0.0 && $sy !== 0.0) {
                $norm = hypot($height, $width);
                return $norm > 0.0 ? [$sx * $height / $norm, $sy * $width / $norm] : null;
            }
            return $sx === 0.0 && $sy === 0.0 ? null : [$sx, $sy];
        }
        if (preg_match('/^(-?\d*\.?\d+)(deg|rad|grad|turn)$/', $value, $m) !== 1) return null;
        $degrees = match ($m[2]) {
            'rad' => rad2deg((float) $m[1]),
            'grad' => (float) $m[1] * 0.9,
            'turn' => (float) $m[1] * 360.0,
            default => (float) $m[1],
        };
        $radians = deg2rad($degrees);

        return [sin($radians), -cos($radians)];
    }

    /** @return array{0:float,1:float,2:float,3:float} centre and radii of a radial gradient */
    private function radialShape(?string $argument, float $x, float $y, float $width, float $height): array
    {
        $value = strtolower(trim($argument ?? ''));
        $at = '50% 50%';
        if (preg_match('/\bat\s+(.*)$/', $value, $m) === 1) {
            $at = $m[1];
            $value = trim(substr($value, 0, -strlen($m[0])));
        }
        [$px, $py] = $this->backgroundPosition($at, $width, $height, 16.0);
        $cx = $x + $px;
        $cy = $y + $py;
        $circle = str_contains($value, 'circle');
        $left = $px;
        $right = $width - $px;
        $top = $py;
        $bottom = $height - $py;
        $extent = 'farthest-corner';
        foreach (['closest-side', 'closest-corner', 'farthest-side', 'farthest-corner'] as $keyword) {
            if (str_contains($value, $keyword)) $extent = $keyword;
        }
        $lengths = [];
        foreach (preg_split('/\s+/', $value) ?: [] as $token) {
            if (preg_match('/^\d*\.?\d+(px|pt|em|%)$/', $token) === 1) {
                $lengths[] = $token;
            }
        }
        if ($lengths !== []) {
            $rx = str_ends_with($lengths[0], '%') ? (float) $lengths[0] / 100.0 * $width : $this->shadowLength($lengths[0], 16.0);
            $ry = isset($lengths[1]) ? (str_ends_with($lengths[1], '%') ? (float) $lengths[1] / 100.0 * $height : $this->shadowLength($lengths[1], 16.0)) : $rx;
            return [$cx, $cy, $rx, $circle || !isset($lengths[1]) ? $rx : $ry];
        }
        $sideX = str_starts_with($extent, 'closest') ? min($left, $right) : max($left, $right);
        $sideY = str_starts_with($extent, 'closest') ? min($top, $bottom) : max($top, $bottom);
        if ($circle) {
            $r = str_ends_with($extent, 'side') ? (str_starts_with($extent, 'closest') ? min($sideX, $sideY) : max($sideX, $sideY)) : hypot($sideX, $sideY);
            return [$cx, $cy, $r, $r];
        }
        if (str_ends_with($extent, 'side')) return [$cx, $cy, $sideX, $sideY];

        // An ellipse through the corner, with the aspect ratio of the sides (CSS Images 3 §3.6.1).
        return [$cx, $cy, $sideX * M_SQRT2, $sideY * M_SQRT2];
    }

    /** @return array{0:float,1:float} */
    private function backgroundTileSize(string $size, float $imageW, float $imageH, float $areaW, float $areaH, float $fontSize): array
    {
        $parts = preg_split('/\s+/', strtolower(trim($size))) ?: ['auto'];
        if ($parts[0] === 'cover' || $parts[0] === 'contain') {
            $scale = $parts[0] === 'cover' ? max($areaW / $imageW, $areaH / $imageH) : min($areaW / $imageW, $areaH / $imageH);
            return [$imageW * $scale, $imageH * $scale];
        }
        $resolve = fn(string $v, float $reference): ?float => $v === 'auto' ? null
            : (str_ends_with($v, '%') ? (float) $v / 100.0 * $reference : $this->shadowLength($v, $fontSize));
        $w = $resolve($parts[0], $areaW);
        $h = $resolve($parts[1] ?? 'auto', $areaH);
        if ($w === null && $h === null) return [$imageW, $imageH];
        if ($w === null) return [$h * $imageW / $imageH, $h];
        if ($h === null) return [$w, $w * $imageH / $imageW];

        return [$w, $h];
    }

    /**
     * `background-position` as the offset of the image inside the positioning area; a
     * percentage (and the keywords) aligns that point of the image with that point of the area.
     *
     * @return array{0:float,1:float}
     */
    private function backgroundPosition(string $position, float $freeX, float $freeY, float $fontSize): array
    {
        $tokens = preg_split('/\s+/', strtolower(trim($position))) ?: [];
        $horizontal = null;
        $vertical = null;
        $plain = [];
        foreach ($tokens as $token) {
            if (in_array($token, ['left', 'right'], true)) $horizontal = $token;
            elseif (in_array($token, ['top', 'bottom'], true)) $vertical = $token;
            else $plain[] = $token;
        }
        $keyword = static fn(?string $k): ?string => match ($k) { 'left', 'top' => '0%', 'right', 'bottom' => '100%', 'center' => '50%', default => $k };
        $xValue = $horizontal !== null ? $keyword($horizontal) : $keyword($plain[0] ?? '0%');
        $yValue = $vertical !== null ? $keyword($vertical) : $keyword($horizontal !== null ? ($plain[0] ?? '50%') : ($plain[1] ?? ($plain === [] ? '0%' : '50%')));
        if ($vertical !== null && $horizontal === null && $plain !== []) $xValue = $keyword($plain[0]);
        if ($vertical !== null && $horizontal === null && $plain === []) $xValue = '50%';
        $resolve = fn(string $v, float $free): float => str_ends_with($v, '%') ? (float) $v / 100.0 * $free : $this->shadowLength($v, $fontSize);

        return [$resolve($xValue, $freeX), $resolve($yValue, $freeY)];
    }

    /**
     * `box-shadow` (CSS Backgrounds 3 §7.1), outer shadows only: each shadow is the border box
     * moved by its offsets and grown by its spread, in its colour, painted under the background.
     * The blur is approximated by stacked layers growing across the blur radius, each carrying an
     * equal share of the alpha, which gives the soft edge without a raster step. `inset` shadows
     * are skipped. The property used to be ignored altogether.
     *
     * @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands
     */
    private function appendBoxShadows(array &$commands, LayoutNode|AtomicInlineBox $node, ComputedStyle $style, int $pageIndex, float $x, float $y, float $width, float $height, BorderRadius $radius): void
    {
        $raw = trim($style->get('box-shadow') ?? '');
        if ($raw === '' || strtolower($raw) === 'none') return;
        $fontSize = $node instanceof LayoutNode ? $node->fontSize : 16.0;
        foreach (array_reverse(self::splitTopLevel($raw, ',')) as $shadow) {
            $tokens = self::splitTopLevel($shadow, ' ');
            $lengths = [];
            $color = null;
            $inset = false;
            foreach ($tokens as $token) {
                if (strtolower($token) === 'inset') {
                    $inset = true;
                } elseif (preg_match('/^-?\d*\.?\d+(px|pt|em|rem|mm|cm|in|pc)?$/i', $token) === 1) {
                    $lengths[] = $this->shadowLength($token, $fontSize);
                } else {
                    $color = strtolower($token) === 'currentcolor' ? ColorParser::parse($style->get('color', 'black')) : ColorParser::parse($token);
                }
            }
            if ($inset || count($lengths) < 2) continue;
            $color = Opacity::apply($color ?? ColorParser::parse($style->get('color', 'black')), $style);
            if ($color === null || $color->a <= 0.0) continue;
            [$dx, $dy] = $lengths;
            $blur = max(0.0, $lengths[2] ?? 0.0);
            $spread = $lengths[3] ?? 0.0;
            $layers = $blur > 0.0 ? max(2, min(8, (int) ceil($blur / 2))) : 1;
            $alpha = $color->a / $layers;
            for ($i = 0; $i < $layers; $i++) {
                $grow = $spread + ($layers === 1 ? 0.0 : -$blur / 2 + $blur * ($i + 0.5) / $layers);
                $w = $width + 2 * $grow;
                $h = $height + 2 * $grow;
                if ($w <= 0.0 || $h <= 0.0) continue;
                $commands[] = new BoxPaintCommand(
                    node: $node,
                    pageIndex: $pageIndex,
                    x: $x + $dx - $grow,
                    y: $y + $dy - $grow,
                    width: $w,
                    height: $h,
                    backgroundColor: new \Pagyra\Css\Color\Rgba($color->r, $color->g, $color->b, $alpha),
                    borderRadius: $this->grownRadius($radius, $grow),
                    decorative: true,
                );
            }
        }
    }

    /**
     * `outline` (CSS UI 4 §5): a line of `outline-width` drawn outside the border box, pushed out
     * by `outline-offset`, in `outline-color` or the text colour. Every visible style is drawn
     * solid. It used to be ignored.
     *
     * @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands
     */
    private function appendOutline(array &$commands, LayoutNode|AtomicInlineBox $node, ComputedStyle $style, int $pageIndex, float $x, float $y, float $width, float $height): void
    {
        $style_ = strtolower(trim($style->get('outline-style') ?? ''));
        $widthRaw = $style->get('outline-width');
        $colorRaw = $style->get('outline-color');
        foreach (self::splitTopLevel(trim($style->get('outline') ?? ''), ' ') as $token) {
            $lower = strtolower($token);
            if (in_array($lower, ['none', 'hidden', 'solid', 'dotted', 'dashed', 'double', 'groove', 'ridge', 'inset', 'outset', 'auto'], true)) {
                $style_ = $style_ !== '' ? $style_ : $lower;
            } elseif (preg_match('/^\d*\.?\d+[a-z]*$/i', $token) === 1 || in_array($lower, ['thin', 'medium', 'thick'], true)) {
                $widthRaw ??= $token;
            } else {
                $colorRaw ??= $token;
            }
        }
        if ($style_ === '' || $style_ === 'none' || $style_ === 'hidden') return;
        $fontSize = $node instanceof LayoutNode ? $node->fontSize : 16.0;
        $lineWidth = match (strtolower(trim($widthRaw ?? 'medium'))) {
            'thin' => 1.0,
            'medium' => 3.0,
            'thick' => 5.0,
            default => max(0.0, $this->shadowLength((string) $widthRaw, $fontSize)),
        };
        if ($lineWidth <= 0.0) return;
        $offset = $this->shadowLength((string) ($style->get('outline-offset') ?? '0'), $fontSize);
        $colorText = strtolower(trim($colorRaw ?? 'currentcolor'));
        $color = Opacity::apply(ColorParser::parse(in_array($colorText, ['currentcolor', 'invert', 'auto'], true) ? ($style->get('color', 'black') ?? 'black') : $colorText), $style);
        if ($color === null || $color->a <= 0.0) return;

        $left = $x - $offset - $lineWidth;
        $top = $y - $offset - $lineWidth;
        $outerWidth = $width + 2 * ($offset + $lineWidth);
        $outerHeight = $height + 2 * ($offset + $lineWidth);
        foreach ([
            [$left, $top, $outerWidth, $lineWidth],
            [$left, $top + $outerHeight - $lineWidth, $outerWidth, $lineWidth],
            [$left, $top + $lineWidth, $lineWidth, $outerHeight - 2 * $lineWidth],
            [$left + $outerWidth - $lineWidth, $top + $lineWidth, $lineWidth, $outerHeight - 2 * $lineWidth],
        ] as [$rx, $ry, $rw, $rh]) {
            if ($rw <= 0.0 || $rh <= 0.0) continue;
            $commands[] = new BoxPaintCommand(node: $node, pageIndex: $pageIndex, x: $rx, y: $ry, width: $rw, height: $rh, backgroundColor: $color, decorative: true);
        }
    }

    private function shadowLength(string $value, float $fontSize): float
    {
        if (preg_match('/^(-?\d*\.?\d+)([a-z]*)$/i', trim($value), $m) !== 1) return 0.0;
        $n = (float) $m[1];

        return match (strtolower($m[2])) {
            'pt' => \Pagyra\Units\Units::ptToPx($n),
            'em' => $n * $fontSize,
            'rem' => $n * 16.0,
            'mm' => \Pagyra\Units\Units::mmToPx($n),
            'cm' => \Pagyra\Units\Units::cmToPx($n),
            'in' => \Pagyra\Units\Units::inToPx($n),
            'pc' => \Pagyra\Units\Units::pcToPx($n),
            default => $n,
        };
    }

    private function grownRadius(BorderRadius $radius, float $grow): BorderRadius
    {
        if ($radius->isZero()) return $radius;
        $corner = static fn(CornerRadius $c): CornerRadius => new CornerRadius(max(0.0, $c->x + $grow), max(0.0, $c->y + $grow));

        return new BorderRadius($corner($radius->topLeft), $corner($radius->topRight), $corner($radius->bottomRight), $corner($radius->bottomLeft));
    }

    /** @return list<string> the value split at $separator outside parentheses */
    private static function splitTopLevel(string $value, string $separator): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        foreach (str_split($value) as $ch) {
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth = max(0, $depth - 1);
            if ($depth === 0 && ($separator === ' ' ? ctype_space($ch) : $ch === $separator)) {
                if (trim($buffer) !== '') $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') $parts[] = trim($buffer);

        return $parts;
    }

    private function firstLineFragmentForMarker(BlockFragment $block): ?LineFragment
    {
        if ($block->lines !== []) return $block->lines[0];
        foreach ($block->children as $child) {
            $found = $this->firstLineFragmentForMarker($child);
            if ($found !== null) return $found;
        }
        return null;
    }

    private function firstTextRunInLine(LineBox $line): ?TextRun
    {
        foreach ($line->orderedItems() as $item) {
            if ($item instanceof TextRun) return $item;
        }
        return null;
    }

    private function fragmentRadius(BorderRadius $radius, bool $drawTop, bool $drawBottom): BorderRadius
    {
        return new BorderRadius(
            $drawTop ? $radius->topLeft : new CornerRadius(),
            $drawTop ? $radius->topRight : new CornerRadius(),
            $drawBottom ? $radius->bottomRight : new CornerRadius(),
            $drawBottom ? $radius->bottomLeft : new CornerRadius(),
        );
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendBorders(
        array &$commands,
        LayoutNode $node,
        int $pageIndex,
        float $x,
        float $y,
        float $width,
        float $height,
        bool $drawTop,
        bool $drawBottom,
    ): void {
        if ($width <= 0.0 || $height <= 0.0) return;
        if ($drawTop && $drawBottom && $this->appendRoundedUniformBorder($commands, $node, $pageIndex, $x, $y, $width, $height)) return;

        $edges = $node->box->border;
        $top = max(0.0, min($edges->top, $height));
        $right = max(0.0, min($edges->right, $width));
        $bottom = max(0.0, min($edges->bottom, $height));
        $left = max(0.0, min($edges->left, $width));

        if ($drawTop && $top > 0.0) $this->appendBorderSide($commands, $node, $pageIndex, 'top', $x, $y, $width, $top);
        if ($drawBottom && $bottom > 0.0) $this->appendBorderSide($commands, $node, $pageIndex, 'bottom', $x, $y + $height - $bottom, $width, $bottom);

        $sideY = $y + ($drawTop ? $top : 0.0);
        $sideHeight = max(0.0, $height - ($drawTop ? $top : 0.0) - ($drawBottom ? $bottom : 0.0));
        if ($left > 0.0 && $sideHeight > 0.0) $this->appendBorderSide($commands, $node, $pageIndex, 'left', $x, $sideY, $left, $sideHeight);
        if ($right > 0.0 && $sideHeight > 0.0) $this->appendBorderSide($commands, $node, $pageIndex, 'right', $x + $width - $right, $sideY, $right, $sideHeight);
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendRoundedUniformBorder(
        array &$commands,
        LayoutNode $node,
        int $pageIndex,
        float $x,
        float $y,
        float $width,
        float $height,
    ): bool {
        $edges = $node->box->border;
        $borderWidth = $edges->top;
        if ($borderWidth <= 0.0
            || abs($edges->right - $borderWidth) > self::EPSILON
            || abs($edges->bottom - $borderWidth) > self::EPSILON
            || abs($edges->left - $borderWidth) > self::EPSILON) return false;

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if ($this->borderStyle($node, $side) !== 'solid') return false;
        }
        $colors = array_map(fn (string $side): ?Rgba => $this->borderColor($node, $side), ['top', 'right', 'bottom', 'left']);
        if (!$colors[0] instanceof Rgba || $colors[0]->a <= 0.0) return false;
        foreach (array_slice($colors, 1) as $color) {
            if (!$color instanceof Rgba || !$this->sameColor($colors[0], $color)) return false;
        }

        $outer = BorderRadiusResolver::resolve($node->source->style, $width, $height);
        if ($outer->isZero()) return false;
        $innerWidth = max(0.0, $width - 2.0 * $borderWidth);
        $innerHeight = max(0.0, $height - 2.0 * $borderWidth);
        $inner = BorderRadiusResolver::normalize(new BorderRadius(
            new CornerRadius(max(0.0, $outer->topLeft->x - $borderWidth), max(0.0, $outer->topLeft->y - $borderWidth)),
            new CornerRadius(max(0.0, $outer->topRight->x - $borderWidth), max(0.0, $outer->topRight->y - $borderWidth)),
            new CornerRadius(max(0.0, $outer->bottomRight->x - $borderWidth), max(0.0, $outer->bottomRight->y - $borderWidth)),
            new CornerRadius(max(0.0, $outer->bottomLeft->x - $borderWidth), max(0.0, $outer->bottomLeft->y - $borderWidth)),
        ), $innerWidth, $innerHeight);

        $commands[] = new RoundedBorderPaintCommand($node, $pageIndex, $x, $y, $width, $height, $borderWidth, $colors[0], $outer, $inner);
        return true;
    }

    private function sameColor(Rgba $a, Rgba $b): bool
    {
        return abs($a->r - $b->r) <= self::EPSILON
            && abs($a->g - $b->g) <= self::EPSILON
            && abs($a->b - $b->b) <= self::EPSILON
            && abs($a->a - $b->a) <= self::EPSILON;
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendBorderSide(
        array &$commands,
        LayoutNode $node,
        int $pageIndex,
        string $side,
        float $x,
        float $y,
        float $width,
        float $height,
    ): void {
        if ($this->borderStyle($node, $side) !== 'solid') return;
        $color = $this->borderColor($node, $side);
        if (!$color instanceof Rgba || $color->a <= 0.0) return;
        $commands[] = new BorderPaintCommand($node, $pageIndex, $side, $x, $y, $width, $height, $color);
    }

    private function borderStyle(LayoutNode $node, string $side): string
    {
        return $this->styleBorderStyle($node->source->style, $side);
    }

    private function borderColor(LayoutNode $node, string $side): ?Rgba
    {
        return $this->styleBorderColor($node->source->style, $side);
    }

    /** @param list<string> $parts @return list<string> */
    private function expandFourValues(array $parts): array
    {
        if ($parts === []) return ['', '', '', ''];
        return match (count($parts)) {
            1 => [$parts[0], $parts[0], $parts[0], $parts[0]],
            2 => [$parts[0], $parts[1], $parts[0], $parts[1]],
            3 => [$parts[0], $parts[1], $parts[2], $parts[1]],
            default => [$parts[0], $parts[1], $parts[2], $parts[3]],
        };
    }

    /**
     * @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands
     * @param list<LineFragment> $lines
     */
    private function appendLines(array &$commands, array $lines, array $margins): void
    {
        foreach ($lines as $lineFragment) {
            $line = $lineFragment->line;
            foreach ($line->orderedItems() as $item) {
                if ($item instanceof TextRun) {
                    $this->appendTextRun($commands, $item, $line, $lineFragment, $margins);
                    continue;
                }
                $this->appendAtomicBox($commands, $item, $line, $lineFragment, $margins);
            }
        }
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendTextRun(array &$commands, TextRun $run, LineBox $line, LineFragment $lineFragment, array $margins): void
    {
        $weightRaw = strtolower(trim($run->style->get('font-weight', '400') ?? '400'));
        $fontWeight = $weightRaw === 'bold' ? 700 : ($weightRaw === 'normal' ? 400 : (is_numeric($weightRaw) ? (int) $weightRaw : 400));
        [$underline, $lineThrough, $overline] = $this->resolveTextDecorationLines($run->style);
        $decorationStyle = strtolower(trim($run->style->get('text-decoration-style') ?? 'solid'));
        $decorationColorRaw = strtolower(trim($run->style->get('text-decoration-color') ?? 'currentcolor'));
        $decorationColor = $decorationColorRaw === 'currentcolor' ? null : Opacity::apply(ColorParser::parse($decorationColorRaw), $run->style);
        $baseline = $lineFragment->pageBaseline + ($run->baseline - $line->baseline) + $margins['top'];
        if ($run->inlineBackground !== null) {
            // CSS paints an inline box's background over its content area, which is the font's
            // ascent plus descent around the baseline, not the line box: a highlighted word in a
            // paragraph with line-height: 2 gets a band hugging the glyphs, not a double-height
            // slab. The 0.9/0.22 em split is the hhea ascent/descent of the Liberation and URW
            // faces that stand in for the Base14 families, which is what WebKit draws with.
            $commands[] = new BoxPaintCommand(
                node: $run,
                pageIndex: $lineFragment->pageIndex,
                x: $run->x + $margins['left'],
                y: $baseline - self::INLINE_BACKGROUND_ASCENT * $run->fontSize,
                width: $run->width,
                height: (self::INLINE_BACKGROUND_ASCENT + self::INLINE_BACKGROUND_DESCENT) * $run->fontSize,
                backgroundColor: Opacity::apply(ColorParser::parse($run->inlineBackground), $run->style),
            );
        }
        $commands[] = new TextPaintCommand(
            run: $run,
            pageIndex: $lineFragment->pageIndex,
            x: $run->x + $margins['left'],
            y: $lineFragment->pageY + ($run->y - $line->y) + $margins['top'],
            baseline: $baseline,
            text: $run->text,
            fontSize: $run->fontSize,
            fontFamily: $run->style->get('font-family'),
            fontWeight: max(100, min(900, $fontWeight)),
            fontStyle: strtolower(trim($run->style->get('font-style', 'normal') ?? 'normal')),
            color: Opacity::apply(ColorParser::parse($run->style->get('color', 'black')), $run->style),
            underline: $underline,
            lineThrough: $lineThrough,
            linkHref: $run->style->get('x-link-href'),
            overline: $overline,
            decorationStyle: in_array($decorationStyle, ['solid', 'double', 'dotted', 'dashed', 'wavy'], true) ? $decorationStyle : 'solid',
            decorationColor: $decorationColor,
        );
    }

    /**
     * Resolves the `text-decoration` (shorthand) / `text-decoration-line` (longhand) tokens
     * into [underline, lineThrough], mirroring pagyra-js's parseTextDecorationLine, which keeps
     * only the recognized line keywords and treats "none" as clearing every line.
     *
     * @return array{0:bool,1:bool,2:bool} underline, line-through, overline
     */
    private function resolveTextDecorationLines(ComputedStyle $style): array
    {
        $raw = $style->get('text-decoration-line') ?? $style->get('text-decoration');
        if ($raw === null) return [false, false, false];
        $tokens = preg_split('/\s+/', strtolower(trim($raw))) ?: [];
        if ($tokens === [] || in_array('none', $tokens, true)) return [false, false, false];
        return [in_array('underline', $tokens, true), in_array('line-through', $tokens, true), in_array('overline', $tokens, true)];
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendAtomicBox(array &$commands, AtomicInlineBox $box, LineBox $line, LineFragment $lineFragment, array $margins): void
    {
        $outerPageY = $lineFragment->pageY + ($box->y - $line->y) + $margins['top'];
        $borderX = $box->x + $box->margin['left'] + $margins['left'];
        $borderY = $outerPageY + $box->margin['top'];
        $borderWidth = $box->contentWidth + $box->padding['left'] + $box->padding['right'] + $box->border['left'] + $box->border['right'];
        $borderHeight = $box->contentHeight + $box->padding['top'] + $box->padding['bottom'] + $box->border['top'] + $box->border['bottom'];

        if ($borderWidth > 0.0 && $borderHeight > 0.0) {
            $radius = BorderRadiusResolver::resolve($box->style, $borderWidth, $borderHeight);
            $this->appendBoxShadows($commands, $box, $box->style, $lineFragment->pageIndex, $borderX, $borderY, $borderWidth, $borderHeight, $radius);
            $commands[] = new BoxPaintCommand(
                node: $box,
                pageIndex: $lineFragment->pageIndex,
                x: $borderX,
                y: $borderY,
                width: $borderWidth,
                height: $borderHeight,
                backgroundColor: Opacity::apply(ColorParser::parse($box->style->get('background-color')), $box->style),
                borderRadius: $radius,
            );
            $this->appendBackgroundImage($commands, $box->source, $box->style, $lineFragment->pageIndex, $borderX, $borderY, $borderWidth, $borderHeight, new \Pagyra\Geometry\Edges($box->border['top'], $box->border['right'], $box->border['bottom'], $box->border['left']), 16.0);
            $this->appendAtomicBorders($commands, $box, $lineFragment->pageIndex, $borderX, $borderY, $borderWidth, $borderHeight, $radius);
            $this->appendOutline($commands, $box, $box->style, $lineFragment->pageIndex, $borderX, $borderY, $borderWidth, $borderHeight);
        }

        if ($box->source->node->isElement('img')) {
            $this->appendAtomicImage($commands, $box, $line, $lineFragment, $margins);
        }

        if ($box->contentLines !== []) {
            $nested = [];
            foreach ($box->contentLines as $index => $contentLine) {
                $pageY = $lineFragment->pageY + ($contentLine->y - $line->y);
                $pageBaseline = $lineFragment->pageY + ($contentLine->baseline - $line->y);
                $nested[] = new LineFragment(
                    line: $contentLine,
                    lineIndex: $index,
                    pageIndex: $lineFragment->pageIndex,
                    pageY: $pageY,
                    pageBaseline: $pageBaseline,
                    continuousY: $contentLine->y,
                    continuousBaseline: $contentLine->baseline,
                );
            }
            $this->appendLines($commands, $nested, $margins);
        }
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendAtomicBorders(
        array &$commands,
        AtomicInlineBox $box,
        int $pageIndex,
        float $x,
        float $y,
        float $width,
        float $height,
        BorderRadius $outerRadius,
    ): void {
        $edges = $box->border;
        $top = max(0.0, min($edges['top'], $height));
        $right = max(0.0, min($edges['right'], $width));
        $bottom = max(0.0, min($edges['bottom'], $height));
        $left = max(0.0, min($edges['left'], $width));

        if ($top > 0.0
            && abs($right - $top) <= self::EPSILON
            && abs($bottom - $top) <= self::EPSILON
            && abs($left - $top) <= self::EPSILON
            && !$outerRadius->isZero()) {
            $styles = array_map(fn(string $side): string => $this->styleBorderStyle($box->style, $side), ['top', 'right', 'bottom', 'left']);
            $colors = array_map(fn(string $side): ?Rgba => $this->styleBorderColor($box->style, $side), ['top', 'right', 'bottom', 'left']);
            if (count(array_unique($styles)) === 1 && $styles[0] === 'solid' && $colors[0] instanceof Rgba && $colors[0]->a > 0.0) {
                $same = true;
                foreach (array_slice($colors, 1) as $color) {
                    if (!$color instanceof Rgba || !$this->sameColor($colors[0], $color)) { $same = false; break; }
                }
                if ($same) {
                    $innerWidth = max(0.0, $width - 2.0 * $top);
                    $innerHeight = max(0.0, $height - 2.0 * $top);
                    $inner = BorderRadiusResolver::normalize(new BorderRadius(
                        new CornerRadius(max(0.0, $outerRadius->topLeft->x - $top), max(0.0, $outerRadius->topLeft->y - $top)),
                        new CornerRadius(max(0.0, $outerRadius->topRight->x - $top), max(0.0, $outerRadius->topRight->y - $top)),
                        new CornerRadius(max(0.0, $outerRadius->bottomRight->x - $top), max(0.0, $outerRadius->bottomRight->y - $top)),
                        new CornerRadius(max(0.0, $outerRadius->bottomLeft->x - $top), max(0.0, $outerRadius->bottomLeft->y - $top)),
                    ), $innerWidth, $innerHeight);
                    $commands[] = new RoundedBorderPaintCommand($box, $pageIndex, $x, $y, $width, $height, $top, $colors[0], $outerRadius, $inner);
                    return;
                }
            }
        }

        if ($top > 0.0) $this->appendAtomicBorderSide($commands, $box, $pageIndex, 'top', $x, $y, $width, $top);
        if ($bottom > 0.0) $this->appendAtomicBorderSide($commands, $box, $pageIndex, 'bottom', $x, $y + $height - $bottom, $width, $bottom);
        $sideY = $y + $top;
        $sideHeight = max(0.0, $height - $top - $bottom);
        if ($left > 0.0 && $sideHeight > 0.0) $this->appendAtomicBorderSide($commands, $box, $pageIndex, 'left', $x, $sideY, $left, $sideHeight);
        if ($right > 0.0 && $sideHeight > 0.0) $this->appendAtomicBorderSide($commands, $box, $pageIndex, 'right', $x + $width - $right, $sideY, $right, $sideHeight);
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendAtomicBorderSide(array &$commands, AtomicInlineBox $box, int $pageIndex, string $side, float $x, float $y, float $width, float $height): void
    {
        if ($this->styleBorderStyle($box->style, $side) !== 'solid') return;
        $color = $this->styleBorderColor($box->style, $side);
        if (!$color instanceof Rgba || $color->a <= 0.0) return;
        $commands[] = new BorderPaintCommand($box, $pageIndex, $side, $x, $y, $width, $height, $color);
    }

    /** @param list<BoxPaintCommand|BorderPaintCommand|RoundedBorderPaintCommand|TextPaintCommand|ImagePaintCommand> $commands */
    private function appendAtomicImage(array &$commands, AtomicInlineBox $box, LineBox $line, LineFragment $lineFragment, array $margins): void
    {
        if ($this->imageBytes === null) return;
        $source = $box->source->node->attribute('src');
        $bytes = $this->imageBytes->resolve($source);
        if ($source === null || $bytes === null) return;
        try {
            $metadata = $this->imageMetadata->read($bytes);
        } catch (\InvalidArgumentException) {
            return;
        }
        if (!in_array($metadata->format, ['jpeg', 'png'], true)) return;
        if ($box->contentWidth <= 0.0 || $box->contentHeight <= 0.0) return;

        $contentX = $box->x + $box->margin['left'] + $box->border['left'] + $box->padding['left'] + $margins['left'];
        $contentY = $lineFragment->pageY
            + (($box->y + $box->margin['top'] + $box->border['top'] + $box->padding['top']) - $line->y)
            + $margins['top'];
        $contentBox = new Rect($contentX, $contentY, $box->contentWidth, $box->contentHeight);
        $fitRaw = strtolower(trim($box->style->get('object-fit', 'fill') ?? 'fill'));
        $fit = ObjectFit::tryFrom($fitRaw) ?? ObjectFit::Fill;
        $position = ObjectPositionParser::parse($box->style->get('object-position'));
        $destination = ObjectFitResolver::resolve($metadata->width, $metadata->height, $contentBox, $fit, $position);
        $clipRect = ObjectFitResolver::needsClip($destination, $contentBox) ? $contentBox : null;

        $commands[] = new ImagePaintCommand(
            box: $box,
            pageIndex: $lineFragment->pageIndex,
            x: $destination->x,
            y: $destination->y,
            width: $destination->width,
            height: $destination->height,
            bytes: $bytes,
            metadata: $metadata,
            source: $source,
            clipRect: $clipRect,
            opacity: Opacity::of($box->style),
        );
    }

    private function styleBorderStyle(\Pagyra\Style\ComputedStyle $style, string $side): string
    {
        $specific = $style->get('border-' . $side . '-style');
        if ($specific !== null && trim($specific) !== '') return strtolower(trim($specific));
        $raw = trim($style->get('border-style', 'none') ?? 'none');
        $parts = preg_split('/\s+/', $raw) ?: ['none'];
        $expanded = $this->expandFourValues($parts);
        return strtolower($expanded[array_search($side, ['top', 'right', 'bottom', 'left'], true)] ?? 'none');
    }

    private function styleBorderColor(\Pagyra\Style\ComputedStyle $style, string $side): ?Rgba
    {
        $specific = trim($style->get('border-' . $side . '-color') ?? '');
        $raw = $specific !== '' ? $specific : trim($style->get('border-color') ?? '');
        if ($specific === '' && $raw !== '' && !str_contains($raw, '(')) {
            $parts = preg_split('/\s+/', $raw) ?: [];
            if (count($parts) > 1) {
                $expanded = $this->expandFourValues($parts);
                $raw = $expanded[array_search($side, ['top', 'right', 'bottom', 'left'], true)] ?? $raw;
            }
        }
        if ($raw === '' || strtolower($raw) === 'currentcolor') $raw = $style->get('color', 'black') ?? 'black';
        return Opacity::apply(ColorParser::parse($raw), $style);
    }

    /** @param array<string,mixed> $profile @return array{top:float,right:float,bottom:float,left:float} */
    private function marginsForPage(array $profile, int $pageIndex): array
    {
        if (!isset($profile['default']) || !is_array($profile['default'])) {
            return [
                'top' => (float) ($profile['top'] ?? 0.0),
                'right' => (float) ($profile['right'] ?? 0.0),
                'bottom' => (float) ($profile['bottom'] ?? 0.0),
                'left' => (float) ($profile['left'] ?? 0.0),
            ];
        }

        $index = max(0, $pageIndex);
        if ($index === 0 && isset($profile['first']) && is_array($profile['first'])) $resolved = $profile['first'];
        elseif ($index % 2 === 0 && isset($profile['right']) && is_array($profile['right'])) $resolved = $profile['right'];
        elseif ($index % 2 === 1 && isset($profile['left']) && is_array($profile['left'])) $resolved = $profile['left'];
        else $resolved = $profile['default'];

        return [
            'top' => (float) ($resolved['top'] ?? 0.0),
            'right' => (float) ($resolved['right'] ?? 0.0),
            'bottom' => (float) ($resolved['bottom'] ?? 0.0),
            'left' => (float) ($resolved['left'] ?? 0.0),
        ];
    }
}
