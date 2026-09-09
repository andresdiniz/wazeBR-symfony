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
use DateTimeImmutable;
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
            $errorCount = 0;

            foreach ($data as $index => $item) {
                if (!$dryRun) {
                    // Converte item para array se for string (JSON decode)
                    if (is_string($item)) {
                        $item = json_decode($item, true) ?? [];
                    }
                    if (is_array($item)) {
                        try {
                            $result = $this->processTvtItem($item, $partner, $feed, $feedCollection, $index);
                            $routesCount++;
                            $definitionsCount += $result['definitions'] ?? 0;
                            $historyCount += $result['history'] ?? 0;
                        } catch (\Throwable $e) {
                            $this->logger->error('Erro ao processar item TVT', [
                                'index' => $index,
                                'message' => $e->getMessage(),
                            ]);
                            $errorCount++;
                        }
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
                'errors' => $errorCount,
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
     * @return array{definitions: int, history: int}
     */
    private function processTvtItem(array $item, $partner, WazeFeed $feed, WazeFeedCollection $feedCollection, int $index): array
    {
        $definitionsCount = 0;
        $historyCount = 0;

        // Extrair dados do item
        $routeId = $item['routeId'] ?? $item['id'] ?? null;
        $routeName = $item['routeName'] ?? $item['name'] ?? '';
        $from = $item['from'] ?? '';
        $to = $item['to'] ?? '';
        $length = $item['length'] ?? 0;
        $speed = $item['speed'] ?? 0;
        $delay = $item['delay'] ?? 0;
        $level = $item['level'] ?? 0;
        $type = $item['type'] ?? '';
        $coords = $item['coords'] ?? $item['geometry'] ?? [];

        if (!$routeId) {
            $this->logger->warning('Item TVT sem routeId', ['index' => $index, 'item' => $item]);
            return ['definitions' => 0, 'history' => 0];
        }

        $this->logger->debug('Processando item TVT', [
            'index' => $index,
            'route_id' => $routeId,
            'route_name' => $routeName,
            'speed' => $speed,
            'delay' => $delay,
        ]);

        // Buscar ou criar a rota
        $route = $this->tvtRouteRepo->findOneBy(['externalRouteId' => (string) $routeId]);
        
        if (!$route) {
            $route = new WazeTvtRoute();
            $route->setExternalRouteId((string) $routeId);
            $route->setName($routeName);
            $route->setFrom($from);
            $route->setTo($to);
            $route->setLength((float) $length);
            $route->setPartner($partner);
            $this->em->persist($route);
            
            $this->logger->debug('Nova rota TVT criada', ['route_id' => $routeId]);
        }

        // Criar definicao da rota
        $definition = new WazeTvtRouteDefinition();
        $definition->setRoute($route);
        $definition->setSpeed((float) $speed);
        $definition->setDelay((float) $delay);
        $definition->setLevel((int) $level);
        $definition->setType($type);
        if (is_array($coords)) {
            $definition->setCoords($coords);
        }
        $this->em->persist($definition);
        $definitionsCount++;

        // Criar historico
        $history = new WazeTvtRouteHistory();
        $history->setRoute($route);
        $history->setDefinition($definition);
        $history->setTimestamp(new DateTimeImmutable());
        $history->setSpeed((float) $speed);
        $history->setDelay((float) $delay);
        $history->setLevel((int) $level);
        $this->em->persist($history);
        $historyCount++;

        $this->logger->debug('Item TVT processado', [
            'route_id' => $routeId,
            'definitions' => $definitionsCount,
            'history' => $historyCount,
        ]);

        return ['definitions' => $definitionsCount, 'history' => $historyCount];
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
