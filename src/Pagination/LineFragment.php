<?php

declare(strict_types=1);

namespace Pagyra\Pagination;

use Pagyra\Layout\LineBox;

final readonly class LineFragment implements \JsonSerializable
{
    public function __construct(
        public LineBox $line,
        public int $lineIndex,
        public int $pageIndex,
        public float $pageY,
        public float $pageBaseline,
        public float $continuousY,
        public float $continuousBaseline,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'lineIndex' => $this->lineIndex,
            'pageIndex' => $this->pageIndex,
            'pageY' => $this->pageY,
            'pageBaseline' => $this->pageBaseline,
            'continuousY' => $this->continuousY,
            'continuousBaseline' => $this->continuousBaseline,
            'line' => $this->line,
        ];
    }
}
