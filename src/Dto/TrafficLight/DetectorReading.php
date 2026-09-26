<?php

declare(strict_types=1);

namespace App\Dto\TrafficLight;

final readonly class DetectorReading
{
    public function __construct(
        public string $id,
        public bool $occupied,
        public ?int $vehicleCount = null,
        public ?float $occupancyPct = null,
    ) {}
}
