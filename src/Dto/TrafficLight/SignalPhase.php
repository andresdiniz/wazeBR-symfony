<?php

declare(strict_types=1);

namespace App\Dto\TrafficLight;

final readonly class SignalPhase
{
    public const COLOR_RED    = 'RED';
    public const COLOR_YELLOW = 'YELLOW';
    public const COLOR_GREEN  = 'GREEN';
    public const COLOR_OFF    = 'OFF';

    public function __construct(
        public int $number,
        public string $color,
        public ?int $remainingSeconds = null,
        public bool $pedestrian = false,
    ) {}

    public function isGreen(): bool  { return $this->color === self::COLOR_GREEN; }
    public function isRed(): bool    { return $this->color === self::COLOR_RED; }
    public function isYellow(): bool { return $this->color === self::COLOR_YELLOW; }
}
