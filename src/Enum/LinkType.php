<?php

declare(strict_types=1);

namespace App\Enum;

enum LinkType: string
{
    case WazeFeed = 'waze_feed';   // Feed PartnerHub (alertas + jams)
    case WazeTvt  = 'waze_tvt';   // Feed TVT (tempos de viagem)
    case Camera   = 'camera';      // Câmera de tráfego
    case Sensor   = 'sensor';      // Sensor / estação
    case Cemaden  = 'cemaden';     // Feed CEMADEN
    case Other    = 'other';       // Outros

    public function label(): string
    {
        return match ($this) {
            self::WazeFeed => 'Feed Waze (Alertas/Jams)',
            self::WazeTvt  => 'Feed Waze TVT (Tempos de Viagem)',
            self::Camera   => 'Câmera',
            self::Sensor   => 'Sensor',
            self::Cemaden  => 'CEMADEN',
            self::Other    => 'Outro',
        };
    }
}
