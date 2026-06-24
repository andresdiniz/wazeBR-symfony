<?php

declare(strict_types=1);

namespace App\Scheduler\Handler;

use App\Repository\MonitoredLinkRepository;
use App\Scheduler\Message\FetchWazeTrafficMessage;
use App\Service\WazeTrafficFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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
            try {
                $count = $this->trafficService->fetchAndPersist($link);
                $link->markSuccess($count);
                $this->em->flush();
                $totalSaved += $count;

                $this->logger->info('[WazeTrafficScheduler] TVT coletado', [
                    'link'    => $link->getName(),
                    'partner' => $link->getPartner()?->getSlug(),
                    'jams'    => $count,
                ]);

            } catch (\Throwable $e) {
                $totalErrors++;
                $link->markError($e->getMessage());
                $this->em->flush();

                $this->logger->error('[WazeTrafficScheduler] Erro TVT', [
                    'link'  => $link->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('[WazeTrafficScheduler] Ciclo conclu\u00eddo', [
            'total_saved'  => $totalSaved,
            'total_errors' => $totalErrors,
        ]);
    }
}
