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
 *
 * Circuit breaker: se a primeira falha for por DNS ou conexão,
 * o ciclo é abortado imediatamente para não repetir o mesmo erro
 * para cada link quando a rede está totalmente indisponível.
 */
#[AsMessageHandler]
final class FetchWazeTrafficHandler
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

        $totalSaved      = 0;
        $totalErrors     = 0;
        $networkAborted  = false;

        foreach ($links as $link) {
            $label   = $link->getLabel() ?? $link->getUrl();
            $partner = $link->getPartner()?->getSlug();

            try {
                // fetchAndPersist() retorna array{routes: int, irregularities: int}
                $result = $this->trafficService->fetchAndPersist($link);

                $link->setLastCollectedAt(new \DateTimeImmutable());
                $this->em->flush();

                $totalSaved += $result['routes'] ?? 0;

                $this->logger->info('[WazeTrafficScheduler] TVT coletado', [
                    'link'           => $label,
                    'partner'        => $partner,
                    'routes'         => $result['routes']         ?? 0,
                    'irregularities' => $result['irregularities'] ?? 0,
                ]);

            } catch (\Throwable $e) {
                $totalErrors++;
                $msg = $e->getMessage();

                $this->logger->error('[WazeTrafficScheduler] Erro TVT', [
                    'link'    => $label,
                    'partner' => $partner,
                    'error'   => $msg,
                ]);

                // Circuit breaker: falha de rede afeta todos os links — abort.
                if ($this->isNetworkError($msg)) {
                    $networkAborted = true;
                    $this->logger->warning(
                        '[WazeTrafficScheduler] Falha de rede detectada — ciclo TVT abortado.',
                        ['pattern' => $msg]
                    );
                    break;
                }
            }
        }

        $this->logger->info('[WazeTrafficScheduler] Ciclo concluído', [
            'total_saved'     => $totalSaved,
            'total_errors'    => $totalErrors,
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
