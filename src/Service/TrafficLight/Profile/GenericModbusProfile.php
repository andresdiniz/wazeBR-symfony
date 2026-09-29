<?php

declare(strict_types=1);

namespace App\Service\TrafficLight\Profile;

final class GenericModbusProfile implements VendorProfileInterface
{
    public function getName(): string { return 'generic'; }
    public function getProtocol(): string { return 'MODBUS_TCP'; }

    public function getOids(): array { return []; }

    public function getRegisters(): array
    {
        return [
            'phaseStatusStart'   => 0x0000,
            'cycleSeconds'       => 0x0001,
            'modeRegister'       => 0x0010,
            'phaseForceRegister' => 0x0011,
            'clearFaultRegister' => 0x0012,
            'cycleRegister'      => 0x0020,
            'offsetRegister'     => 0x0021,
            'splitBase'          => 0x0030,
        ];
    }
}
