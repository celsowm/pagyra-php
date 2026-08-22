<?php

declare(strict_types=1);

namespace Pagyra\Core;

use Pagyra\Dom\Node;
use Pagyra\Layout\LayoutNode;
use Pagyra\Style\StyledNode;

final readonly class PreparedRender implements \JsonSerializable
{
    /**
     * @param array{widthPt:float,heightPt:float} $pageSize
     * @param array{top:float,right:float,bottom:float,left:float} $margins
     * @param list<string> $stylesheetHrefs
     */
    public function __construct(
        public Node $domRoot,
        public StyledNode $styledRoot,
        public LayoutNode $layoutRoot,
        public string $cssText,
        public array $stylesheetHrefs,
        public array $pageSize,
        public array $margins,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'domRoot' => $this->domRoot,
            'styledRoot' => $this->styledRoot,
            'layoutRoot' => $this->layoutRoot,
            'cssText' => $this->cssText,
            'stylesheetHrefs' => $this->stylesheetHrefs,
            'pageSize' => $this->pageSize,
            'margins' => $this->margins,
        ];
    }
}
