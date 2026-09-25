<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\ColorParser;
use Pagyra\Css\Color\Rgba;
use Pagyra\Layout\AtomicInlineBox;
use Pagyra\Svg\SvgDocument;
use Pagyra\Svg\SvgDocumentParser;

/**
 * Converts the owned inline-SVG DOM into ordinary vector display-list commands.
 *
 * Geometry is flattened into absolute page-space M/L/C/Z segments before PDF serialization,
 * keeping the serializer independent of SVG DOM semantics.
 */
final class SvgPaintBuilder
{
    private const KAPPA = 0.5522847498307936;

    public function __construct(
        private readonly SvgDocumentParser $documents = new SvgDocumentParser(),
        private readonly CssTransformParser $transforms = new CssTransformParser(),
    ) {
    }

    /**
     * @return list<SvgPathPaintCommand>
     */
    public function build(
        AtomicInlineBox $box,
        int $pageIndex,
        float $contentX,
        float $contentY,
        float $contentWidth,
        float $contentHeight,
    ): array {
        if (!$box->source->node->isSvg() || $contentWidth <= 0.0 || $contentHeight <= 0.0) {
            return [];
        }

        $document = $this->documents->parse($box->source->node);
        if (!$document instanceof SvgDocument) return [];

        $viewport = $this->viewportMatrix($document, $contentX, $contentY, $contentWidth, $contentHeight);
        $commands = [];

        foreach ($document->shapes as $shape) {
            $segments = $this->shapeSegments($shape);
            if ($segments === []) continue;

            $shapeTransform = $this->transforms->parse((string) ($shape['transform'] ?? ''));
            $matrix = $shapeTransform instanceof TransformMatrix
                ? $viewport->multiply($shapeTransform)
                : $viewport;
            $mapped = $this->mapSegments($segments, $matrix);

            $shapeOpacity = $this->unit($shape['opacity'] ?? 1.0);
            $fillOpacity = $shapeOpacity * $this->unit($shape['fillOpacity'] ?? 1.0);
            $strokeOpacity = $shapeOpacity * $this->unit($shape['strokeOpacity'] ?? 1.0);

            $fill = $this->paintColor((string) ($shape['fill'] ?? 'black'), $box, $fillOpacity);
            $stroke = $this->paintColor((string) ($shape['stroke'] ?? 'none'), $box, $strokeOpacity);
            if (!$fill instanceof Rgba && !$stroke instanceof Rgba) continue;

            $strokeScale = $this->strokeScale($matrix);
            $commands[] = new SvgPathPaintCommand(
                box: $box,
                pageIndex: $pageIndex,
                segments: $mapped,
                fill: $fill,
                stroke: $stroke,
                strokeWidth: max(0.0, (float) ($shape['strokeWidth'] ?? 1.0)) * $strokeScale,
                fillRule: ($shape['fillRule'] ?? 'nonzero') === 'evenodd' ? 'evenodd' : 'nonzero',
            );
        }

        return $commands;
    }

    private function viewportMatrix(
        SvgDocument $document,
        float $x,
        float $y,
        float $width,
        float $height,
    ): TransformMatrix {
        $viewBox = $document->viewBox;
        $sourceWidth = max(1e-9, (float) ($viewBox['width'] ?? $document->width ?? $width));
        $sourceHeight = max(1e-9, (float) ($viewBox['height'] ?? $document->height ?? $height));
        $minX = (float) ($viewBox['minX'] ?? 0.0);
        $minY = (float) ($viewBox['minY'] ?? 0.0);

        $scaleX = $width / $sourceWidth;
        $scaleY = $height / $sourceHeight;
        $offsetX = 0.0;
        $offsetY = 0.0;

        [$align, $mode] = $this->preserveAspectRatio($document->preserveAspectRatio);
        if ($align !== 'none') {
            $uniform = $mode === 'slice' ? max($scaleX, $scaleY) : min($scaleX, $scaleY);
            $scaleX = $scaleY = $uniform;
            $extraX = $width - $sourceWidth * $uniform;
            $extraY = $height - $sourceHeight * $uniform;
            [$fx, $fy] = $this->alignFactors($align);
            $offsetX = $extraX * $fx;
            $offsetY = $extraY * $fy;
        }

        return new TransformMatrix(
            a: $scaleX,
            d: $scaleY,
            e: $x + $offsetX - $minX * $scaleX,
            f: $y + $offsetY - $minY * $scaleY,
        );
    }

