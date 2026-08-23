<?php

declare(strict_types=1);

namespace Pagyra\Svg;

use Pagyra\Dom\Node;

final class SvgDocumentParser
{
    public function __construct(private readonly PathDataParser $paths = new PathDataParser())
    {
    }

    public function parse(Node $root): ?SvgDocument
    {
        if (!$root->isSvg()) return null;

        $viewBox = $this->viewBox($root->attribute('viewbox'));
        $width = $this->number($root->attribute('width'));
        $height = $this->number($root->attribute('height'));
        if ($viewBox !== null) {
            $width ??= $viewBox['width'];
            $height ??= $viewBox['height'];
        }

        $style = $this->style($root, [
            'fill' => 'black',
            'stroke' => 'none',
            'stroke-width' => '1',
            'fill-rule' => 'nonzero',
            'opacity' => '1',
            'fill-opacity' => '1',
            'stroke-opacity' => '1',
        ]);

        $shapes = [];
        foreach ($root->children as $child) {
            $this->collect($child, $style, $shapes);
        }

        return new SvgDocument(
            width: $width,
            height: $height,
            viewBox: $viewBox,
            shapes: $shapes,
            preserveAspectRatio: trim($root->attribute('preserveaspectratio') ?? '') ?: 'xMidYMid meet',
        );
    }

    /** @param array<string,string> $inherited @param list<array<string,mixed>> $shapes */
    private function collect(Node $node, array $inherited, array &$shapes): void
    {
        if ($node->type !== 'element') return;
        $style = $this->style($node, $inherited);
        $tag = $node->tagName ?? '';

        if (in_array($tag, ['svg', 'g'], true)) {
            foreach ($node->children as $child) $this->collect($child, $style, $shapes);
            return;
        }

        $shape = match ($tag) {
            'path' => $this->pathShape($node, $style),
            'rect' => $this->rectShape($node, $style),
            'circle' => $this->circleShape($node, $style),
            'ellipse' => $this->ellipseShape($node, $style),
            'line' => $this->lineShape($node, $style),
            'polyline', 'polygon' => $this->pointsShape($node, $style, $tag),
            default => null,
        };
        if ($shape !== null) $shapes[] = $shape;
    }

    /** @param array<string,string> $style */
    private function pathShape(Node $node, array $style): ?array
    {
        $segments = $this->paths->parse($node->attribute('d'));
        return $segments === [] ? null : $this->shape('path', $style, ['segments' => $segments]);
    }

    /** @param array<string,string> $style */
    private function rectShape(Node $node, array $style): ?array
    {
        $width = $this->number($node->attribute('width')) ?? 0.0;
        $height = $this->number($node->attribute('height')) ?? 0.0;
        if ($width <= 0.0 || $height <= 0.0) return null;
        $rx = $this->number($node->attribute('rx'));
        $ry = $this->number($node->attribute('ry'));
        return $this->shape('rect', $style, [
            'x' => $this->number($node->attribute('x')) ?? 0.0,
            'y' => $this->number($node->attribute('y')) ?? 0.0,
            'width' => $width,
            'height' => $height,
            'rx' => $rx ?? $ry ?? 0.0,
            'ry' => $ry ?? $rx ?? 0.0,
        ]);
    }

    /** @param array<string,string> $style */
    private function circleShape(Node $node, array $style): ?array
    {
        $r = $this->number($node->attribute('r')) ?? 0.0;
        if ($r <= 0.0) return null;
        return $this->shape('circle', $style, [
            'cx' => $this->number($node->attribute('cx')) ?? 0.0,
            'cy' => $this->number($node->attribute('cy')) ?? 0.0,
            'r' => $r,
        ]);
    }

    /** @param array<string,string> $style */
    private function ellipseShape(Node $node, array $style): ?array
    {
        $rx = $this->number($node->attribute('rx')) ?? 0.0;
        $ry = $this->number($node->attribute('ry')) ?? 0.0;
        if ($rx <= 0.0 || $ry <= 0.0) return null;
        return $this->shape('ellipse', $style, [
            'cx' => $this->number($node->attribute('cx')) ?? 0.0,
            'cy' => $this->number($node->attribute('cy')) ?? 0.0,
            'rx' => $rx,
            'ry' => $ry,
        ]);
    }

    /** @param array<string,string> $style */
    private function lineShape(Node $node, array $style): array
    {
        return $this->shape('line', $style, [
            'x1' => $this->number($node->attribute('x1')) ?? 0.0,
            'y1' => $this->number($node->attribute('y1')) ?? 0.0,
            'x2' => $this->number($node->attribute('x2')) ?? 0.0,
            'y2' => $this->number($node->attribute('y2')) ?? 0.0,
        ]);
    }

    /** @param array<string,string> $style */
    private function pointsShape(Node $node, array $style, string $type): ?array
    {
        $raw = trim($node->attribute('points') ?? '');
        if ($raw === '') return null;
        preg_match_all('/[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $raw, $matches);
        $values = array_map('floatval', $matches[0] ?? []);
        if (count($values) < 4) return null;
        $points = [];
        for ($i = 0; $i + 1 < count($values); $i += 2) $points[] = ['x' => $values[$i], 'y' => $values[$i + 1]];
        return $this->shape($type, $style, ['points' => $points]);
    }

    /** @param array<string,string> $style @param array<string,mixed> $geometry */
    private function shape(string $type, array $style, array $geometry): array
    {
        return [
            'type' => $type,
            ...$geometry,
            'fill' => $style['fill'],
            'stroke' => $style['stroke'],
            'strokeWidth' => max(0.0, $this->number($style['stroke-width']) ?? 1.0),
            'fillRule' => strtolower($style['fill-rule']) === 'evenodd' ? 'evenodd' : 'nonzero',
            'opacity' => $this->unit($style['opacity']),
            'fillOpacity' => $this->unit($style['fill-opacity']),
            'strokeOpacity' => $this->unit($style['stroke-opacity']),
        ];
    }

    /** @param array<string,string> $inherited @return array<string,string> */
    private function style(Node $node, array $inherited): array
    {
        $result = $inherited;
        foreach (['fill', 'stroke', 'stroke-width', 'fill-rule', 'opacity', 'fill-opacity', 'stroke-opacity'] as $name) {
            $value = $node->attribute($name);
            if ($value !== null && trim($value) !== '') $result[$name] = trim($value);
        }
        $inline = $node->attribute('style');
        if ($inline !== null) {
            foreach (explode(';', $inline) as $declaration) {
                $parts = explode(':', $declaration, 2);
                if (count($parts) !== 2) continue;
                $name = strtolower(trim($parts[0]));
                if (array_key_exists($name, $result)) $result[$name] = trim($parts[1]);
            }
        }
        return $result;
    }

    /** @return array{minX:float,minY:float,width:float,height:float}|null */
    private function viewBox(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') return null;
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) return null;
        $width = (float) $parts[2]; $height = (float) $parts[3];
        if ($width <= 0.0 || $height <= 0.0) return null;
        return ['minX' => (float) $parts[0], 'minY' => (float) $parts[1], 'width' => $width, 'height' => $height];
    }

    private function number(?string $raw): ?float
    {
        if ($raw === null || trim($raw) === '') return null;
        if (preg_match('/^[\s]*([-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?)/', $raw, $m) !== 1) return null;
        $value = (float) $m[1];
        return is_finite($value) ? $value : null;
    }

    private function unit(string $raw): float
    {
        $value = $this->number($raw) ?? 1.0;
        return max(0.0, min(1.0, $value));
    }
}
