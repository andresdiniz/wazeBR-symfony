<?php

declare(strict_types=1);

namespace App\Scheduler\Message;

/**
 * Mensagem agendada para busca de dados hidrológicos CEMADEN.
 * Disparada a cada 10 minutos pelo WazeFeedSchedule.
 */
final class FetchCemadenHydroMessage
{
    public function __construct(
        /** Filtra por partner_slug quando não-null (útil para rodar manualmente). */
        public readonly ?string $partnerSlug = null,
    ) {}
}
