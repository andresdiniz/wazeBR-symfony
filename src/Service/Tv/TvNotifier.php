<?php

declare(strict_types=1);

namespace App\Service\Tv;

use App\Entity\Partner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Notifica as TVs (wallboards) que os dados de um parceiro mudaram.
 *
 * Fluxo:
 *   1. Invalida o cache tag-aware do wallboard (tv_partner_{id} ou tv_global).
 *   2. Publica um evento "magro" no Mercure no tópico tv/{id}.
 *
 * O browser NÃO recebe o payload completo por SSE — apenas o aviso.
 * Ao receber o evento, faz um fetch em /tv/api/data, que já estará
 * fresco (cache invalidado) e é compartilhado entre todas as TVs.
 */
final class TvNotifier
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly TagAwareCacheInterface $tvCache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Notifica as TVs de um parceiro. Se $partner for null, notifica
     * a TV "global" (admin sem partner).
     */
    public function notify(?Partner $partner): void
    {
        $partnerId = $partner?->getId();
        $topic     = 'tv/' . ($partnerId ?? 'global');

        // 1. Invalida o cache do wallboard — o próximo /tv/api/data
        //    refaz as queries e devolve dados frescos.
        try {
            if ($partnerId !== null) {
                $this->tvCache->invalidateTags(['tv_partner_' . $partnerId]);
            } else {
                $this->tvCache->invalidateTags(['tv_global']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[TvNotifier] invalidateTags falhou: {msg}', [
                'msg' => $e->getMessage(),
            ]);
        }

        // 2. Publica o aviso. Payload mínimo — o cliente busca o resto.
        try {
            $this->hub->publish(new Update(
                topics: $topic,
                data: (string) json_encode([
                    'reason' => 'data_changed',
                    'at'     => time(),
                ]),
                private: false,
            ));
        } catch (\Throwable $e) {
            // Nunca deixe uma falha do hub quebrar o pipeline de coleta.
            $this->logger->warning('[TvNotifier] publish falhou: {msg}', [
                'msg'   => $e->getMessage(),
                'topic' => $topic,
            ]);
        }
    }

    /**
     * Notifica vários partners de uma vez + a TV global.
     * Útil em comandos que processam todos os parceiros.
     *
     * @param iterable<Partner|null> $partners
     */
    public function notifyMany(iterable $partners): void
    {
        $seen = [];

        foreach ($partners as $partner) {
            if (!$partner instanceof Partner) {
                continue;
            }
            $id = $partner->getId();
            if ($id === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $this->notify($partner);
        }

        // TV global (admin sem partner) sempre escuta tudo
        $this->notify(null);
    }
}
