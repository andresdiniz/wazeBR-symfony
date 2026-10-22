<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Avisa as páginas /routes/{id} abertas que há dado novo.
 *
 * Mesmo desenho do TvNotifier: o evento é "magro" (sem dados) e o navegador busca
 * /routes/{id}/live.json — que passa pela autenticação e pelo escopo de parceiro.
 *
 * Tópicos:  routes/{partnerId}  (usuários do parceiro)
 *           routes/global       (admins sem parceiro — recebem tudo)
 *
 * Chamado pelo TvNotifier, que já é acionado pelos sincronizadores Waze quando há mudança.
 * Falha do hub NUNCA derruba a coleta.
 */
final class RouteLiveNotifier
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function topicFor(?Partner $partner): string
    {
        return 'routes/' . ($partner?->getId() ?? 'global');
    }

    public function notify(?Partner $partner): void
    {
        $topics = [self::topicFor(null)];
        if ($partner !== null) {
            array_unshift($topics, self::topicFor($partner));
        }

        $data = (string) json_encode(['reason' => 'data_changed', 'at' => time()]);

        foreach ($topics as $topic) {
            try {
                $this->hub->publish(new Update(topics: $topic, data: $data, private: false));
            } catch (\Throwable $e) {
                $this->logger->warning('[RouteLiveNotifier] publish falhou: {msg}', [
                    'msg'   => $e->getMessage(),
                    'topic' => $topic,
                ]);
            }
        }
    }
}
