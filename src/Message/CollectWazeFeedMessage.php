<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mensagem disparada pelo Scheduler a cada 5 minutos
 * para acionar a coleta de alertas e jams do feed PartnerHub Waze.
 */
final class CollectWazeFeedMessage
{
    public function __construct(
        /** Slug do parceiro — null significa todos os ativos */
        public readonly ?string $partnerSlug = null,
    ) {}
}
