<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Graphics;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Color\PdfColor;
use Celsowm\PagyraPhp\Image\PdfImageManager;
use Celsowm\PagyraPhp\Graphics\State\PdfExtGStateManager;

final class PdfGraphicsRenderer
{
    private PdfBuilder $builder;
    private PdfColor $colorManager;
    private PdfImageManager $imageManager;
    private PdfExtGStateManager $extGStateManager;

    public function __construct(PdfBuilder $builder)
    {
        $this->builder = $builder;
        $this->colorManager = $builder->getColorManager();
        $this->imageManager = $builder->getImageManager();
        $this->extGStateManager = $builder->getExtGStateManager();
    }

    public function getColorManager(): PdfColor
    {
        return $this->colorManager;
    }

    public function getImageManager(): PdfImageManager
    {
        return $this->imageManager;
    }

    public function getExtGStateManager(): PdfExtGStateManager
    {
        return $this->extGStateManager;
    }

    public function appendToPageContent(string $ops): void
    {
        $this->builder->appendToPageContent($ops);
    }

    public function registerPageResource(string $type, string $label, ?int $value = 0): void
    {
        $this->builder->registerPageResource($type, $label, $value);
    }

    public function insertOpsAt(string $ops, int $at): void
    {
        $this->builder->insertOpsAt($ops, $at);
    }

    public function writeOps(string $ops): void
    {
        $this->builder->writeOps($ops);
    }

    public function getCurrentPage(): ?int
    {
        return $this->builder->getCurrentPage();
    }

    public function colorOps($spec): string
    {
        return $this->colorManager->getFillOps($spec);
    }

    public function strokeColorOps($spec): string
    {
        return $this->colorManager->getStrokeOps($spec);
    }

    public function normalizeColor($color): ?array
    {
        return $this->colorManager->normalize($color);
    }

    public function normalizeShadowSpec($spec): ?array
    {
        if (!is_array($spec)) return null;
        return [
            'dx' => (float)($spec['dx'] ?? 0.6),
            'dy' => (float)($spec['dy'] ?? -0.6),
            'alpha' => max(0.0, min(1.0, (float)($spec['alpha'] ?? 0.35))),
            'blur' => max(0.0, (float)($spec['blur'] ?? 0.0)),
            'samples' => max(1, (int)($spec['samples'] ?? 8)),
            'color' => $this->normalizeColor($spec['color'] ?? ['gray' => 0.0]) ?? ['space' => 'gray', 'v' => [0.0]]
        ];
    }

