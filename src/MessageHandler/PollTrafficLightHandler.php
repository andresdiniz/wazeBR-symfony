<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PollTrafficLight;
use App\Repository\TrafficLightRepository;
use App\Service\TrafficLight\TrafficLightManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PollTrafficLightHandler
{
    public function __construct(
        private readonly TrafficLightRepository $repository,
        private readonly TrafficLightManager $manager,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PollTrafficLight $message): void
    {
        $light = $this->repository->find($message->trafficLightId);
        if ($light === null || !$light->isActive()) {
            return;
        }

        $snapshot = $this->manager->poll($light);
        if (!$snapshot->isSuccess()) {
            $this->logger->warning('Poll falhou via Messenger', [
                'lightId' => $light->getId(),
                'error'   => $snapshot->getErrorMessage(),
            ]);
        }
    }
}
