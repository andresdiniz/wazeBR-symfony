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
 * Processa FetchWazeAlertsMessage: itera todos os feeds ativos e coleta alertas.
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

        // Filtra por parceiro se a mensagem especificar
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
                $count = $this->feedService->fetchAndPersist($link);
                $link->markSuccess($count);
                $this->em->flush();

                $totalSaved += $count;

                $this->logger->info('[WazeScheduler] Feed coletado', [
                    'link'    => $link->getName(),
                    'partner' => $link->getPartner()?->getSlug(),
                    'alerts'  => $count,
                ]);

            } catch (\Throwable $e) {
                $totalErrors++;
                $link->markError($e->getMessage());
                $this->em->flush();

                $this->logger->error('[WazeScheduler] Erro ao coletar feed', [
                    'link'      => $link->getName(),
                    'partner'   => $link->getPartner()?->getSlug(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('[WazeScheduler] Ciclo conclu\u00eddo', [
            'total_saved'  => $totalSaved,
            'total_errors' => $totalErrors,
        ]);
    }
}
