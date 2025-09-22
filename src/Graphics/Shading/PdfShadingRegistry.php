<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Graphics\Shading;

use Celsowm\PagyraPhp\Core\PdfBuilder;

/**
 * Responsabilidade única: criar/cachear objetos /Shading e registrar nos recursos da página.
 */
final class PdfShadingRegistry
{
    public function __construct(private PdfBuilder $pdf) {}

    /** @var array<string, array{name:string,id:int}> */
    private array $cache = [];
    private int $seq = 0;

    public function registerOnPage(string $name, int $objId): void
    {
        $this->pdf->registerPageResource('Shading', $name, $objId);
    }

    /**
     * @param 'axial'|'radial' $type
     * @param array<int,float> $coords
     * @param array{0:bool,1:bool} $extend
     * @return array{name:string,id:int}
     */
    public function getOrCreate(string $type, array $coords, int $fnId, array $extend): array
    {
        $key = md5(serialize([$type, $coords, $fnId, $extend]));
        if (isset($this->cache[$key])) return $this->cache[$key];

        $name = '/Sh' . (++$this->seq);
        $sid  = $this->pdf->newObjectId();

        if ($type === 'axial') {
            [$x0, $y0, $x1, $y1] = $coords;
            $body = sprintf(
                "<< /ShadingType 2 /ColorSpace /DeviceRGB /Coords [%.6F %.6F %.6F %.6F] /Function %d 0 R /Extend [%s %s] >>",
                $x0,
                $y0,
                $x1,
                $y1,
                $fnId,
                $extend[0] ? 'true' : 'false',
                $extend[1] ? 'true' : 'false'
            );
        } else { // radial
            [$x0, $y0, $r0, $x1, $y1, $r1] = $coords;
            $body = sprintf(
                "<< /ShadingType 3 /ColorSpace /DeviceRGB /Coords [%.6F %.6F %.6F %.6F %.6F %.6F] /Function %d 0 R /Extend [%s %s] >>",
                $x0,
                $y0,
                $r0,
                $x1,
                $y1,
                $r1,
                $fnId,
                $extend[0] ? 'true' : 'false',
                $extend[1] ? 'true' : 'false'
            );
        }

        $this->pdf->setObject($sid, $body);
        return $this->cache[$key] = ['name' => $name, 'id' => $sid];
    }
}
