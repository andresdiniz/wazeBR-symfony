<?php

declare(strict_types=1);

namespace App\Scheduler\Handler;

use App\Repository\MonitoredLinkRepository;
use App\Scheduler\Message\FetchWazeTrafficMessage;
use App\Service\WazeTrafficFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Processa FetchWazeTrafficMessage:
 * itera todos os feeds TVT ativos (feedFormat=2) e persiste snapshots.
 */
#[AsMessageHandler]
final class FetchWazeTrafficHandler
{
    public function __construct(
        private readonly MonitoredLinkRepository $linkRepo,
        private readonly WazeTrafficFeedService  $trafficService,
        private readonly EntityManagerInterface  $em,
        private readonly LoggerInterface         $logger,
    ) {}

    public function __invoke(FetchWazeTrafficMessage $message): void
    {
        $links = $this->linkRepo->findActiveTrafficFeeds();

        if (empty($links)) {
            $this->logger->info('[WazeTrafficScheduler] Nenhum feed TVT ativo.');
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
                // fetchAndPersist() retorna array{routes: int, irregularities: int}
                $result = $this->trafficService->fetchAndPersist($link);

                $link->setLastCollectedAt(new \DateTimeImmutable());
                $this->em->flush();

                $totalSaved += $result['routes'] ?? 0;

                $this->logger->info('[WazeTrafficScheduler] TVT coletado', [
                    'link'           => $label,
                    'partner'        => $link->getPartner()?->getSlug(),
                    'routes'         => $result['routes']         ?? 0,
                    'irregularities' => $result['irregularities'] ?? 0,
                ]);

            } catch (\Throwable $e) {
                $totalErrors++;

                $this->logger->error('[WazeTrafficScheduler] Erro TVT', [
                    'link'    => $label,
                    'partner' => $link->getPartner()?->getSlug(),
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('[WazeTrafficScheduler] Ciclo concluído', [
            'total_saved'  => $totalSaved,
            'total_errors' => $totalErrors,
        ]);
    }
}
