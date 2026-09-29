<?php

declare(strict_types=1);

namespace App\Service\TrafficLight\Profile;

final class TescNtcipProfile implements VendorProfileInterface
{
    public function getName(): string { return 'tesc'; }
    public function getProtocol(): string { return 'NTCIP'; }

    public function getOids(): array
    {
        return [
            'unitControlStatus'          => '1.3.6.1.4.1.1206.4.1.1.1.2.1.4.0',
            'unitFlashStatus'            => '1.3.6.1.4.1.1206.4.1.1.1.2.1.3.0',
            'unitAlarmStatus2'           => '1.3.6.1.4.1.1206.4.1.1.1.2.1.2.0',
            'phaseStatusGroupReds'       => '1.3.6.1.4.1.1206.4.1.1.1.2.1.5.0',
            'phaseStatusGroupYellows'    => '1.3.6.1.4.1.1206.4.1.1.1.2.1.6.0',
            'phaseStatusGroupGreens'     => '1.3.6.1.4.1.1206.4.1.1.1.2.1.7.0',
            'phaseStatusGroupPedClears'  => '1.3.6.1.4.1.1206.4.1.1.1.2.1.9.0',
            'unitControl'                => '1.3.6.1.4.1.1206.4.1.1.1.2.2.1.0',
            'phaseControlGroupPhaseOmit' => '1.3.6.1.4.1.1206.4.1.1.1.2.3.1.0',
        ];
    }

    public function getRegisters(): array { return []; }
}
