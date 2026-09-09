<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\WazeFeed;
use App\Entity\WazeFeedCollection;
use App\Repository\WazeFeedCollectionRepository;
use App\Repository\WazeFeedRepository;
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
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{routes: int, definitions: int, history: int}
     */
    public function collect(Partner $partner, string $feedUuid, bool $dryRun = false): array
    {
        // Busca ou cria o WazeFeed
        $feed = $this->feedRepo->findOneBy(['partner' => $partner, 'feedUuid' => $feedUuid]);
        
        if (!$feed && !$dryRun) {
            $feed = new WazeFeed();
            $feed->setPartner($partner);
            $feed->setFeedUuid($feedUuid);
            $feed->setType('tvt');
            $this->em->persist($feed);
            $this->em->flush();
        }

        $feedCollection = new WazeFeedCollection();
        $feedCollection->setPartner($partner);
        $feedCollection->setFeed($feed);
        $feedCollection->setStatus('processing');
        $feedCollection->setStartedAt(new \DateTimeImmutable());

        if (!$dryRun) {
            $this->em->persist($feedCollection);
            $this->em->flush();
        }

        try {
            $url = sprintf(
                'https://www.waze.com/row-partnerhub-api/feeds-tvt/%s?id=%s',
                $partner->getWazePartnerUuid(),
                $feedUuid
            );

            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'wazeBR-symfony/1.0',
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();

            $this->logger->info('Waze TVT feed response', [
                'partner' => $partner->getId(),
                'feed' => $feedUuid,
                'items_count' => count($data),
            ]);

            $routesCount = 0;
            $definitionsCount = 0;
            $historyCount = 0;

            foreach ($data as $item) {
                if (!$dryRun) {
                    $this->processTvtItem($item, $partner, $feedCollection);
                    $routesCount++;
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
                'partner' => $partner->getId(),
                'feed' => $feedUuid,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function processTvtItem(array $item, Partner $partner, WazeFeedCollection $feedCollection): void
    {
        // Implementacao da logica de processamento do item TVT
        // Extrai dados e cria/atualiza entidades WazeTvtRoute, WazeTvtRouteDefinition, WazeTvtRouteHistory
    }

    public function getLastFeedCollection(Partner $partner, string $feedUuid): ?WazeFeedCollection
    {
        $feed = $this->feedRepo->findOneBy(['partner' => $partner, 'feedUuid' => $feedUuid]);
        if (!$feed) {
            return null;
        }
        
        return $this->feedCollectionRepo->findOneBy(
            ['feed' => $feed],
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
            $fc->setCompletedAt(new \DateTimeImmutable());
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
            $fc->setLastError($reason);
            $fc->setUpdatedAt(new \DateTimeImmutable());
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Erro ao marcar coleta como falha', [
                'feed_collection' => $fc->getId(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