    /** @return array{0:string,1:string} */
    private function preserveAspectRatio(string $raw): array
    {
        $tokens = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (($tokens[0] ?? '') === 'defer') array_shift($tokens);
        $align = $tokens[0] ?? 'xMidYMid';
        if ($align === 'none') return ['none', 'meet'];
        return [$align, strtolower($tokens[1] ?? 'meet') === 'slice' ? 'slice' : 'meet'];
    }

    /** @return array{0:float,1:float} */
    private function alignFactors(string $align): array
    {
        $x = str_contains($align, 'xMax') ? 1.0 : (str_contains($align, 'xMid') ? 0.5 : 0.0);
        $y = str_contains($align, 'YMax') ? 1.0 : (str_contains($align, 'YMid') ? 0.5 : 0.0);
        return [$x, $y];
    }

    /** @return list<array<string,float|string>> */
    private function shapeSegments(array $shape): array
    {
        return match ($shape['type'] ?? '') {
            'path' => $shape['segments'] ?? [],
            'rect' => $this->rectSegments($shape),
            'circle' => $this->ellipseSegments(
                (float) ($shape['cx'] ?? 0.0),
                (float) ($shape['cy'] ?? 0.0),
                (float) ($shape['r'] ?? 0.0),
                (float) ($shape['r'] ?? 0.0),
            ),
            'ellipse' => $this->ellipseSegments(
                (float) ($shape['cx'] ?? 0.0),
                (float) ($shape['cy'] ?? 0.0),
                (float) ($shape['rx'] ?? 0.0),
                (float) ($shape['ry'] ?? 0.0),
            ),
            'line' => [
                ['type' => 'M', 'x' => (float) ($shape['x1'] ?? 0.0), 'y' => (float) ($shape['y1'] ?? 0.0)],
                ['type' => 'L', 'x' => (float) ($shape['x2'] ?? 0.0), 'y' => (float) ($shape['y2'] ?? 0.0)],
            ],
            'polyline', 'polygon' => $this->pointsSegments(
                $shape['points'] ?? [],
                ($shape['type'] ?? '') === 'polygon',
            ),
            default => [],
        };
    }

    /** @return list<array<string,float|string>> */
    private function rectSegments(array $shape): array
    {
        $x = (float) ($shape['x'] ?? 0.0);
        $y = (float) ($shape['y'] ?? 0.0);
        $width = max(0.0, (float) ($shape['width'] ?? 0.0));
        $height = max(0.0, (float) ($shape['height'] ?? 0.0));
        if ($width <= 0.0 || $height <= 0.0) return [];

        $rx = min($width / 2.0, max(0.0, (float) ($shape['rx'] ?? 0.0)));
        $ry = min($height / 2.0, max(0.0, (float) ($shape['ry'] ?? 0.0)));
        if ($rx <= 0.0 || $ry <= 0.0) {
            return [
                ['type'=>'M','x'=>$x,'y'=>$y],
                ['type'=>'L','x'=>$x+$width,'y'=>$y],
                ['type'=>'L','x'=>$x+$width,'y'=>$y+$height],
                ['type'=>'L','x'=>$x,'y'=>$y+$height],
                ['type'=>'Z'],
            ];
        }

        $kx = $rx * self::KAPPA;
        $ky = $ry * self::KAPPA;
        return [
            ['type'=>'M','x'=>$x+$rx,'y'=>$y],
            ['type'=>'L','x'=>$x+$width-$rx,'y'=>$y],
            ['type'=>'C','x1'=>$x+$width-$rx+$kx,'y1'=>$y,'x2'=>$x+$width,'y2'=>$y+$ry-$ky,'x'=>$x+$width,'y'=>$y+$ry],
            ['type'=>'L','x'=>$x+$width,'y'=>$y+$height-$ry],
            ['type'=>'C','x1'=>$x+$width,'y1'=>$y+$height-$ry+$ky,'x2'=>$x+$width-$rx+$kx,'y2'=>$y+$height,'x'=>$x+$width-$rx,'y'=>$y+$height],
            ['type'=>'L','x'=>$x+$rx,'y'=>$y+$height],
            ['type'=>'C','x1'=>$x+$rx-$kx,'y1'=>$y+$height,'x2'=>$x,'y2'=>$y+$height-$ry+$ky,'x'=>$x,'y'=>$y+$height-$ry],
            ['type'=>'L','x'=>$x,'y'=>$y+$ry],
            ['type'=>'C','x1'=>$x,'y1'=>$y+$ry-$ky,'x2'=>$x+$rx-$kx,'y2'=>$y,'x'=>$x+$rx,'y'=>$y],
            ['type'=>'Z'],
        ];
    }

