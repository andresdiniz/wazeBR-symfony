<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PollAllTrafficLights;
use App\Message\PollTrafficLight;
use App\Repository\TrafficLightRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class PollAllTrafficLightsHandler
{
    public function __construct(
        private readonly TrafficLightRepository $repository,
        private readonly MessageBusInterface $bus,
    ) {}

    public function __invoke(PollAllTrafficLights $message): void
    {
        foreach ($this->repository->findActive() as $light) {
            $this->bus->dispatch(new PollTrafficLight((int) $light->getId()));
        }
    }
}
