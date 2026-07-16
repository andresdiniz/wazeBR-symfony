<?php

declare(strict_types=1);

namespace App\Scheduler\Handler;

use App\Entity\ActivityLog;
use App\Repository\MonitoredLinkRepository;
use App\Scheduler\Message\FetchWazeTrafficMessage;
use App\Service\WazeFeedService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Processa FetchWazeTrafficMessage:
 * itera todos os feeds Waze ativos e persiste jams/irregularidades.
 *
 * - Respeita o refreshIntervalMinutes do parceiro.
 * - Grava ActivityLog(action=fetch_error) em caso de falha.
 * - Circuit breaker para falhas de rede.
 */
#[AsMessageHandler]
final class FetchWazeTrafficHandler
{
    private const DEFAULT_INTERVAL_MINUTES = 5;

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

    public function __invoke(FetchWazeTrafficMessage $message): void
    {
        $links = $this->linkRepo->findActiveWazeFeeds();

        if (empty($links)) {
            $this->logger->info('[WazeTrafficScheduler] Nenhum feed ativo encontrado.');
            return;
        }

        if ($message->partnerSlug !== null) {
            $links = array_filter(
                $links,
                fn($l) => $l->getPartner()?->getSlug() === $message->partnerSlug
            );
        }

        $totalSaved     = 0;
        $totalErrors    = 0;
        $totalSkipped   = 0;
        $networkAborted = false;

        foreach ($links as $link) {
            $label   = $link->getLabel() ?? $link->getUrl();
            $partner = $link->getPartner();

            // ── Respeita o intervalo do parceiro ──────────────────────────
            $intervalMin = $partner?->getRefreshIntervalMinutes() ?? self::DEFAULT_INTERVAL_MINUTES;
            if ($link->getLastCollectedAt() !== null) {
                $elapsedSec = (new \DateTimeImmutable())->getTimestamp() - $link->getLastCollectedAt()->getTimestamp();
                if ($elapsedSec < $intervalMin * 60) {
                    $totalSkipped++;
                    $this->logger->debug('[WazeTrafficScheduler] Coleta ignorada (dentro do intervalo)', [
                        'link'        => $label,
                        'partner'     => $partner?->getSlug(),
                        'interval'    => $intervalMin,
                        'elapsed_sec' => $elapsedSec,
                    ]);
                    continue;
                }
            }
            // ─────────────────────────────────────────────────────────────

            try {
                $count = $this->feedService->fetchAndPersist($link);

                $link->setLastCollectedAt(new \DateTimeImmutable());
                $this->em->flush();

                $totalSaved += $count;

                $this->logger->info('[WazeTrafficScheduler] Feed coletado', [
                    'link'    => $label,
                    'partner' => $partner?->getSlug(),
                    'saved'   => $count,
                ]);

            } catch (\Throwable $e) {
                $totalErrors++;
                $msg = $e->getMessage();

                $this->logger->error('[WazeTrafficScheduler] Erro ao coletar feed', [
                    'link'    => $label,
                    'partner' => $partner?->getSlug(),
                    'error'   => $msg,
                ]);

                // Grava no ActivityLog para visibilidade na área do parceiro
                if ($partner !== null) {
                    $log = (new ActivityLog())
                        ->setPartner($partner)
                        ->setAction('fetch_error')
                        ->setDescription('Erro ao coletar feed Waze Traffic: ' . mb_substr($msg, 0, 240))
                        ->setContext([
                            'link_id' => $link->getId(),
                            'url'     => $link->getUrl(),
                            'label'   => $label,
                            'error'   => $msg,
                        ]);
                    $this->em->persist($log);
                    $this->em->flush();
                }

                if ($this->isNetworkError($msg)) {
                    $networkAborted = true;
                    $this->logger->warning(
                        '[WazeTrafficScheduler] Falha de rede — ciclo abortado.',
                        ['pattern' => $msg]
                    );
                    break;
                }
            }
        }

        $this->logger->info('[WazeTrafficScheduler] Ciclo concluído', [
            'total_saved'     => $totalSaved,
            'total_errors'    => $totalErrors,
            'total_skipped'   => $totalSkipped,
            'network_aborted' => $networkAborted,
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
