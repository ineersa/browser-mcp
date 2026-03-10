<?php

declare(strict_types=1);

namespace App\Domain\Find;

enum FindMatchMode: string
{
    case CONTAINS = 'contains';
    case EXACT = 'exact';
}
