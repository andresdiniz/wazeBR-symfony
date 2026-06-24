<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Scheduler\Message\FetchWazeAlertsMessage;
use App\Scheduler\Message\FetchWazeTrafficMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Agenda a coleta de alertas E tr\u00e1fego Waze a cada 2 minutos.
 *
 * Worker:
 *   php bin/console messenger:consume scheduler_waze_feed --time-limit=3600
 */
#[AsSchedule('waze_feed')]
class WazeFeedSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            // Alertas a cada 2 minutos
            ->add(RecurringMessage::every('2 minutes', new FetchWazeAlertsMessage()))
            // Tr\u00e1fego TVT a cada 2 minutos (offset de 30s para n\u00e3o bater junto)
            ->add(RecurringMessage::every('2 minutes', new FetchWazeTrafficMessage()))
            ->stateful($this->cache);
    }
}
