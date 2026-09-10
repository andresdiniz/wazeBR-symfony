<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WazeFeed;
use App\Entity\WazeFeedCollection;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtRouteDefinition;
use App\Entity\WazeTvtRouteHistory;
use App\Entity\WazeAlert;
use App\Entity\WazeTrafficJam;
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
            $feedType = $feed->getType();
            $endpointUrl = $feed->getEndpointUrl();
            
            if ($feedType === null || $feedType === '') {
                $feedType = str_contains($endpointUrl, 'feeds-tvt') ? 'TVT' : 'EVENTS';
            }
            
            if ($feedType === 'TVT') {
                return $this->collectTvt($feed, $feedCollection, $dryRun);
            } else {
                return $this->collectEvents($feed, $feedCollection, $dryRun);
            }
        } catch (ExceptionInterface $e) {
            $this->logger->error('Erro ao coletar feed Waze', [
                'feed' => $feed->getFeedUuid(),
                'type' => $feed->getType(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function collectTvt(WazeFeed $feed, WazeFeedCollection $feedCollection, bool $dryRun): array
    {
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
    }

    private function collectEvents(WazeFeed $feed, WazeFeedCollection $feedCollection, bool $dryRun): array
    {
        $partner = $feed->getPartner();
        $apiToken = $partner->getApiToken();
        $url = $feed->getEndpointUrl();

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

        $data = $this->httpClient->request('GET', $url, $options)->toArray();
        $alerts = $data['alerts'] ?? [];
        $jams = $data['jams'] ?? [];
        $alertsCount = 0;
        $jamsCount = 0;

        foreach ($alerts as $index => $alertData) {
            if (!is_array($alertData)) {
                continue;
            }
            try {
                $this->processAlert($alertData, $partner, $feed, $feedCollection);
                ++$alertsCount;
            } catch (\Throwable $e) {
                $this->logger->error('Erro ao processar alerta', [
                    'index' => $index,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        foreach ($jams as $index => $jamData) {
            if (!is_array($jamData)) {
                continue;
            }
            try {
                $this->processJam($jamData, $partner, $feed, $feedCollection);
                ++$jamsCount;
            } catch (\Throwable $e) {
                $this->logger->error('Erro ao processar jam', [
                    'index' => $index,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return [
            'alerts' => $alertsCount,
            'jams' => $jamsCount,
            'routes' => 0,
        ];
    }

    private function processAlert(array $data, $partner, WazeFeed $feed, WazeFeedCollection $collection): void
    {
        $externalUuid = $data['uuid'] ?? null;
        
        if ($externalUuid) {
            $existing = $this->em->getRepository(WazeAlert::class)->findOneBy([
                'partner' => $partner,
                'wazeFeed' => $feed,
                'externalUuid' => $externalUuid,
            ]);
            
            if ($existing) {
                $existing->setLastSeenAt(new DateTime());
                $existing->setIsActive(true);
                $existing->setMissingSinceAt(null);
                $this->em->persist($existing);
                return;
            }
        }

        $alert = new WazeAlert();
        $alert->setPartner($partner);
        $alert->setWazeFeed($feed);
        $alert->setExternalUuid($externalUuid);
        $alert->setType($data['type'] ?? '');
        $alert->setSubtype($data['subtype'] ?? null);
        $alert->setLatitude((float) ($data['location']['y'] ?? 0));
        $alert->setLongitude((float) ($data['location']['x'] ?? 0));
        $alert->setStreet($data['street'] ?? null);
        $alert->setCity($data['city'] ?? null);
        $alert->setCountry($data['country'] ?? null);
        $alert->setRoadType($data['roadType'] ?? null);
        $alert->setDescription($data['description'] ?? null);
        $alert->setConfidence($data['confidence'] ?? null);
        $alert->setReliability($data['reliability'] ?? null);
        $alert->setReportRating($data['reportRating'] ?? null);
        $alert->setThumbsUp($data['thumbsUp'] ?? null);
        $alert->setMagvar($data['magvar'] ?? null);
        
        if (isset($data['pubMillis'])) {
            $alert->setReportedAt((new DateTime())->setTimestamp((int) $data['pubMillis'] / 1000));
        }
        
        $alert->setFirstSeenAt(new DateTime());
        $alert->setLastSeenAt(new DateTime());
        $alert->setIsActive(true);
        $alert->setRawPayload($data);

        $this->em->persist($alert);
    }

    private function processJam(array $data, $partner, WazeFeed $feed, WazeFeedCollection $collection): void
    {
        $externalId = $data['id'] ?? null;
        
        if ($externalId) {
            $existing = $this->em->getRepository(WazeTrafficJam::class)->findOneBy([
                'partner' => $partner,
                'wazeFeed' => $feed,
                'externalId' => $externalId,
            ]);
            
            if ($existing) {
                $existing->setLastSeenAt(new DateTime());
                $existing->setIsActive(true);
                $this->em->persist($existing);
                return;
            }
        }

        $jam = new WazeTrafficJam();
        $jam->setPartner($partner);
        $jam->setWazeFeed($feed);
        $jam->setExternalId($externalId);
        $jam->setLengthMeters($data['length'] ?? null);
        $jam->setSpeedKmh(isset($data['speedKMH']) ? (string) round((float) $data['speedKMH'], 2) : null);
        $jam->setDelaySeconds($data['delay'] ?? null);
        $jam->setLevel($data['level'] ?? null);
        $jam->setFirstSeenAt(new DateTime());
        $jam->setLastSeenAt(new DateTime());
        $jam->setIsActive(true);
        $jam->setRawPayload($data);

        $this->em->persist($jam);
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
