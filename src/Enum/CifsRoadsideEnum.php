<?php

namespace App\Enum;

/**
 * Valor "roadside" do bloco lane_impact (formato parcial) da spec CIFS.
 * @see https://developers.google.com/waze/data-feed/cifs-specification
 */
enum CifsRoadsideEnum: string
{
    case LEFT   = 'LEFT';
    case RIGHT  = 'RIGHT';
    case MIDDLE = 'MIDDLE';

    public function label(): string
    {
        return match($this) {
            self::LEFT   => 'Faixa(s) à esquerda',
            self::RIGHT  => 'Faixa(s) à direita',
            self::MIDDLE => 'Faixa(s) centrais',
        };
    }
}
