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

    /**
     * @return array{routes: int, definitions: int, history: int}
     */
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
            $endpointUrl = $feed->getEndpointUrl();

            // Usa endpoint_url se existir, senao constroi URL padrao
            if ($endpointUrl) {
                $url = $endpointUrl;
            } else {
                $url = sprintf(
                    'https://www.waze.com/row-partnerhub-api/feeds-tvt/%s',
                    $feedUuid
                );
            }

            $options = [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'wazeBR-symfony/1.0',
                ],
                'timeout' => 30,
            ];

            // Adiciona token de autenticacao se existir
            if ($apiToken) {
                $options['headers']['Authorization'] = 'Bearer ' . $apiToken;
            }

            // Adiciona feed ID numerico se existir (requerido pela API Waze)
            if ($feedId && !str_contains($url, '?id=')) {
                $options['query'] = ['id' => (string) $feedId];
            }

            $response = $this->httpClient->request('GET', $url, $options);

            $data = $response->toArray();

            $this->logger->info('Waze TVT feed response', [
                'partner' => $partner->getId(),
                'feed' => $feedUuid,
                'feed_id' => $feedId,
                'url' => $url,
                'items_count' => count($data),
            ]);

            $routesCount = 0;
            $definitionsCount = 0;
            $historyCount = 0;

            // As rotas estao dentro de $data['routes']
            $routes = $data['routes'] ?? [];
            
            $this->logger->debug('Routes found', ['count' => count($routes)]);

            foreach ($routes as $index => $item) {
                if (!$dryRun && is_array($item)) {
                    try {
                        $result = $this->processTvtItem($item, $partner, $feed, $feedCollection, $index);
                        if ($result !== null) {
                            $routesCount++;
                            $definitionsCount += $result['definitions'];
                            $historyCount += $result['history'];
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error('Erro ao processar item TVT', [
                            'index' => $index,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Flush explicito para garantir que tudo seja salvo
            if (!$dryRun && ($routesCount > 0 || $definitionsCount > 0 || $historyCount > 0)) {
                try {
                    $this->em->flush();
                    $this->logger->info('Dados TVT salvos com sucesso', [
                        'routes' => $routesCount,
                        'definitions' => $definitionsCount,
                        'history' => $historyCount,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->critical('Erro ao salvar dados TVT no banco', [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    throw $e;
                }
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

    /**
     * @return array{definitions: int, history: int}|null
     */
    private function processTvtItem(array $item, $partner, WazeFeed $feed, WazeFeedCollection $feedCollection, int $index): ?array
    {
        // Pula itens que nao sao rotas (sem campo 'id')
        if (!isset($item['id']) || !is_scalar($item['id'])) {
            $this->logger->debug('Item TVT ignorado (sem id)', ['index' => $index]);
            return null;
        }

        $routeId = (string) $item['id'];
        $name = $item['name'] ?? null;
        $fromName = $item['fromName'] ?? null;
        $toName = $item['toName'] ?? null;
        $length = (int) ($item['length'] ?? 0);
        $time = (int) ($item['time'] ?? 0);
        $historicTime = (int) ($item['historicTime'] ?? 0);
        $jamLevel = (int) ($item['jamLevel'] ?? 0);
        $line = $item['line'] ?? [];
        $type = $item['type'] ?? null;
        $bbox = $item['bbox'] ?? null;

        $this->logger->debug('Processando rota TVT', [
            'index' => $index,
            'route_id' => $routeId,
            'name' => $name,
            'from' => $fromName,
            'to' => $toName,
            'length' => $length,
            'time' => $time,
        ]);

        // Buscar ou criar a rota
        $route = $this->tvtRouteRepo->findOneByExternalRouteId($routeId);
        
        if (!$route) {
            $route = new WazeTvtRoute();
            $route->setPartner($partner);
            $route->setWazeFeed($feed);
            $route->setExternalRouteId($routeId);
            $route->setLabel($name);
            $route->setIsActive(true);
            $route->setFirstSeenAt(new DateTime());
            $route->setLastSeenAt(new DateTime());
            $this->em->persist($route);
            
            $this->logger->debug('Nova rota TVT criada', ['route_id' => $routeId]);
        } else {
            // Atualizar ultima visualizacao
            $route->setLastSeenAt(new DateTime());
            $route->setLabel($name);
        }

        // Calcular velocidade (km/h) = (metros / segundos) * 3.6
        $speedKmh = $time > 0 ? round(($length / $time) * 3.6, 2) : null;
        $delaySeconds = $time - $historicTime;

        // Criar definicao simplificada (apenas campos que existem no banco)
        $definition = new WazeTvtRouteDefinition();
        $definition->setRouteId($routeId);
        $definition->setName($name);
        $definition->setBbox($bbox);
        $definition->setLine($line);
        $this->em->persist($definition);

        // Criar historico
        $history = new WazeTvtRouteHistory();
        $history->setRouteId($routeId);
        $history->setObservedAt(new DateTime());
        $history->setTravelTimeSeconds($time);
        $history->setSpeedKmh($speedKmh !== null ? (string) $speedKmh : null);
        $history->setDelaySeconds($delaySeconds > 0 ? $delaySeconds : null);
        $history->setLengthMeters($length);
        $history->setStatus($jamLevel > 3 ? 'congested' : ($jamLevel > 0 ? 'moderate' : 'free'));
        $history->setRawMetrics([
            'jamLevel' => $jamLevel,
            'historicTime' => $historicTime,
            'type' => $type,
            'fromName' => $fromName,
            'toName' => $toName,
        ]);
        $this->em->persist($history);

        $this->logger->debug('Rota TVT salva', [
            'route_id' => $routeId,
            'speed_kmh' => $speedKmh,
            'delay_seconds' => $delaySeconds,
        ]);

        return ['definitions' => 1, 'history' => 1];
    }

    public function getLastFeedCollection(WazeFeed $feed): ?WazeFeedCollection
    {
        return $this->feedCollectionRepo->findOneBy(
            ['wazeFeed' => $feed],
            ['id' => 'DESC']
        );
    }

    public function success(WazeFeedCollection $fc): void
    {
        if (!$this->em->isOpen()) {
            $this->logger->warning('EntityManager fechado ao tentar marcar coleta como sucesso', [
                'feed_collection' => $fc->getId(),
            ]);
            return;
        }

        try {
            $fc->setStatus('success');
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Erro ao marcar coleta como sucesso', [
                'feed_collection' => $fc->getId(),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function fail(string $reason, WazeFeedCollection $fc): void
    {
        if (!$this->em->isOpen()) {
            $this->logger->warning('EntityManager fechado ao tentar marcar coleta como falha', [
                'feed_collection' => $fc->getId(),
                'reason' => $reason,
            ]);
            return;
        }

        try {
            $fc->setStatus('error');
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Erro ao marcar coleta como falha', [
                'feed_collection' => $fc->getId(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
