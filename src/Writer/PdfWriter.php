<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Writer;

final class PdfWriter
{
    private array $objects = [];
    private array $offsets = [];
    private int $objCount = 0;

    public function newObjectId(): int
    {
        return ++$this->objCount;
    }

    public function setObject(int $id, string $content): void
    {
        $this->objects[$id] = $content;
    }

    public function getObject(int $id): ?string
    {
        return $this->objects[$id] ?? null;
    }

    public function addObject(string $content): int
    {
        $id = $this->newObjectId();
        $this->setObject($id, $content);
        return $id;
    }

    public function buildFontObjects(string $alias, array $fontData, array $usedGids): array
    {
        return [];
    }

    public function buildImageObject(array $imageData): int
    {
        return 0;
    }

    public function output(int $catalogId): string
    {
        $pdf = "%PDF-1.7\n%" . chr(0xE2) . chr(0xE3) . chr(0xCF) . chr(0xD3) . "\n";
        $this->offsets = [];
        for ($id = 1; $id <= $this->objCount; $id++) {
            if (!isset($this->objects[$id])) {
                throw new \LogicException("Objeto PDF com ID {$id} foi alocado mas não definido.");
            }
            $this->offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$this->objects[$id]}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . ($this->objCount + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $this->objCount; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $this->offsets[$i]);
        }
        $pdf .= "trailer << /Size " . ($this->objCount + 1) . " /Root {$catalogId} 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }
}