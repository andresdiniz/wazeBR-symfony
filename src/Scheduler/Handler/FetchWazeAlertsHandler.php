<?php

declare(strict_types=1);

namespace App\Scheduler\Handler;

use App\Repository\MonitoredLinkRepository;
use App\Scheduler\Message\FetchWazeAlertsMessage;
use App\Service\WazeFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Processa FetchWazeAlertsMessage:
 * itera todos os feeds Waze ativos (feedFormat=1) e persiste alertas/jams.
 *
 * Circuit breaker: se a primeira falha for por DNS ou conexão,
 * o ciclo é abortado imediatamente para não repetir o mesmo erro
 * para cada link quando a rede está totalmente indisponível.
 */
#[AsMessageHandler]
final class FetchWazeAlertsHandler
{
    /** Fragmentos de mensagem que indicam falha de infra (DNS / TCP). */
    private const NETWORK_ERROR_PATTERNS = [
        'Could not resolve host',
        'Recv failure',
        'Connection was reset',
        'Connection refused',
        'Connection timed out',
        'timed out',
        'SSL',
        'Network is unreachable',
    ];

    public function __construct(
        private readonly MonitoredLinkRepository $linkRepo,
        private readonly WazeFeedService         $feedService,
        private readonly EntityManagerInterface  $em,
        private readonly LoggerInterface         $logger,
    ) {}

    public function __invoke(FetchWazeAlertsMessage $message): void
    {
        $links = $this->linkRepo->findActiveWazeFeeds();

        if (empty($links)) {
            $this->logger->info('[WazeScheduler] Nenhum feed ativo encontrado.');
            return;
        }

        if ($message->partnerSlug !== null) {
            $links = array_filter(
                $links,
                fn($l) => $l->getPartner()?->getSlug() === $message->partnerSlug
            );
        }

        $totalSaved       = 0;
        $totalErrors      = 0;
        $networkAborted   = false;

        foreach ($links as $link) {
            $label   = $link->getLabel() ?? $link->getUrl();
            $partner = $link->getPartner()?->getSlug();

            try {
                $count = $this->feedService->fetchAndPersist($link);

                $link->setLastCollectedAt(new \DateTimeImmutable());
                $this->em->flush();

                $totalSaved += $count;

                $this->logger->info('[WazeScheduler] Feed coletado', [
                    'link'    => $label,
                    'partner' => $partner,
                    'saved'   => $count,
                ]);

            } catch (\Throwable $e) {
                $totalErrors++;
                $msg = $e->getMessage();

                $this->logger->error('[WazeScheduler] Erro ao coletar feed', [
                    'link'    => $label,
                    'partner' => $partner,
                    'error'   => $msg,
                ]);

                // Circuit breaker: falha de rede afeta todos os links — abort.
                if ($this->isNetworkError($msg)) {
                    $networkAborted = true;
                    $this->logger->warning(
                        '[WazeScheduler] Falha de rede detectada — ciclo abortado para evitar spam de erros.',
                        ['pattern' => $msg]
                    );
                    break;
                }
            }
        }

        $this->logger->info('[WazeScheduler] Ciclo concluído', [
            'total_saved'      => $totalSaved,
            'total_errors'     => $totalErrors,
            'network_aborted'  => $networkAborted,
        ]);
    }

    private function isNetworkError(string $message): bool
    {
        foreach (self::NETWORK_ERROR_PATTERNS as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}