    /** @return list<array<string,float|string>> */
    private function ellipseSegments(float $cx, float $cy, float $rx, float $ry): array
    {
        if ($rx <= 0.0 || $ry <= 0.0) return [];
        $kx = $rx * self::KAPPA;
        $ky = $ry * self::KAPPA;

        return [
            ['type'=>'M','x'=>$cx+$rx,'y'=>$cy],
            ['type'=>'C','x1'=>$cx+$rx,'y1'=>$cy+$ky,'x2'=>$cx+$kx,'y2'=>$cy+$ry,'x'=>$cx,'y'=>$cy+$ry],
            ['type'=>'C','x1'=>$cx-$kx,'y1'=>$cy+$ry,'x2'=>$cx-$rx,'y2'=>$cy+$ky,'x'=>$cx-$rx,'y'=>$cy],
            ['type'=>'C','x1'=>$cx-$rx,'y1'=>$cy-$ky,'x2'=>$cx-$kx,'y2'=>$cy-$ry,'x'=>$cx,'y'=>$cy-$ry],
            ['type'=>'C','x1'=>$cx+$kx,'y1'=>$cy-$ry,'x2'=>$cx+$rx,'y2'=>$cy-$ky,'x'=>$cx+$rx,'y'=>$cy],
            ['type'=>'Z'],
        ];
    }

    /** @param list<array{x:float,y:float}> $points @return list<array<string,float|string>> */
    private function pointsSegments(array $points, bool $closed): array
    {
        if ($points === []) return [];
        $first = array_shift($points);
        $segments = [['type'=>'M','x'=>(float)$first['x'],'y'=>(float)$first['y']]];
        foreach ($points as $point) {
            $segments[] = ['type'=>'L','x'=>(float)$point['x'],'y'=>(float)$point['y']];
        }
        if ($closed) $segments[] = ['type'=>'Z'];
        return $segments;
    }

    /** @param list<array<string,float|string>> $segments @return list<array<string,float|string>> */
    private function mapSegments(array $segments, TransformMatrix $matrix): array
    {
        $mapped = [];
        foreach ($segments as $segment) {
            $type = (string) ($segment['type'] ?? '');
            if ($type === 'Z') {
                $mapped[] = ['type' => 'Z'];
                continue;
            }
            if ($type === 'C') {
                [$x1,$y1] = $this->point($matrix, (float)$segment['x1'], (float)$segment['y1']);
                [$x2,$y2] = $this->point($matrix, (float)$segment['x2'], (float)$segment['y2']);
                [$x,$y] = $this->point($matrix, (float)$segment['x'], (float)$segment['y']);
                $mapped[] = ['type'=>'C','x1'=>$x1,'y1'=>$y1,'x2'=>$x2,'y2'=>$y2,'x'=>$x,'y'=>$y];
                continue;
            }
            [$x,$y] = $this->point($matrix, (float)$segment['x'], (float)$segment['y']);
            $mapped[] = ['type'=>$type,'x'=>$x,'y'=>$y];
        }
        return $mapped;
    }

    /** @return array{0:float,1:float} */
    private function point(TransformMatrix $matrix, float $x, float $y): array
    {
        return [
            $matrix->a * $x + $matrix->c * $y + $matrix->e,
            $matrix->b * $x + $matrix->d * $y + $matrix->f,
        ];
    }

    private function strokeScale(TransformMatrix $matrix): float
    {
        $det = $matrix->a * $matrix->d - $matrix->b * $matrix->c;
        if (is_finite($det) && abs($det) > 1e-12) return sqrt(abs($det));
        $a = hypot($matrix->a, $matrix->b);
        $b = hypot($matrix->c, $matrix->d);
        return max(1e-9, ($a + $b) / 2.0);
    }

    private function paintColor(string $raw, AtomicInlineBox $box, float $opacity): ?Rgba
    {
        $raw = trim($raw);
        if ($raw === '' || strtolower($raw) === 'none' || $opacity <= 0.0) return null;
        if (strtolower($raw) === 'currentcolor') {
            $raw = $box->style->get('color', 'black') ?? 'black';
        }
        $color = ColorParser::parse($raw);
        if (!$color instanceof Rgba) return null;
        return new Rgba($color->r, $color->g, $color->b, $color->a * $opacity * Opacity::of($box->style));
    }

    private function unit(mixed $value): float
    {
        return max(0.0, min(1.0, is_numeric($value) ? (float) $value : 1.0));
    }
}
