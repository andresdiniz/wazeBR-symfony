<?php

declare(strict_types=1);

namespace App\Dto\TrafficLight;

final readonly class TrafficLightCommand
{
    public const SET_PHASE     = 'SET_PHASE';
    public const SET_MODE      = 'SET_MODE';
    public const FORCE_GREEN   = 'FORCE_GREEN';
    public const FORCE_RED     = 'FORCE_RED';
    public const FLASH_YELLOW  = 'FLASH_YELLOW';
    public const CLEAR_FAULT   = 'CLEAR_FAULT';

    /** @param array<string,mixed> $payload */
    public function __construct(
        public string $type,
        public array $payload = [],
        public ?string $reason = null,
    ) {
        $allowed = [
            self::SET_PHASE, self::SET_MODE, self::FORCE_GREEN,
            self::FORCE_RED, self::FLASH_YELLOW, self::CLEAR_FAULT,
        ];
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf('Comando desconhecido: %s', $type));
        }
    }
}
