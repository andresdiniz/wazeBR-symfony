<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MonitoredLink;
use App\Entity\WazeIrregularity;
use App\Entity\WazeRoute;
use App\Entity\WazeRouteSnapshot;
use App\Entity\WazeSubRoute;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coleta o feed de tráfego do Waze (PartnerHub) e persiste:
 *   - WazeRoute        → upsert por (wazeId, partner)  — rota "viva"
 *   - WazeRouteSnapshot → insert sempre                  — histórico
 *   - WazeSubRoute     → recria a cada coleta (orphanRemoval)
 *   - WazeIrregularity → upsert por (wazeId, sourceLink) — ativa/inativa
 *
 * Adaptado do wazejobtraficc.php para Symfony + Doctrine.
 */
class WazeTrafficFeedService
{
    public function __construct(
        private readonly HttpClientInterface    $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    // ─────────────────────────────────────────────────────────────────
    // Ponto de entrada
    // ─────────────────────────────────────────────────────────────────

    /**
     * Busca o feed e persiste/atualiza rotas, snapshots históricos e irregularidades.
     *
     * @return array{routes: int, irregularities: int}
     */
    public function fetchAndPersist(MonitoredLink $link): array
    {
        $start    = microtime(true);
        $partner  = $link->getPartner();
        $response = $this->httpClient->request('GET', $link->getUrl(), [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $data = $response->toArray();

        if (!isset($data['routes']) || !is_array($data['routes'])) {
            throw new \UnexpectedValueException(
                sprintf('[Traffic] Chave "routes" ausente. Chaves: %s', implode(', ', array_keys($data)))
            );
        }

        $routeCount        = 0;
        $irregularityCount = 0;
        $now               = new \DateTimeImmutable();

        // ── Rotas ────────────────────────────────────────────────────
        foreach ($data['routes'] as $raw) {
            $this->upsertRoute($link, $raw, $now);
            ++$routeCount;
        }

        // ── Irregularidades ──────────────────────────────────────────
        // Desativa todas as irregularidades do link antes de processar
        $this->deactivateIrregularities($link);

        foreach ($data['irregularities'] ?? [] as $raw) {
            $this->upsertIrregularity($link, $raw, $now);
            ++$irregularityCount;
        }

        $this->em->flush();

        $elapsed = round(microtime(true) - $start, 2);
        $this->logger->info('[Traffic] Feed processado', [
            'link'            => $link->getLabel() ?? $link->getUrl(),
            'partner'         => $partner->getSlug(),
            'routes'          => $routeCount,
            'irregularities'  => $irregularityCount,
            'elapsed_s'       => $elapsed,
        ]);

        return ['routes' => $routeCount, 'irregularities' => $irregularityCount];
    }

    // ─────────────────────────────────────────────────────────────────
    // Rotas
    // ─────────────────────────────────────────────────────────────────

    private function upsertRoute(MonitoredLink $link, array $raw, \DateTimeImmutable $now): void
    {
        $partner = $link->getPartner();
        $wazeId  = isset($raw['id']) ? (string) $raw['id'] : null;

        // Tentar reutilizar rota existente (upsert)
        $route = null;
        if ($wazeId !== null) {
            $route = $this->em->getRepository(WazeRoute::class)->findOneBy([
                'wazeId'  => $wazeId,
                'partner' => $partner,
            ]);
        }

        if ($route === null) {
            $route = new WazeRoute();
            $route->setPartner($partner);
            $route->setWazeId($wazeId);
        }

        [$avgSpeed, $avgTime, $historicSpeed] = $this->calcSpeeds(
            $raw['length']      ?? 0,
            $raw['time']        ?? 0,
            $raw['historicTime'] ?? 0
        );

        $route
            ->setName($raw['name']         ?? null)
            ->setFromName($raw['fromName'] ?? null)
            ->setToName($raw['toName']     ?? null)
            ->setLength((int) ($raw['length'] ?? 0))
            ->setJamLevel((int) ($raw['jamLevel'] ?? 0))
            ->setTime((int) ($raw['time'] ?? 0))
            ->setHistoricTime((int) ($raw['historicTime'] ?? 0))
            ->setType($raw['type']         ?? null)
            ->setBbox($raw['bbox']         ?? null)
            ->setLine($raw['line']         ?? null)
            ->setIsActive(true)
            ->setCollectedAt(\DateTime::createFromImmutable($now));

        $this->em->persist($route);

        // Snapshot histórico (sempre insert)
        $this->insertRouteSnapshot($route, $avgSpeed, $avgTime, $now);

        // Sub-rotas: apagar antigas e recriar
        foreach ($route->getSubRoutes() as $old) {
            $route->removeSubRoute($old);
        }

        foreach ($raw['subRoutes'] ?? [] as $i => $subRaw) {
            $this->persistSubRoute($route, $subRaw, $i);
        }
    }

    private function insertRouteSnapshot(WazeRoute $route, float $avgSpeed, float $avgTime, \DateTimeImmutable $now): void
    {
        $snapshot = new WazeRouteSnapshot();
        $snapshot
            ->setRoute($route)
            ->setAvgSpeed($avgSpeed)
            ->setAvgTime((int) $avgTime)
            ->setCollectedAt($now);

        $this->em->persist($snapshot);
    }

    private function persistSubRoute(WazeRoute $route, array $raw, int $order): void
    {
        [$avgSpeed, , $historicSpeed] = $this->calcSpeeds(
            $raw['length']       ?? 0,
            $raw['time']         ?? 0,
            $raw['historicTime'] ?? 0
        );

        $leadAlert = $raw['leadAlert'] ?? null;

        $sub = new WazeSubRoute();
        $sub
            ->setRoute($route)
            ->setFromName($raw['fromName']       ?? null)
            ->setToName($raw['toName']           ?? null)
            ->setTime((int) ($raw['time']        ?? 0))
            ->setHistoricTime((int) ($raw['historicTime'] ?? 0))
            ->setLength((int) ($raw['length']    ?? 0))
            ->setJamLevel((int) ($raw['jamLevel'] ?? 0))
            ->setAvgSpeed($avgSpeed)
            ->setHistoricSpeed($historicSpeed)
            ->setLine($raw['line']               ?? null)
            ->setBbox($raw['bbox']               ?? null)
            ->setSortOrder($order)
            ->setLeadAlertId($leadAlert['id']       ?? null)
            ->setLeadAlertType($leadAlert['type']   ?? null)
            ->setLeadAlertSubType($leadAlert['subType'] ?? null)
            ->setLeadAlertPosition(isset($leadAlert['position']) ? (array) $leadAlert['position'] : null)
            ->setLeadAlertNumComments((int) ($leadAlert['numComments'] ?? 0))
            ->setLeadAlertNumThumbsUp((int) ($leadAlert['numThumbsUp'] ?? 0))
            ->setLeadAlertNumNotThereReports((int) ($leadAlert['numNotThereReports'] ?? 0))
            ->setLeadAlertStreet($leadAlert['street'] ?? null);

        $route->addSubRoute($sub);
        $this->em->persist($sub);
    }

    // ─────────────────────────────────────────────────────────────────
    // Irregularidades
    // ─────────────────────────────────────────────────────────────────

    /**
     * Marca todas as irregularidades deste link como inativas.
     * As que ainda estão no feed serão reativadas no upsert.
     */
    private function deactivateIrregularities(MonitoredLink $link): void
    {
        $this->em->createQuery(
            'UPDATE App\Entity\WazeIrregularity i SET i.isActive = false WHERE i.sourceLink = :link'
        )->setParameter('link', $link)->execute();
    }

    private function upsertIrregularity(MonitoredLink $link, array $raw, \DateTimeImmutable $now): void
    {
        $partner = $link->getPartner();
        $wazeId  = isset($raw['id']) ? (string) $raw['id'] : null;

        $irr = null;
        if ($wazeId !== null) {
            $irr = $this->em->getRepository(WazeIrregularity::class)->findOneBy([
                'wazeId'     => $wazeId,
                'sourceLink' => $link,
            ]);
        }

        if ($irr === null) {
            $irr = new WazeIrregularity();
            $irr->setWazeId($wazeId);
            $irr->setPartner($partner);
            $irr->setSourceLink($link);
        }

        [$avgSpeed, $avgTime, $historicSpeed] = $this->calcSpeeds(
            $raw['length']       ?? 0,
            $raw['time']         ?? 0,
            $raw['historicTime'] ?? 0
        );

        $leadAlert = $raw['leadAlert'] ?? null;

        $irr
            ->setName($raw['name']         ?? null)
            ->setFromName($raw['fromName'] ?? null)
            ->setToName($raw['toName']     ?? null)
            ->setLength((int) ($raw['length'] ?? 0))
            ->setJamLevel((int) ($raw['jamLevel'] ?? 0))
            ->setTime((int) ($raw['time'] ?? 0))
            ->setHistoricTime((int) ($raw['historicTime'] ?? 0))
            ->setAvgSpeed($avgSpeed)
            ->setHistoricSpeed($historicSpeed)
            ->setBbox($raw['bbox']         ?? null)
            ->setLine($raw['line']         ?? null)
            ->setIsActive(true)
            ->setCollectedAt($now)
            ->setLeadAlertId($leadAlert['id']         ?? null)
            ->setLeadAlertType($leadAlert['type']     ?? null)
            ->setLeadAlertSubType($leadAlert['subType'] ?? null)
            ->setLeadAlertPosition(isset($leadAlert['position']) ? (array) $leadAlert['position'] : null)
            ->setLeadAlertNumComments((int) ($leadAlert['numComments'] ?? 0))
            ->setLeadAlertCity($leadAlert['city']     ?? null)
            ->setLeadAlertExternalImageId($leadAlert['externalImageId'] ?? null)
            ->setLeadAlertNumThumbsUp((int) ($leadAlert['numThumbsUp'] ?? 0))
            ->setLeadAlertStreet($leadAlert['street'] ?? null)
            ->setLeadAlertNumNotThereReports((int) ($leadAlert['numNotThereReports'] ?? 0));

        $this->em->persist($irr);
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Calcula velocidades médias a partir de comprimento e tempos.
     *
     * @return array{float, float, float}  [avgSpeed, avgTime, historicSpeed]  (km/h, s, km/h)
     */
    private function calcSpeeds(int|float $length, int|float $time, int|float $historicTime): array
    {
        $length      = max(1, (float) $length);
        $time        = max(1, (float) $time);
        $historicTime = max(1, (float) $historicTime);

        $avgSpeed      = ($length / 1000) / ($time / 3600);         // km/h
        $historicSpeed = ($length / 1000) / ($historicTime / 3600); // km/h

        return [$avgSpeed, $time, $historicSpeed];
    }
}
