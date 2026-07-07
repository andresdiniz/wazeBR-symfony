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
 *   - WazeSubRoute     → recria a cada coleta (bulk DELETE + re-insert)
 *   - WazeIrregularity → upsert por (wazeId, sourceLink) — ativa/inativa
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

    /** @return array{routes: int, irregularities: int} */
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

        foreach ($data['routes'] as $raw) {
            $this->upsertRoute($link, $raw, $now);
            ++$routeCount;
        }

        $this->deactivateIrregularities($link);

        foreach ($data['irregularities'] ?? [] as $raw) {
            $this->upsertIrregularity($link, $raw, $now);
            ++$irregularityCount;
        }

        $this->em->flush();

        $elapsed = round(microtime(true) - $start, 2);
        $this->logger->info('[Traffic] Feed processado', [
            'link'           => $link->getLabel() ?? $link->getUrl(),
            'partner'        => $partner->getSlug(),
            'routes'         => $routeCount,
            'irregularities' => $irregularityCount,
            'elapsed_s'      => $elapsed,
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

        $length       = $this->toNum($raw['length']       ?? 0);
        $time         = $this->toNum($raw['time']         ?? 0);
        $historicTime = $this->toNum($raw['historicTime'] ?? 0);

        [$avgSpeed, $timeInt, $historicSpeed] = $this->calcSpeeds($length, $time, $historicTime);

        $route
            ->setName($raw['name']         ?? null)
            ->setFromName($raw['fromName'] ?? null)
            ->setToName($raw['toName']     ?? null)
            ->setLength((int) $length)
            ->setJamLevel((int) $this->toNum($raw['jamLevel'] ?? 0))
            ->setTime((int) $time)
            ->setHistoricTime((int) $historicTime)
            ->setType($raw['type']         ?? null)
            ->setBbox(is_array($raw['bbox'] ?? null) ? $raw['bbox'] : null)
            ->setLine(is_array($raw['line'] ?? null) ? $raw['line'] : null)
            ->setIsActive(true)
            ->setCollectedAt(\DateTime::createFromImmutable($now));

        $this->em->persist($route);

        $this->insertRouteSnapshot($route, $avgSpeed, $timeInt, $historicSpeed, $raw, $now);

        // fix: DELETE em bulk via DQL ao invés de um DELETE por subrota (N+1)
        if ($route->getId() !== null) {
            $this->em->createQuery(
                'DELETE App\Entity\WazeSubRoute s WHERE s.route = :route'
            )->setParameter('route', $route)->execute();
            $this->em->clear(WazeSubRoute::class);
        }

        foreach ($raw['subRoutes'] ?? [] as $i => $subRaw) {
            $this->persistSubRoute($route, $subRaw, $i);
        }
    }

    private function insertRouteSnapshot(
        WazeRoute $route,
        float $avgSpeed,
        int $time,
        float $historicSpeed,
        array $raw,
        \DateTimeImmutable $now
    ): void {
        $snapshot = new WazeRouteSnapshot();
        $snapshot
            ->setRoute($route)
            ->setTime($time)
            ->setHistoricTime((int) $this->toNum($raw['historicTime'] ?? 0))
            ->setLength((int) $this->toNum($raw['length'] ?? 0))
            ->setJamLevel((int) $this->toNum($raw['jamLevel'] ?? 0))
            ->setAvgSpeed($avgSpeed)
            ->setHistoricSpeed($historicSpeed)
            ->setCollectedAt($now);

        $this->em->persist($snapshot);
    }

    private function persistSubRoute(WazeRoute $route, array $raw, int $order): void
    {
        $length       = $this->toNum($raw['length']       ?? 0);
        $time         = $this->toNum($raw['time']         ?? 0);
        $historicTime = $this->toNum($raw['historicTime'] ?? 0);

        [$avgSpeed, , $historicSpeed] = $this->calcSpeeds($length, $time, $historicTime);

        $leadAlert = is_array($raw['leadAlert'] ?? null) ? $raw['leadAlert'] : null;

        $sub = new WazeSubRoute();
        $sub
            ->setRoute($route)
            ->setFromName($raw['fromName']  ?? null)
            ->setToName($raw['toName']      ?? null)
            ->setTime((int) $time)
            ->setHistoricTime((int) $historicTime)
            ->setLength((int) $length)
            ->setJamLevel((int) $this->toNum($raw['jamLevel'] ?? 0))
            ->setAvgSpeed($avgSpeed)
            ->setHistoricSpeed($historicSpeed)
            ->setLine(is_array($raw['line'] ?? null) ? $raw['line'] : null)
            ->setBbox(is_array($raw['bbox'] ?? null) ? $raw['bbox'] : null)
            ->setSortOrder($order)
            ->setLeadAlertId($leadAlert['id']         ?? null)
            ->setLeadAlertType($leadAlert['type']     ?? null)
            ->setLeadAlertSubType($leadAlert['subType'] ?? null)
            ->setLeadAlertPosition(isset($leadAlert['position']) ? (array) $leadAlert['position'] : null)
            ->setLeadAlertNumComments((int) $this->toNum($leadAlert['numComments'] ?? 0))
            ->setLeadAlertNumThumbsUp((int) $this->toNum($leadAlert['numThumbsUp'] ?? 0))
            ->setLeadAlertNumNotThereReports((int) $this->toNum($leadAlert['numNotThereReports'] ?? 0))
            ->setLeadAlertStreet($leadAlert['street'] ?? null);

        $this->em->persist($sub);
    }

    // ─────────────────────────────────────────────────────────────────
    // Irregularidades
    // ─────────────────────────────────────────────────────────────────

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

        $length       = $this->toNum($raw['length']       ?? 0);
        $time         = $this->toNum($raw['time']         ?? 0);
        $historicTime = $this->toNum($raw['historicTime'] ?? 0);

        [$avgSpeed, , $historicSpeed] = $this->calcSpeeds($length, $time, $historicTime);

        $leadAlert = is_array($raw['leadAlert'] ?? null) ? $raw['leadAlert'] : null;

        $irr
            ->setName($raw['name']         ?? null)
            ->setFromName($raw['fromName'] ?? null)
            ->setToName($raw['toName']     ?? null)
            ->setLength((int) $length)
            ->setJamLevel((int) $this->toNum($raw['jamLevel'] ?? 0))
            ->setTime((int) $time)
            ->setHistoricTime((int) $historicTime)
            ->setAvgSpeed($avgSpeed)
            ->setHistoricSpeed($historicSpeed)
            ->setBbox(is_array($raw['bbox'] ?? null) ? $raw['bbox'] : null)
            ->setLine(is_array($raw['line'] ?? null) ? $raw['line'] : null)
            ->setIsActive(true)
            ->setCollectedAt($now)
            ->setLeadAlertId($leadAlert['id']         ?? null)
            ->setLeadAlertType($leadAlert['type']     ?? null)
            ->setLeadAlertSubType($leadAlert['subType'] ?? null)
            ->setLeadAlertPosition(isset($leadAlert['position']) ? (array) $leadAlert['position'] : null)
            ->setLeadAlertNumComments((int) $this->toNum($leadAlert['numComments'] ?? 0))
            ->setLeadAlertCity($leadAlert['city']     ?? null)
            ->setLeadAlertExternalImageId($leadAlert['externalImageId'] ?? null)
            ->setLeadAlertNumThumbsUp((int) $this->toNum($leadAlert['numThumbsUp'] ?? 0))
            ->setLeadAlertStreet($leadAlert['street'] ?? null)
            ->setLeadAlertNumNotThereReports((int) $this->toNum($leadAlert['numNotThereReports'] ?? 0));

        $this->em->persist($irr);
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Converte qualquer valor do payload (array, string, null, int, float) para float seguro.
     * Arrays retornam 0 para evitar o fatal "Unsupported operand types: int + array".
     */
    private function toNum(mixed $v): float
    {
        if (is_array($v) || is_null($v)) {
            return 0.0;
        }

        return (float) $v;
    }

    /**
     * @return array{float, int, float}  [avgSpeed km/h, time s, historicSpeed km/h]
     */
    private function calcSpeeds(float $length, float $time, float $historicTime): array
    {
        $length       = max(1.0, $length);
        $time         = max(1.0, $time);
        $historicTime = max(1.0, $historicTime);

        $avgSpeed      = ($length / 1000.0) / ($time / 3600.0);
        $historicSpeed = ($length / 1000.0) / ($historicTime / 3600.0);

        return [$avgSpeed, (int) $time, $historicSpeed];
    }
}
