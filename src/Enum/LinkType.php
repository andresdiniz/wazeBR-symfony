<?php

declare(strict_types=1);

namespace App\Enum;

enum LinkType: string
{
    case WazeFeed = 'waze_feed';   // Feed PartnerHub (alertas + jams)
    case Camera   = 'camera';      // Câmera de tráfego
    case Sensor   = 'sensor';      // Sensor / estação
    case Cemaden  = 'cemaden';     // Feed CEMADEN
    case Other    = 'other';       // Outros

    public function label(): string
    {
        return match ($this) {
            self::WazeFeed => 'Feed Waze (Alertas/Jams)',
            self::Camera   => 'Câmera',
            self::Sensor   => 'Sensor',
            self::Cemaden  => 'CEMADEN',
            self::Other    => 'Outro',
        };
    }
}
