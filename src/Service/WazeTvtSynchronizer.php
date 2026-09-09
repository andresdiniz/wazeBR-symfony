<?php

namespace App\Service;

use App\Entity\WazeFeed;
use App\Entity\WazeFeedCollection;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtRouteDefinition;
use App\Entity\WazeTvtRouteHistory;
use App\Repository\WazeTvtRouteRepository;
use App\Repository\WazeTvtRouteDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;

class WazeTvtSynchronizer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WazeTvtRouteRepository $routeRepository,
        private readonly WazeTvtRouteDefinitionRepository $definitionRepository
    ) {}

    public function upsert(WazeFeed $feed, WazeFeedCollection $collection, array $data): WazeTvtRoute
    {
        $externalRouteId = (string)($data['id'] ?? $data['routeId'] ?? '');

        $route = $this->routeRepository->findOneByFeedAndExternalRouteId($feed->getId(), $externalRouteId);

        if (!$route) {
            $route = new WazeTvtRoute();
            $route->setPartner($feed->getPartner());
            $route->setWazeFeed($feed);
            $route->setExternalRouteId($externalRouteId);
            $route->setFirstSeenAt(new \DateTime());
            $this->em->persist($route);
        }

        $route->setLabel($data['name'] ?? $data['routeName'] ?? null);
        $route->setIsActive(true);
        $route->setLastSeenAt(new \DateTime());

        // Versionar definição se geometria mudou
        $definition = $this->upsertDefinition($route, $data);
        $route->setCurrentDefinition($definition);

        // Registrar métricas desta coleta
        $this->recordHistory($route, $definition, $collection, $data);

        $this->em->flush();

        return $route;
    }

    private function upsertDefinition(WazeTvtRoute $route, array $data): WazeTvtRouteDefinition
    {
        $geometry = $data['line'] ?? $data['geometry'] ?? [];
        $geometryHash = hash('sha256', json_encode($geometry));

        $definitionHash = hash('sha256', implode('|', [
            $data['name'] ?? '',
            $data['from'] ?? '',
            $data['to'] ?? '',
            $data['length'] ?? '',
            $geometryHash,
        ]));

        // Checar se já existe essa versão
        if ($route->getId()) {
            $existing = $this->definitionRepository->findOneByRouteAndHash(
                $route->getId(),
                $definitionHash
            );
            if ($existing) {
                return $existing;
            }

            // Fechar versão atual
            $current = $this->definitionRepository->findCurrentByRoute($route->getId());
            if ($current) {
                $current->setIsCurrent(false);
                $current->setValidUntil(new \DateTime());
            }
        }

        // Calcular próximo número de versão
        $versionNumber = $route->getId()
            ? $this->definitionRepository->getNextVersionNumber($route->getId())
            : 1;

        $definition = new WazeTvtRouteDefinition();
        $definition->setWazeTvtRoute($route);
        $definition->setVersionNumber($versionNumber);
        $definition->setDefinitionHash($definitionHash);
        $definition->setName($data['name'] ?? $data['routeName'] ?? null);
        $definition->setOriginName($data['from'] ?? null);
        $definition->setDestinationName($data['to'] ?? null);
        $definition->setDistanceMeters(isset($data['length']) ? (int)$data['length'] : null);
        $definition->setGeometry($geometry);
        $definition->setGeometryHash($geometryHash);
        $definition->setSegmentCount(isset($data['segments']) ? count($data['segments']) : null);
        $definition->setIsCurrent(true);
        $definition->setValidFrom(new \DateTime());

        $this->em->persist($definition);

        return $definition;
    }

    private function recordHistory(
        WazeTvtRoute $route,
        WazeTvtRouteDefinition $definition,
        WazeFeedCollection $collection,
        array $data
    ): void {
        $travelTimeSeconds = isset($data['time']) ? (int)$data['time'] : null;

        $history = new WazeTvtRouteHistory();
        $history->setWazeTvtRoute($route);
        $history->setWazeTvtRouteDefinition($definition);
        $history->setWazeFeedCollection($collection);
        $history->setObservedAt(new \DateTime());
        $history->setTravelTimeSeconds($travelTimeSeconds);
        $history->setTravelTimeMinutes(
            $travelTimeSeconds !== null
                ? (string)round($travelTimeSeconds / 60, 2)
                : null
        );
        $history->setDelaySeconds(isset($data['historicTime']) && $travelTimeSeconds !== null
            ? (int)($travelTimeSeconds - (int)$data['historicTime'])
            : null
        );
        $history->setLengthMeters(isset($data['length']) ? (int)$data['length'] : null);
        $history->setStatus($data['trafficState'] ?? null);
        $history->setRawMetrics($data);

        $this->em->persist($history);
    }
}