    public function normalizeBorderSpec($border, $padding): array
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
        if (isset($color['space']) || is_string($color)) $spec['color'] = array_fill(0, 4, $this->normalizeColor($color));
        elseif (is_array($color) && count($color) === 4) {
            for ($i = 0; $i < 4; $i++) $spec['color'][$i] = $this->normalizeColor($color[$i]);
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

    public function normalizePadding($padding): array
    {
        if (is_numeric($padding)) return array_fill(0, 4, (float)$padding);
        if (is_array($padding)) {
            $c = count($padding);
            if ($c === 1) return array_fill(0, 4, (float)$padding[0]);
            if ($c === 2) return [(float)$padding[0], (float)$padding[1], (float)$padding[0], (float)$padding[1]];
            if ($c === 3) return [(float)$padding[0], (float)$padding[1], (float)$padding[2], (float)$padding[1]];
            if ($c === 4) return array_map('floatval', $padding);
        }
        return [0.0, 0.0, 0.0, 0.0];
    }

    public function buildBackgroundRectOps(float $x, float $y, float $w, float $h, array $color): string
    {
        return "q\n" . $this->colorOps($color) . sprintf("%.3F %.3F %.3F %.3F re\n", $x, $y, $w, $h) . "f\nQ\n";
    }

    public function drawBackgroundRect(float $x, float $y, float $width, float $height, array $color): void
    {
        if ($this->getCurrentPage() === null) return;
        $this->appendToPageContent($this->buildBackgroundRectOps($x, $y, $width, $height, $color));
    }

    private function clampCornerRadii(float $w, float $h, array $r): array
    {
        $r = array_map('floatval', $r);
        for ($i = 0; $i < 4; $i++) $r[$i] = max(0.0, min($r[$i], min($w, $h) * 0.5));
        if (($sum = $r[0] + $r[1]) > $w) {
            $r[0] *= $w / $sum;
            $r[1] *= $w / $sum;
        }
        if (($sum = $r[3] + $r[2]) > $w) {
            $r[3] *= $w / $sum;
            $r[2] *= $w / $sum;
        }
        if (($sum = $r[0] + $r[3]) > $h) {
            $r[0] *= $h / $sum;
            $r[3] *= $h / $sum;
        }
        if (($sum = $r[1] + $r[2]) > $h) {
            $r[1] *= $h / $sum;
            $r[2] *= $h / $sum;
        }
        return $r;
    }

    public function buildRoundedRectPath(float $x, float $y, float $w, float $h, array $r): string
    {
        $r = $this->clampCornerRadii($w, $h, $r);
        [$rtl, $rtr, $rbr, $rbl] = $r;
        $K = 0.55228474983;
        $path = sprintf('%.3F %.3F m', $x + $rtl, $y + $h);
        $path .= sprintf(' %.3F %.3F l', $x + $w - $rtr, $y + $h);
        if ($rtr > 0) $path .= sprintf(' %.3F %.3F %.3F %.3F %.3F %.3F c', $x + $w - $rtr * (1 - $K), $y + $h, $x + $w, $y + $h - $rtr * (1 - $K), $x + $w, $y + $h - $rtr);
        $path .= sprintf(' %.3F %.3F l', $x + $w, $y + $rbr);
        if ($rbr > 0) $path .= sprintf(' %.3F %.3F %.3F %.3F %.3F %.3F c', $x + $w, $y + $rbr * (1 - $K), $x + $w - $rbr * (1 - $K), $y, $x + $w - $rbr, $y);
        $path .= sprintf(' %.3F %.3F l', $x + $rbl, $y);
        if ($rbl > 0) $path .= sprintf(' %.3F %.3F %.3F %.3F %.3F %.3F c', $x + $rbl * (1 - $K), $y, $x, $y + $rbl * (1 - $K), $x, $y + $rbl);
        $path .= sprintf(' %.3F %.3F l', $x, $y + $h - $rtl);
        if ($rtl > 0) $path .= sprintf(' %.3F %.3F %.3F %.3F %.3F %.3F c', $x, $y + $h - $rtl * (1 - $K), $x + $rtl * (1 - $K), $y + $h, $x + $rtl, $y + $h);
        $path .= " h\n";
        return $path;
    }

    public function buildRoundedBackgroundRectOps(float $x, float $y, float $w, float $h, array $r, array $color): string
    {
        return "q\n" . $this->colorOps($color) . $this->buildRoundedRectPath($x, $y, $w, $h, $r) . "f\nQ\n";
    }

    public function drawRoundedBackgroundRect(float $x, float $y, float $w, float $h, array $r, array $color): void
    {
        if ($this->getCurrentPage() === null) return;
        $this->appendToPageContent($this->buildRoundedBackgroundRectOps($x, $y, $w, $h, $r, $color));
    }

    private function borderIsUniform(array $spec): bool
    {
        $eq4 = function (array $arr, float $eps = 1e-6): bool {
            if (count($arr) !== 4) return false;
            $a = $arr[0];
            for ($i = 1; $i < 4; $i++) {
                if (is_numeric($a) && is_numeric($arr[$i])) {
                    if (abs($a - $arr[$i]) > $eps) return false;
                } else {
                    if ($arr[$i] !== $a) return false;
                }
            }
            return true;
        };
        return $eq4($spec['width']) && $eq4($spec['dash']) && $eq4($spec['color']);
    }

    public function drawParagraphBorders(array $box, array $spec): void
    {
        if ($this->getCurrentPage() === null) return;
        $x = $box['x'];
        $y = $box['y'];
        $w = $box['w'];
        $h = $box['h'];
        $r = $spec['radius'] ?? [0, 0, 0, 0];
        $hasRadius = is_array($r) ? (max($r) > 1e-4) : ((float)$r > 1e-4);
        if (!is_array($r)) $r = array_fill(0, 4, (float)$r);

        if ($hasRadius && $this->borderIsUniform($spec) && $spec['width'][0] > 1e-3) {
            $ops = "q\n" . sprintf("%.3F w\n", $spec['width'][0]) . $this->strokeColorOps($spec['color'][0]) . $spec['dash'][0] . "\n1 j\n" .
                $this->buildRoundedRectPath($x, $y, $w, $h, $r) . "S\nQ\n";
            $this->appendToPageContent($ops);
            return;
        }
        $ops = "q\n";
        $sides = [[[$x, $y + $h], [$x + $w, $y + $h]], [[$x + $w, $y + $h], [$x + $w, $y]], [[$x + $w, $y], [$x, $y]], [[$x, $y], [$x, $y + $h]]];
        for ($i = 0; $i < 4; $i++) {
            if ($spec['width'][$i] > 1e-3) {
                $ops .= sprintf("%.3F w\n", $spec['width'][$i]) . $this->strokeColorOps($spec['color'][$i]) . $spec['dash'][$i] . "\n" .
                    sprintf("%.3F %.3F m\n", $sides[$i][0][0], $sides[$i][0][1]) . sprintf("%.3F %.3F l\n", $sides[$i][1][0], $sides[$i][1][1]) . "S\n";
            }
        }
        $this->appendToPageContent($ops . "Q\n");
    }

    public function drawHorizontalLineAt(float $x, float $y, float $width, array $opts): void
    {
        if ($this->getCurrentPage() === null) return;
        $ops = "q\n" . sprintf("%.3F w\n", $opts['height']);
        if (($color = $this->normalizeColor($opts['color'])) !== null) $ops .= $this->strokeColorOps($color);
        if ($opts['dash'] !== null && is_array($opts['dash'])) $ops .= sprintf("[%.1F %.1F] 0 d\n", $opts['dash'][0], $opts['dash'][1]);
        else $ops .= match ($opts['style']) {
            'dashed' => "[6 3] 0 d\n",
            'dotted' => "[1 2] 0 d\n",
            default => "[] 0 d\n",
        };
        $ops .= sprintf("%.3F %.3F m\n%.3F %.3F l\nS\nQ\n", $x, $y, $x + $width, $y);
        $this->appendToPageContent($ops);
    }

    private function buildImageOps(string $alias, float $x, float $y, float $w, float $h, ?array $opts = null): string
    {
        if ($this->getCurrentPage() === null) return '';
        $img = $this->imageManager->getImage($alias);
        if ($img === null) throw new \LogicException("Imagem '{$alias}' não registrada.");

        $ops = "q\n";
        if (isset($opts['alpha']) && (float)$opts['alpha'] < 1.0) {
            [$gsName, $gsId] = $this->getExtGStateManager()->ensureAlphaRef(
                max(0.0, min(1.0, (float)$opts['alpha']))
            );
            $this->registerPageResource('ExtGState', $gsName, $gsId);
            $ops .= "{$gsName} gs\n";
        }
        $ops .= sprintf("%.3F 0 0 %.3F %.3F %.3F cm\n", $w, $h, $x, $y);
        $ops .= $img['name'] . " Do\nQ\n";
        $this->registerPageResource('XObject', $img['name'], $img['objId']);
        return $ops;
    }

    private function fitImageInBox(float $imgW, float $imgH, float $boxX, float $boxY, float $boxW, float $boxH, array $opts): array
    {
        $mode = strtolower($opts['mode'] ?? 'cover');
        $align = strtolower($opts['align'] ?? 'center');
        $valign = strtolower($opts['valign'] ?? 'middle');
        $offX = (float)($opts['offsetX'] ?? 0.0);
        $offY = (float)($opts['offsetY'] ?? 0.0);

        if (isset($opts['size'])) {
            $tw = (float)($opts['size']['w'] ?? 0.0);
            $th = (float)($opts['size']['h'] ?? 0.0);
            if ($tw > 0 && $th <= 0) $th = $tw * ($imgH / $imgW);
            if ($th > 0 && $tw <= 0) $tw = $th * ($imgW / $imgH);
            if ($tw > 0 && $th > 0) {
                $x = match ($align) {
                    'left' => $boxX,
                    'right' => $boxX + $boxW - $tw,
                    default => $boxX + ($boxW - $tw) / 2
                };
                $y = match ($valign) {
                    'top' => $boxY + $boxH - $th,
                    'bottom' => $boxY,
                    default => $boxY + ($boxH - $th) / 2
                };
                return [$x + $offX, $y + $offY, $tw, $th];
            }
        }
        if ($mode === 'stretch') return [$boxX + $offX, $boxY + $offY, $boxW, $boxH];

        $scale = 1.0;
        if ($mode === 'contain') $scale = min($boxW / $imgW, $boxH / $imgH);
        elseif ($mode === 'cover') $scale = max($boxW / $imgW, $boxH / $imgH);

        $tw = $imgW * $scale;
        $th = $imgH * $scale;
        $x = match ($align) {
            'left' => $boxX,
            'right' => $boxX + $boxW - $tw,
            default => $boxX + ($boxW - $tw) / 2
        };
        $y = match ($valign) {
            'top' => $boxY + $boxH - $th,
            'bottom' => $boxY,
            default => $boxY + ($boxH - $th) / 2
        };
        return [$x + $offX, $y + $offY, $tw, $th];
    }

    public function drawBackgroundImageInRect(string $alias, float $x, float $y, float $w, float $h, array $opts = [], ?int $insertAt = null): void
    {
        $img = $this->imageManager->getImage($alias);
        if ($img === null) throw new \LogicException("Imagem '{$alias}' não registrada.");

        $alpha = $opts['alpha'] ?? 0.08;
        $repeat = strtolower($opts['repeat'] ?? 'no-repeat');
        $opsAll = '';
        if ($repeat !== 'tile') {
            [$ix, $iy, $iw, $ih] = $this->fitImageInBox($img['w'], $img['h'], $x, $y, $w, $h, $opts);
            $opsAll = $this->buildImageOps($alias, $ix, $iy, $iw, $ih, ['alpha' => $alpha]);
        } else {
            $tw = $opts['tileSize']['w'] ?? null;
            $th = $opts['tileSize']['h'] ?? null;
            if ($tw === null && $th === null) {
                $th = max(24.0, $h * 0.25);
                $tw = $th * ($img['w'] / $img['h']);
            } elseif ($tw !== null && $th === null) $th = (float)$tw * ($img['h'] / $img['w']);
            elseif ($tw === null && $th !== null) $tw = (float)$th * ($img['w'] / $img['h']);
            $tw = (float)$tw;
            $th = (float)$th;
            $gapX = (float)($opts['tileGap']['x'] ?? 0.0);
            $gapY = (float)($opts['tileGap']['y'] ?? 0.0);
            for ($yy = $y; $yy < $y + $h; $yy += $th + $gapY) {
                for ($xx = $x; $xx < $x + $w; $xx += $tw + $gapX) {
                    $opsAll .= $this->buildImageOps($alias, $xx, $yy, $tw, $th, ['alpha' => $alpha]);
                }
            }
        }
        if ($opsAll !== '') {
            if ($insertAt !== null) $this->insertOpsAt($opsAll, $insertAt);
            else $this->appendToPageContent($opsAll);
        }
    }
}
