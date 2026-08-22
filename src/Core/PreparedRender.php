<?php

declare(strict_types=1);

namespace Pagyra\Core;

use Pagyra\Dom\Node;

final readonly class PreparedRender implements \JsonSerializable
{
    /** @param array{widthPt:float,heightPt:float} $pageSize
     *  @param array{top:float,right:float,bottom:float,left:float} $margins
     */
    public function __construct(
        public Node $domRoot,
        public array $pageSize,
        public array $margins,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'domRoot' => $this->domRoot,
            'pageSize' => $this->pageSize,
            'margins' => $this->margins,
        ];
    }
}
