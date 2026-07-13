<?php

namespace App\Enum;

enum CifsDirectionEnum: string
{
    case BOTH_DIRECTIONS = 'BOTH_DIRECTIONS';
    case ONE_DIRECTION   = 'ONE_DIRECTION';

    public function label(): string
    {
        return match($this) {
            self::BOTH_DIRECTIONS => 'Ambos os sentidos',
            self::ONE_DIRECTION   => 'Um sentido',
        };
    }
}
