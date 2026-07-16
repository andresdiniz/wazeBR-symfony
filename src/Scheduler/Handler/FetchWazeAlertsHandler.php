<?php

declare(strict_types=1);

namespace App\Scheduler\Handler;

use App\Entity\ActivityLog;
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
 * - Respeita o refreshIntervalMinutes do parceiro para não coletar antes do tempo.
 * - Grava ActivityLog(action=fetch_error) em caso de falha, para visibilidade na área do parceiro.
 * - Circuit breaker: se a primeira falha for por DNS ou conexão,
 *   o ciclo é abortado imediatamente para não repetir o mesmo erro
 *   para cada link quando a rede está totalmente indisponível.
 */
#[AsMessageHandler]
final class FetchWazeAlertsHandler
{
    /** Intervalo padrão do sistema em minutos (usado quando o partner não configurou um próprio). */
    private const DEFAULT_INTERVAL_MINUTES = 5;

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

        $totalSaved      = 0;
        $totalErrors     = 0;
        $totalSkipped    = 0;
        $networkAborted  = false;

        foreach ($links as $link) {
            $label   = $link->getLabel() ?? $link->getUrl();
            $partner = $link->getPartner();

            // ── Respeita o intervalo do parceiro ──────────────────────────
            $intervalMin = $partner?->getRefreshIntervalMinutes() ?? self::DEFAULT_INTERVAL_MINUTES;
            if ($link->getLastCollectedAt() !== null) {
                $elapsedSec = (new \DateTimeImmutable())->getTimestamp() - $link->getLastCollectedAt()->getTimestamp();
                if ($elapsedSec < $intervalMin * 60) {
                    $totalSkipped++;
                    $this->logger->debug('[WazeScheduler] Coleta ignorada (dentro do intervalo)', [
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

                $this->logger->info('[WazeScheduler] Feed coletado', [
                    'link'    => $label,
                    'partner' => $partner?->getSlug(),
                    'saved'   => $count,
                ]);

            } catch (\Throwable $e) {
                $totalErrors++;
                $msg = $e->getMessage();

                $this->logger->error('[WazeScheduler] Erro ao coletar feed', [
                    'link'    => $label,
                    'partner' => $partner?->getSlug(),
                    'error'   => $msg,
                ]);

                // Grava no ActivityLog para visibilidade na área do parceiro
                if ($partner !== null) {
                    $log = (new ActivityLog())
                        ->setPartner($partner)
                        ->setAction('fetch_error')
                        ->setDescription('Erro ao coletar feed Waze Alerts: ' . mb_substr($msg, 0, 240))
                        ->setContext([
                            'link_id' => $link->getId(),
                            'url'     => $link->getUrl(),
                            'label'   => $label,
                            'error'   => $msg,
                        ]);
                    $this->em->persist($log);
                    $this->em->flush();
                }

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
