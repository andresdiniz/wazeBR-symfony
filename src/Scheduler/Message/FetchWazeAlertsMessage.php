<?php

declare(strict_types=1);

namespace App\Scheduler\Message;

/**
 * Mensagem disparada pelo Scheduler a cada 2 minutos.
 * Pode carregar um partnerSlug opcional para restringir a coleta.
 */
final class FetchWazeAlertsMessage
{
    public function __construct(
        public readonly ?string $partnerSlug = null,
    ) {}
}
