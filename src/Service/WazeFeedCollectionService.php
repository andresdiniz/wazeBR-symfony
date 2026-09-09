<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WazeFeed;
use App\Entity\WazeFeedCollection;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtRouteDefinition;
use App\Entity\WazeTvtRouteHistory;
use App\Repository\WazeFeedCollectionRepository;
use App\Repository\WazeFeedRepository;
use App\Repository\WazeTvtRouteDefinitionRepository;
use App\Repository\WazeTvtRouteHistoryRepository;
use App\Repository\WazeTvtRouteRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class WazeFeedCollectionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WazeFeedCollectionRepository $feedCollectionRepo,
        private readonly WazeFeedRepository $feedRepo,
        private readonly WazeTvtRouteRepository $tvtRouteRepo,
        private readonly WazeTvtRouteDefinitionRepository $tvtRouteDefRepo,
        private readonly WazeTvtRouteHistoryRepository $tvtRouteHistoryRepo,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function collect(WazeFeed $feed, bool $dryRun = false): array
    {
        $feedCollection = new WazeFeedCollection();
        $feedCollection->setWazeFeed($feed);
        $feedCollection->setStatus('processing');

        if (!$dryRun) {
            $this->em->persist($feedCollection);
            $this->em->flush();
        }

        try {
            $partner = $feed->getPartner();
            $feedUuid = $feed->getFeedUuid();
            $apiToken = $partner->getApiToken();
            $feedId = $feed->getFeedId();
            $url = $feed->getEndpointUrl() ?: sprintf(
                'https://www.waze.com/row-partnerhub-api/feeds-tvt/%s',
                $feedUuid
            );

            $options = [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'wazeBR-symfony/1.0',
                ],
                'timeout' => 30,
            ];

            if ($apiToken) {
                $options['headers']['Authorization'] = 'Bearer '.$apiToken;
            }

            if ($feedId && !str_contains($url, '?id=')) {
                $options['query'] = ['id' => (string) $feedId];
            }

            $data = $this->httpClient->request('GET', $url, $options)->toArray();
            $routes = $data['routes'] ?? [];
            $routesCount = 0;
            $definitionsCount = 0;
            $historyCount = 0;

            foreach ($routes as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }

                try {
                    $result = $this->processTvtItem($item, $partner, $feed, $feedCollection, (int) $index);
                    if ($result !== null) {
                        ++$routesCount;
                        $definitionsCount += $result['definitions'];
                        $historyCount += $result['history'];
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Erro ao processar item TVT', [
                        'index' => $index,
                        'route_id' => $item['id'] ?? null,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            if (!$dryRun) {
                $this->em->flush();
            }

            return [
                'routes' => $routesCount,
                'definitions' => $definitionsCount,
                'history' => $historyCount,
            ];
        } catch (ExceptionInterface $e) {
            $this->logger->error('Erro ao coletar feed Waze TVT', [
                'feed' => $feed->getFeedUuid(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function processTvtItem(array $item, $partner, WazeFeed $feed, WazeFeedCollection $feedCollection, int $index): ?array
    {
        if (!isset($item['id']) || !is_scalar($item['id'])) {
            return null;
        }

        $externalRouteId = (string) $item['id'];
        $name = isset($item['name']) ? (string) $item['name'] : null;
        $length = (int) ($item['length'] ?? 0);
        $time = (int) ($item['time'] ?? 0);
        $historicTime = (int) ($item['historicTime'] ?? 0);
        $jamLevel = (int) ($item['jamLevel'] ?? 0);
        $bbox = $item['bbox'] ?? null;
        $line = $item['line'] ?? null;

        // Upsert WazeTvtRoute
        $route = $this->tvtRouteRepo->findOneByExternalRouteId($externalRouteId);
        if (!$route) {
            $route = new WazeTvtRoute();
            $route->setPartner($partner);
            $route->setWazeFeed($feed);
            $route->setExternalRouteId($externalRouteId);
            $route->setLabel($name);
            $route->setIsActive(true);
            $route->setFirstSeenAt(new DateTime());
            $this->em->persist($route);
        }
        $route->setLabel($name);
        $route->setLastSeenAt(new DateTime());

        // Upsert WazeTvtRouteDefinition
        $definition = $this->tvtRouteDefRepo->findOneByRouteAndCurrent($route, true);
        if (!$definition) {
            $definition = new WazeTvtRouteDefinition();
            $definition->setWazeTvtRoute($route);
            $definition->setVersionNumber(1);
            $definition->setName($name);
            $definition->setGeometry(is_array($line) ? $line : $this->decodeJsonColumn($line));
            $definition->setMetadata(['bbox' => $bbox]);
            $definition->setIsCurrent(true);
            $route->setCurrentDefinition($definition);
            $this->em->persist($definition);
        } else {
            $definition->setName($name);
            $definition->setGeometry(is_array($line) ? $line : $this->decodeJsonColumn($line));
            $definition->setMetadata(['bbox' => $bbox]);
        }

        $speedKmh = $time > 0 ? round(($length / $time) * 3.6, 2) : null;
        $delaySeconds = $time - $historicTime;

        // Create new history entry
        $history = new WazeTvtRouteHistory();
        $history->setWazeTvtRoute($route);
        $history->setWazeTvtRouteDefinition($definition);
        $history->setWazeFeedCollection($feedCollection);
        $history->setObservedAt(new DateTime());
        $history->setTravelTimeSeconds($time);
        $speedMinutes = $speedKmh !== null ? round($time / 60, 2) : null;
        $history->setTravelTimeMinutes($speedMinutes === null ? null : (string) $speedMinutes);
        $history->setSpeedKmh($speedKmh === null ? null : (string) $speedKmh);
        $history->setDelaySeconds($delaySeconds > 0 ? $delaySeconds : null);
        $history->setLengthMeters($length);
        $history->setStatus($jamLevel > 3 ? 'congested' : ($jamLevel > 0 ? 'moderate' : 'free'));
        $history->setRawMetrics([
            'jamLevel' => $jamLevel,
            'historicTime' => $historicTime,
            'fromName' => $item['fromName'] ?? null,
            'toName' => $item['toName'] ?? null,
            'type' => $item['type'] ?? null,
        ]);
        $this->em->persist($history);

        return ['definitions' => 1, 'history' => 1];
    }

    private function encodeJsonColumn(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decodeJsonColumn(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getLastFeedCollection(WazeFeed $feed): ?WazeFeedCollection
    {
        return $this->feedCollectionRepo->findOneBy(['wazeFeed' => $feed], ['id' => 'DESC']);
    }

    public function success(WazeFeedCollection $fc): void
    {
        if ($this->em->isOpen()) {
            $fc->setStatus('success');
            $this->em->flush();
        }
    }

    public function fail(string $reason, WazeFeedCollection $fc): void
    {
        if ($this->em->isOpen()) {
            $fc->setStatus('error');
            $this->em->flush();
        }
    }
}
