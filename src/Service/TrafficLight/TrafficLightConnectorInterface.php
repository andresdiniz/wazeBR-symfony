<?php

declare(strict_types=1);

namespace App\Service\TrafficLight;

use App\Dto\TrafficLight\TrafficLightCommand;
use App\Dto\TrafficLight\TrafficLightState;

interface TrafficLightConnectorInterface
{
    /** Protocolo suportado (ex.: 'NTCIP', 'MODBUS_TCP', 'HTTP_REST', 'FAKE'). */
    public function supports(string $protocol): bool;

    /**
     * Lê o estado atual do controlador.
     *
     * @param string               $endpoint Endereço do controlador (host, IP:porta ou URL base)
     * @param array<string,mixed>  $options  Opções específicas do protocolo (community SNMP, unitId, OIDs customizados, timeout etc.)
     */
    public function read(string $endpoint, array $options = []): TrafficLightState;

    /**
     * Envia um comando ao controlador. Retorna true se o controlador confirmou.
     *
     * @param array<string,mixed> $options
     */
    public function write(
        string $endpoint,
        TrafficLightCommand $command,
        array $options = [],
    ): bool;
}
