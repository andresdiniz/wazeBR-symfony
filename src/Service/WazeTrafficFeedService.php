<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MonitoredLink;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coleta o feed TVT do Waze e persiste como WazeTvtSnapshot + WazeTvtRoute.
 *
 * Estrutura real do JSON (verificada em 2026-06-25):
 * {
 *   "updateTime": 1782350463272,
 *   "name": "Managed Area",
 *   "areaName": "Managed Area",
 *   "broadcasterId": "",
 *   "isMetric": true,
 *   "bbox": {"minX": -43.82, "maxX": -43.74, "minY": -20.71, "maxY": -20.61},
 *   "usersOnJams": [{"jamLevel": 0, "wazersCount": 79}, ...],   // 5 níveis
 *   "lengthOfJams": [{"jamLevel": 1, "jamLength": 0}, ...],     // 4 níveis
 *   "irregularities": [],
 *   "routes": [
 *     {
 *       "id": "1734959326165",
 *       "name": "Cachoeira Centro",
 *       "type": "STATIC",
 *       "fromName": "R. Antônio...",
 *       "toName": "Av. Pref. ...",
 *       "length": 3152,
 *       "time": 522,
 *       "historicTime": 457,
 *       "jamLevel": 1,
 *       "line": [{"x": -43.79, "y": -20.64}, ...],
 *       "bbox": {"minX":..., ...},
 *       "subRoutes": []
 *     },
 *     ...
 *   ]
 * }
 */
class WazeTrafficFeedService
{
    public function __construct(
        private readonly HttpClientInterface    $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Busca e persiste um snapshot completo do feed TVT.
     *
     * @return int Número de rotas salvas (principais + subRoutes)
 */
    public function fetchAndPersist(MonitoredLink $link): int
    {
        $response = $this->httpClient->request('GET', $link->getUrl(), [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $data = $response->toArray();

        // Validação básica
        if (!isset($data['routes']) || !is_array($data['routes'])) {
            throw new \UnexpectedValueException(
                sprintf(
                    '[WazeTVT] Chave "routes" ausente no JSON. Chaves encontradas: %s',
                    implode(', ', array_keys($data))
                )
            );
        }

        $partner = $link->getPartner();

        // --- Criar snapshot ---
        $snapshot = new WazeTvtSnapshot();
        $snapshot
            ->setPartner($partner)
            ->setSourceLink($link)
            ->setUpdateTime(isset($data['updateTime']) ? (int) $data['updateTime'] : null)
            ->setFeedName($data['name'] ?? null)
            ->setAreaName($data['areaName'] ?? null)
            ->setBroadcasterId($data['broadcasterId'] ?? null)
            ->setIsMetric((bool) ($data['isMetric'] ?? true))
            ->setBbox($data['bbox'] ?? null)
            ->setUsersOnJams($data['usersOnJams'] ?? [])
            ->setLengthOfJams($data['lengthOfJams'] ?? [])
            ->setIrregularities($data['irregularities'] ?? []);

        // --- Normalizar rotas ---
        $routeCount = 0;

        foreach ($data['routes'] as $rawRoute) {
            $routeCount += $this->persistRoute($snapshot, $rawRoute, false, null);
        }

        $snapshot->setRouteCount($routeCount);

        $this->em->persist($snapshot);
        $this->em->flush();

        $this->logger->info('[WazeTVT] Snapshot salvo', [
            'link'       => $link->getName(),
            'partner'    => $partner->getSlug(),
            'routes'     => $routeCount,
            'updateTime' => $data['updateTime'] ?? null,
            'area'       => $data['areaName'] ?? null,
        ]);

        return $routeCount;
    }

    /**
     * Persiste uma rota (principal ou subRota) e suas subRoutes recursivamente.
     *
     * @return int Número de entidades WazeTvtRoute criadas
     */
    private function persistRoute(
        WazeTvtSnapshot $snapshot,
        array           $raw,
        bool            $isSubRoute,
        ?string         $parentWazeId
    ): int {
        $route = new WazeTvtRoute();
        $route
            ->setSnapshot($snapshot)
            ->setWazeRouteId(isset($raw['id']) ? (string) $raw['id'] : null)
            ->setIsSubRoute($isSubRoute)
            ->setParentWazeId($parentWazeId)
            ->setName($raw['name'] ?? null)
            ->setType($raw['type'] ?? null)
            ->setFromName($raw['fromName'] ?? null)
            ->setToName($raw['toName'] ?? null)
            ->setLength(isset($raw['length'])       ? (int)   $raw['length']       : null)
            ->setTime(isset($raw['time'])           ? (int)   $raw['time']         : null)
            ->setHistoricTime(isset($raw['historicTime']) ? (int) $raw['historicTime'] : null)
            ->setJamLevel(isset($raw['jamLevel'])   ? (int)   $raw['jamLevel']     : null)
            ->setLine($raw['line'] ?? [])
            ->setBbox($raw['bbox'] ?? null)
            ->setSubRoutesRaw($raw['subRoutes'] ?? []);

        $this->em->persist($route);
        $snapshot->addRoute($route);

        $count = 1;

        // Processar subRoutes recursivamente
        foreach ($raw['subRoutes'] ?? [] as $subRaw) {
            $count += $this->persistRoute(
                $snapshot,
                $subRaw,
                true,
                isset($raw['id']) ? (string) $raw['id'] : null
            );
        }

        return $count;
    }
}
