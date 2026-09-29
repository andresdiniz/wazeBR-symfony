<?php

declare(strict_types=1);

namespace App\Dto\TrafficLight;

final readonly class TrafficLightCommand
{
    // ── Modos operacionais ──────────────────────────────────────────────
    public const SET_MODE       = 'SET_MODE';       // AUTOMATIC | MANUAL | FLASH
    public const SET_PHASE      = 'SET_PHASE';      // força uma fase específica
    public const FORCE_GREEN    = 'FORCE_GREEN';    // todas as vias verdes? NÃO — força fase específica
    public const FORCE_RED      = 'FORCE_RED';      // mantém vermelho na fase indicada
    public const FLASH_YELLOW   = 'FLASH_YELLOW';   // piscante amarelo (modo flash)
    public const ALL_RED        = 'ALL_RED';        // tudo vermelho (all-red)
    public const ALL_DARK       = 'ALL_DARK';       // todas as luzes apagadas
    public const CLEAR_FAULT    = 'CLEAR_FAULT';    // limpa falhas/alarmes

    // ── Tempos ───────────────────────────────────────────────────────────
    public const SET_CYCLE      = 'SET_CYCLE';      // duração do ciclo (s)
    public const SET_SPLIT      = 'SET_SPLIT';      // tempo de verde de uma fase (s)
    public const SET_OFFSET     = 'SET_OFFSET';     // offset do ciclo (s)

    public const ALL = [
        self::SET_MODE,
        self::SET_PHASE,
        self::FORCE_GREEN,
        self::FORCE_RED,
        self::FLASH_YELLOW,
        self::ALL_RED,
        self::ALL_DARK,
        self::CLEAR_FAULT,
        self::SET_CYCLE,
        self::SET_SPLIT,
        self::SET_OFFSET,
    ];

    /** @param array<string,mixed> $payload */
    public function __construct(
        public string $type,
        public array $payload = [],
        public ?string $reason = null,
    ) {
        if (!in_array($type, self::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Comando desconhecido: %s', $type));
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'type'    => $this->type,
            'payload' => $this->payload,
            'reason'  => $this->reason,
        ];
    }
}
