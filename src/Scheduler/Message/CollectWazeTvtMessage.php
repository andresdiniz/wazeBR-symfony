<?php

declare(strict_types=1);

namespace App\Scheduler\Message;

/**
 * Mensagem que dispara a coleta dos feeds TVT (feedFormat=2).
 * Equivalente a: php bin/console app:waze:collect-tvt [--partner=slug]
 */
final class CollectWazeTvtMessage
{
    public function __construct(
        public readonly ?string $partnerSlug = null,
    ) {}
}
