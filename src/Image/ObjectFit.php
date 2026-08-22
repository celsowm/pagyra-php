<?php

declare(strict_types=1);

namespace Pagyra\Image;

enum ObjectFit: string
{
    case Fill = 'fill';
    case Contain = 'contain';
    case Cover = 'cover';
    case None = 'none';
    case ScaleDown = 'scale-down';
}
