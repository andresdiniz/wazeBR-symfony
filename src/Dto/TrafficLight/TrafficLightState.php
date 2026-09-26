<?php

declare(strict_types=1);

namespace App\Dto\TrafficLight;

final readonly class TrafficLightState
{
    public const MODE_AUTOMATIC = 'AUTOMATIC';
    public const MODE_MANUAL    = 'MANUAL';
    public const MODE_FLASH     = 'FLASH';
    public const MODE_UNKNOWN   = 'UNKNOWN';

    /**
     * @param SignalPhase[]        $phases
     * @param DetectorReading[]    $detectors
     * @param TrafficLightFault[]  $faults
     * @param array<string,mixed>  $raw
     */
    public function __construct(
        public string $controllerId,
        public string $protocol,
        public \DateTimeImmutable $readAt,
        public ?int $currentPhase = null,
        public ?int $cycleSeconds = null,
        public string $mode = self::MODE_UNKNOWN,
        public array $phases = [],
        public array $detectors = [],
        public array $faults = [],
        public array $raw = [],
    ) {}

    public function hasFaults(): bool
    {
        return $this->faults !== [];
    }

    /** @return TrafficLightFault[] */
    public function criticalFaults(): array
    {
        return array_values(array_filter(
            $this->faults,
            static fn (TrafficLightFault $f): bool => $f->severity === TrafficLightFault::SEVERITY_CRITICAL,
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'controllerId'   => $this->controllerId,
            'protocol'       => $this->protocol,
            'readAt'         => $this->readAt->format(\DateTimeInterface::ATOM),
            'currentPhase'   => $this->currentPhase,
            'cycleSeconds'   => $this->cycleSeconds,
            'mode'           => $this->mode,
            'phases'         => array_map(static fn (SignalPhase $p): array => [
                'number'           => $p->number,
                'color'            => $p->color,
                'remainingSeconds' => $p->remainingSeconds,
                'pedestrian'       => $p->pedestrian,
            ], $this->phases),
            'detectors'      => array_map(static fn (DetectorReading $d): array => [
                'id'           => $d->id,
                'occupied'     => $d->occupied,
                'vehicleCount' => $d->vehicleCount,
                'occupancyPct' => $d->occupancyPct,
            ], $this->detectors),
            'faults'         => array_map(static fn (TrafficLightFault $f): array => [
                'code'       => $f->code,
                'severity'   => $f->severity,
                'message'    => $f->message,
                'detectedAt' => $f->detectedAt?->format(\DateTimeInterface::ATOM),
            ], $this->faults),
        ];
    }
}
