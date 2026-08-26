<?php

declare(strict_types=1);

namespace App\Entity;

enum StationType: string
{
    case PLUVIOMETRIC = 'pluviometric';
    case HYDROLOGICAL = 'hydrological';
}
