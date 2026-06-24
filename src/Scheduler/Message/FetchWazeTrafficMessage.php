<?php

declare(strict_types=1);

namespace App\Scheduler\Message;

/** Mensagem disparada pelo Scheduler para coletar tr\u00e1fego TVT a cada 2 minutos. */
final class FetchWazeTrafficMessage
{
    public function __construct(
        public readonly ?string $partnerSlug = null,
    ) {}
}
