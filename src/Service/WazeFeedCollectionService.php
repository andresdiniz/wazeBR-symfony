<?php

namespace App\Service;

use App\Entity\WazeFeed;
use App\Entity\WazeFeedCollection;
use App\Repository\WazeFeedCollectionRepository;
use Doctrine\ORM\EntityManagerInterface;

class WazeFeedCollectionService
{
    public function __construct(
        private readonly WazeFeedCollectionRepository $collectionRepository,
        private readonly EntityManagerInterface $em
    ) {}

    public function start(WazeFeed $feed): WazeFeedCollection
    {
        return $this->collectionRepository->createForFeed($feed);
    }

    public function succeed(
        WazeFeedCollection $collection,
        int $alerts,
        int $jams,
        int $routes,
        ?string $payloadHash
    ): void {
        $collection->succeed($alerts, $jams, $routes, $payloadHash);

        $feed = $collection->getWazeFeed();
        $feed->setLastSuccessAt(new \DateTime());
        $feed->setLastErrorMessage(null);

        $this->em->flush();
    }

    public function fail(WazeFeedCollection $collection, \Throwable $e): void
    {
        $collection->fail($e);

        $feed = $collection->getWazeFeed();
        $feed->setLastErrorAt(new \DateTime());
        $feed->setLastErrorMessage(substr($e->getMessage(), 0, 500));

        $this->em->flush();
    }
}
