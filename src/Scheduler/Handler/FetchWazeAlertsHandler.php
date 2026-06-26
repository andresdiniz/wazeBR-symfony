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
 */
#[AsMessageHandler]
final class FetchWazeAlertsHandler
{
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

        $totalSaved  = 0;
        $totalErrors = 0;

        foreach ($links as $link) {
            $label = $link->getLabel() ?? $link->getUrl();

            try {
                $count = $this->feedService->fetchAndPersist($link);

                $link->setLastCollectedAt(new \DateTimeImmutable());
                $this->em->flush();

                $totalSaved += $count;

                $this->logger->info('[WazeScheduler] Feed coletado', [
                    'link'    => $label,
                    'partner' => $link->getPartner()?->getSlug(),
                    'saved'   => $count,
                ]);

            } catch (\Throwable $e) {
                $totalErrors++;

                $this->logger->error('[WazeScheduler] Erro ao coletar feed', [
                    'link'    => $label,
                    'partner' => $link->getPartner()?->getSlug(),
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('[WazeScheduler] Ciclo conclu\u00eddo', [
            'total_saved'  => $totalSaved,
            'total_errors' => $totalErrors,
        ]);
    }
}
