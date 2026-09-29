<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\TrafficLight;

use App\Dto\TrafficLight\SignalPhase;
use App\Dto\TrafficLight\TrafficLightFault;
use App\Dto\TrafficLight\TrafficLightState;
use PHPUnit\Framework\TestCase;

final class TrafficLightStateTest extends TestCase
{
    public function testHasFaultsAndCriticalFaults(): void
    {
        $state = new TrafficLightState(
            controllerId: 'x',
            protocol:     'TEST',
            readAt:       new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            faults: [
                new TrafficLightFault('A', TrafficLightFault::SEVERITY_MINOR, 'menor'),
                new TrafficLightFault('B', TrafficLightFault::SEVERITY_CRITICAL, 'crítico'),
            ],
        );

        self::assertTrue($state->hasFaults());
        self::assertCount(1, $state->criticalFaults());
        self::assertSame('B', $state->criticalFaults()[0]->code);
    }

    public function testToArrayShape(): void
    {
        $state = new TrafficLightState(
            controllerId: 'x',
            protocol:     'TEST',
            readAt:       new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            currentPhase: 2,
            phases: [new SignalPhase(2, SignalPhase::COLOR_GREEN, 30)],
        );

        $arr = $state->toArray();
        self::assertSame(2, $arr['currentPhase']);
        self::assertSame('GREEN', $arr['phases'][0]['color']);
        self::assertSame(30, $arr['phases'][0]['remainingSeconds']);
    }
}
