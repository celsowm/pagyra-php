<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Core;

use Celsowm\PagyraPhp\Graphics\PdfGraphicsRenderer;

class PdfBorderManager
{
    private PdfGraphicsRenderer $graphicsRenderer;
    private $colorNormalizer;

    public function __construct(PdfGraphicsRenderer $graphicsRenderer)
    {
        $this->graphicsRenderer = $graphicsRenderer;
    }

    /**
     * Normaliza especificações de borda e padding
     */
    public function normalizeBorderSpec($border, $padding): array
    {
        $spec = [
            'hasBorder' => $border !== null,
            'width' => [0.0, 0.0, 0.0, 0.0],
            'color' => [null, null, null, null],
            'dash' => [null, null, null, null],
            'padding' => [0.0, 0.0, 0.0, 0.0],
            'radius' => [0.0, 0.0, 0.0, 0.0]
        ];

        // Normaliza padding
        if (is_numeric($padding)) {
            $spec['padding'] = array_fill(0, 4, (float)$padding);
        } elseif (is_array($padding) && count($padding) === 4) {
            $spec['padding'] = array_map('floatval', $padding);
        }

        if (!$spec['hasBorder']) {
            if (is_array($border) && isset($border['radius'])) {
                $r = $border['radius'];
                if (is_numeric($r)) {
                    $spec['radius'] = array_fill(0, 4, (float)$r);
                } elseif (is_array($r) && count($r) === 4) {
                    $spec['radius'] = array_map('floatval', $r);
                }
            }
            return $spec;
        }

        // Normaliza largura da borda
        $width = $border['width'] ?? 1.0;
        if (is_numeric($width)) {
            $spec['width'] = array_fill(0, 4, (float)$width);
        } elseif (is_array($width) && count($width) === 4) {
            $spec['width'] = array_map('floatval', $width);
        }

        // Normaliza cores da borda
        $color = $border['color'] ?? ['gray' => 0.0];
        if (isset($color['space']) || is_string($color)) {
            $spec['color'] = array_fill(0, 4, $this->normalizeColor($color));
        } elseif (is_array($color) && count($color) === 4) {
            for ($i = 0; $i < 4; $i++) {
                $spec['color'][$i] = $this->normalizeColor($color[$i]);
            }
        }

        // Normaliza estilo da borda
        $style = $border['style'] ?? 'solid';
        $spec['dash'] = ($style === 'dashed') ? array_fill(0, 4, '[3 3] 0 d') : array_fill(0, 4, '[] 0 d');

        // Normaliza radius
        if (isset($border['radius'])) {
            $r = $border['radius'];
            if (is_numeric($r)) {
                $spec['radius'] = array_fill(0, 4, (float)$r);
            } elseif (is_array($r) && count($r) === 4) {
                $spec['radius'] = array_map('floatval', $r);
            }
        }

        return $spec;
    }

    /**
     * Verifica se a borda é uniforme (mesma largura, cor e estilo em todos os lados)
     */
    public function borderIsUniform(array $spec): bool
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

    /**
     * Desenha linha horizontal em posição específica
     */
    public function drawHorizontalLineAt(float $x, float $y, float $width, array $opts): void
    {
        $this->graphicsRenderer->drawHorizontalLineAt($x, $y, $width, $opts);
    }

    /**
     * Adiciona linha horizontal com opções
     */
    public function addHorizontalLine(array $options = []): array
    {
        // Esta função seria usada pelo PdfBuilder para calcular posição e opções
        // Retorna as opções processadas para que o PdfBuilder possa usar
        $opts = array_merge([
            'width' => '100%',
            'height' => 0.5,
            'color' => ['gray' => 0.5],
            'style' => 'solid',
            'align' => 'center',
            'spacing' => 5.0,
            'dash' => null
        ], $options);

        return [
            'processed_options' => $opts,
            'needs_positioning' => true
        ];
    }

    /**
     * Adiciona linha horizontal em posição absoluta
     */
    public function addHorizontalLineAbs(float $x, float $y, float $width, array $options = []): array
    {
        $opts = array_merge([
            'height' => 0.5,
            'color' => ['gray' => 0.5],
            'style' => 'solid',
            'dash' => null
        ], $options);

        return [
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'processed_options' => $opts,
            'needs_positioning' => false
        ];
    }

    /**
     * Adiciona separador decorativo
     */
    public function addSeparator(array $options = []): array
    {
        $opts = array_merge([
            'symbol' => '◆',
            'symbolSize' => null,
            'symbolColor' => null,
            'lineWidth' => '30%',
            'lineHeight' => 0.5,
            'lineColor' => ['gray' => 0.5],
            'lineStyle' => 'solid',
            'spacing' => 10.0,
            'gap' => 10.0
        ], $options);

        return [
            'processed_options' => $opts,
            'needs_positioning' => true,
            'needs_font_context' => true
        ];
    }

    /**
     * Desenha bordas de parágrafo
     */
    public function drawParagraphBorders(array $box, array $spec): void
    {
        $this->graphicsRenderer->drawParagraphBorders($box, $spec);
    }

    /**
     * Calcula o padding normalizado
     */
    public function normalizePadding($padding): array
    {
        if (is_numeric($padding)) {
            return array_fill(0, 4, (float)$padding);
        }
        if (is_array($padding)) {
            $c = count($padding);
            if ($c === 1) return array_fill(0, 4, (float)$padding[0]);
            if ($c === 2) return [(float)$padding[0], (float)$padding[1], (float)$padding[0], (float)$padding[1]];
            if ($c === 3) return [(float)$padding[0], (float)$padding[1], (float)$padding[2], (float)$padding[1]];
            if ($c === 4) return array_map('floatval', $padding);
        }
        return [0.0, 0.0, 0.0, 0.0];
    }

    /**
     * Normaliza especificação de cor (delegado para o gerenciador de cores)
     */
    private function normalizeColor($color): ?array
    {
        if ($this->colorNormalizer !== null && is_callable($this->colorNormalizer)) {
            return ($this->colorNormalizer)($color);
        }
        // Fallback básico se o normalizador não estiver disponível
        return null;
    }

    /**
     * Define o normalizador de cores (injetado pelo PdfBuilder)
     */
    public function setColorNormalizer(callable $colorNormalizer): void
    {
        $this->colorNormalizer = $colorNormalizer;
    }

    /**
     * Obtém o graphics renderer
     */
    public function getGraphicsRenderer(): PdfGraphicsRenderer
    {
        return $this->graphicsRenderer;
    }
}
