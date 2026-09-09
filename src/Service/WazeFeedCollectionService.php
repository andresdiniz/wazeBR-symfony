<?php

declare(strict_types=1);

namespace App\Service;

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

            $url = sprintf(
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

            // Adiciona token de autenticacao se existir
            if ($apiToken) {
                $options['headers']['Authorization'] = 'Bearer ' . $apiToken;
            }

            $response = $this->httpClient->request('GET', $url, $options);

            $data = $response->toArray();

            $this->logger->info('Waze TVT feed response', [
                'partner' => $partner->getId(),
                'feed' => $feedUuid,
                'items_count' => count($data),
            ]);

            $routesCount = 0;

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
                'definitions' => 0,
                'history' => 0,
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

    private function processTvtItem(array $item, $partner, WazeFeedCollection $feedCollection): void
    {
        // Implementacao da logica de processamento do item TVT
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
            $fc->setFinishedAt(new \DateTimeImmutable());
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
            $fc->setErrorMessage($reason);
            $fc->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Erro ao marcar coleta como falha', [
                'feed_collection' => $fc->getId(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
