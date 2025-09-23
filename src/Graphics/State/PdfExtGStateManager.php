<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Graphics\State;

use Celsowm\PagyraPhp\Core\PdfBuilder;

final class PdfExtGStateManager
{
    private int $seq = 0;
    /** @var array<string, array{name:string,id:int}> */
    private array $pool = [];

    public function __construct(private PdfBuilder $pdf) {}

    /** API simples: só alpha (fill e stroke iguais) -> retorna o nome (/GSn) */
    public function ensureAlpha(float $alpha): string
    {
        [$name, $_] = $this->ensureAlphaRef($alpha);
        return $name;
    }

    /** Versão que retorna [nome, id] — ideal para registrar no /Resources */
    public function ensureAlphaRef(float $alpha): array
    {
        $a = $this->clamp01($alpha);
        $key = json_encode(['ca' => $a, 'CA' => $a], JSON_THROW_ON_ERROR);
        if (isset($this->pool[$key])) {
            return [$this->pool[$key]['name'], $this->pool[$key]['id']];
        }

        $name = '/GS' . (++$this->seq);
        $id   = $this->pdf->newObjectId();
        $dict = sprintf('<< /Type /ExtGState /ca %.3F /CA %.3F >>', $a, $a);
        $this->pdf->setObject($id, $dict);

        $this->pool[$key] = ['name' => $name, 'id' => $id];
        return [$name, $id];
    }

    /** Alpha diferentes para fill/stroke, e (opcional) blend mode */
    public function ensureProps(?float $fillAlpha = null, ?float $strokeAlpha = null, ?string $blendMode = null): array
    {
        $props = [];
        if ($fillAlpha !== null)  $props['ca'] = $this->clamp01($fillAlpha);
        if ($strokeAlpha !== null) $props['CA'] = $this->clamp01($strokeAlpha);
        if ($blendMode) $props['BM'] = $this->normalizeBlendMode($blendMode);

        ksort($props);
        $key = json_encode($props, JSON_THROW_ON_ERROR);
        if (isset($this->pool[$key])) {
            return [$this->pool[$key]['name'], $this->pool[$key]['id']];
        }

        $name = '/GS' . (++$this->seq);
        $id   = $this->pdf->newObjectId();

        $parts = ['/Type /ExtGState'];
        if (isset($props['ca'])) $parts[] = sprintf('/ca %.3F', $props['ca']);
        if (isset($props['CA'])) $parts[] = sprintf('/CA %.3F', $props['CA']);
        if (isset($props['BM'])) $parts[] = '/BM /' . $props['BM'];

        $this->pdf->setObject($id, '<< ' . implode(' ', $parts) . ' >>');

        $this->pool[$key] = ['name' => $name, 'id' => $id];
        return [$name, $id];
    }

    /** Para o PdfBuilder usar no fechamento da página, se precisar */
    public function getNameToIdMap(): array
    {
        $out = [];
        foreach ($this->pool as $entry) $out[$entry['name']] = $entry['id'];
        return $out;
    }

    private function clamp01(float $x): float
    {
        if (!is_finite($x)) $x = 0.0;
        return max(0.0, min(1.0, $x));
    }

    private function normalizeBlendMode(string $bm): string
    {
        return match (strtolower($bm)) {
            'multiply' => 'Multiply',
            'screen' => 'Screen',
            'overlay' => 'Overlay',
            'darken' => 'Darken',
            'lighten' => 'Lighten',
            'color-dodge' => 'ColorDodge',
            'color-burn' => 'ColorBurn',
            'hard-light' => 'HardLight',
            'soft-light' => 'SoftLight',
            'difference' => 'Difference',
            'exclusion' => 'Exclusion',
            'hue' => 'Hue',
            'saturation' => 'Saturation',
            'color' => 'Color',
            'luminosity' => 'Luminosity',
            default => 'Normal'
        };
    }
}
