<?php

declare(strict_types=1);

namespace App\Dto\TrafficLight;

final readonly class TrafficLightFault
{
    public const SEVERITY_CRITICAL = 'CRITICAL';
    public const SEVERITY_MAJOR    = 'MAJOR';
    public const SEVERITY_MINOR    = 'MINOR';
    public const SEVERITY_WARNING  = 'WARNING';

    public function __construct(
        public string $code,
        public string $severity,
        public string $message,
        public ?\DateTimeImmutable $detectedAt = null,
    ) {}
}
