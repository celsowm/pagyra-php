<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;

class DocumentUtils
{
    public static function normalizeBorderSpec($border, $padding, PdfBuilder $builder): array
    {
        $spec = ['hasBorder' => $border !== null, 'width' => [0.0, 0.0, 0.0, 0.0], 'color' => [null, null, null, null], 'dash' => [null, null, null, null], 'padding' => [0.0, 0.0, 0.0, 0.0], 'radius' => [0.0, 0.0, 0.0, 0.0]];
        if (is_numeric($padding)) $spec['padding'] = array_fill(0, 4, (float)$padding);
        elseif (is_array($padding) && count($padding) === 4) $spec['padding'] = array_map('floatval', $padding);
        if (!$spec['hasBorder']) {
            if (is_array($border) && isset($border['radius'])) {
                $r = $border['radius'];
                if (is_numeric($r)) $spec['radius'] = array_fill(0, 4, (float)$r);
                elseif (is_array($r) && count($r) === 4) $spec['radius'] = array_map('floatval', $r);
            }
            return $spec;
        }
        $width = $border['width'] ?? 1.0;
        if (is_numeric($width)) $spec['width'] = array_fill(0, 4, (float)$width);
        elseif (is_array($width) && count($width) === 4) $spec['width'] = array_map('floatval', $width);
        $color = $border['color'] ?? ['gray' => 0.0];
        if (isset($color['space']) || is_string($color)) $spec['color'] = array_fill(0, 4, self::normalizeColor($color, $builder));
        elseif (is_array($color) && count($color) === 4) {
            for ($i = 0; $i < 4; $i++) $spec['color'][$i] = self::normalizeColor($color[$i], $builder);
        }
        $style = $border['style'] ?? 'solid';
        $spec['dash'] = ($style === 'dashed') ? array_fill(0, 4, '[3 3] 0 d') : array_fill(0, 4, '[] 0 d');
        if (isset($border['radius'])) {
            $r = $border['radius'];
            if (is_numeric($r)) $spec['radius'] = array_fill(0, 4, (float)$r);
            elseif (is_array($r) && count($r) === 4) $spec['radius'] = array_map('floatval', $r);
        }
        return $spec;
    }

    public static function normalizeColor($color, PdfBuilder $builder): ?array
    {
        return $builder->getColorManager()->normalize($color);
    }

    public static function normalizeShadowSpec($spec, PdfBuilder $builder): ?array
    {
        if (!is_array($spec)) return null;
        return [
            'dx' => (float)($spec['dx'] ?? 0.6),
            'dy' => (float)($spec['dy'] ?? -0.6),
            'alpha' => max(0.0, min(1.0, (float)($spec['alpha'] ?? 0.35))),
            'blur' => max(0.0, (float)($spec['blur'] ?? 0.0)),
            'samples' => max(1, (int)($spec['samples'] ?? 8)),
            'color' => self::normalizeColor($spec['color'] ?? ['gray' => 0.0], $builder) ?? ['space' => 'gray', 'v' => [0.0]]
        ];
    }
}
