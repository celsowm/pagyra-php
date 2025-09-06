<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Table;

final class PdfTableRow
{
    public function __construct(
        public array $cells = [],
        public array $options = []
    ) {}
}