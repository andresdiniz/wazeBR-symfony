<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Direção válida de acordo com a especificação CIFS do Waze.
 * Se a polyline aponta no sentido do tráfego afetado, use ONE_DIRECTION;
 * se o evento bloqueia os dois sentidos, use BOTH_DIRECTIONS.
 */
enum CifsDirectionEnum: string
{
    case ONE_DIRECTION = 'ONE_DIRECTION';
    case BOTH_DIRECTIONS = 'BOTH_DIRECTIONS';
}