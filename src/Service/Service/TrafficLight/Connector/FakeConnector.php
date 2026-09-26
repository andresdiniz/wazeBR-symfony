<?php

declare(strict_types=1);

namespace App\Service\TrafficLight\Connector;

use App\Dto\TrafficLight\SignalPhase;
use App\Dto\TrafficLight\TrafficLightCommand;
use App\Dto\TrafficLight\TrafficLightState;
use App\Service\TrafficLight\TrafficLightConnectorInterface;

final class FakeConnector implements TrafficLightConnectorInterface
{
    public function supports(string $protocol): bool
    {
        return in_array(strtoupper($protocol), ['FAKE', 'STUB', 'DEV'], true);
    }

    public function read(string $endpoint, array $options = []): TrafficLightState
    {
        return new TrafficLightState(
            controllerId: $endpoint,
            protocol:     'FAKE',
            readAt:       new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            currentPhase: 1,
            cycleSeconds: 90,
            mode:         TrafficLightState::MODE_AUTOMATIC,
            phases: [
                new SignalPhase(1, SignalPhase::COLOR_GREEN, 25),
                new SignalPhase(2, SignalPhase::COLOR_RED, 45),
                new SignalPhase(3, SignalPhase::COLOR_RED, 65),
            ],
            raw: ['fake' => true],
        );
    }

    public function write(
        string $endpoint,
        TrafficLightCommand $command,
        array $options = [],
    ): bool {
        return true;
    }
}
