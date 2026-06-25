<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Scheduler\Message\CollectWazeFeedMessage;
use App\Scheduler\Message\FetchWazeAlertsMessage;
use App\Scheduler\Message\FetchWazeTrafficMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Scheduler central do WazeBR.
 *
 * Tarefas:
 *   - FetchWazeAlertsMessage   → a cada 2 min (coleta bbox geográfica)
 *   - FetchWazeTrafficMessage  → a cada 2 min (coleta TVT)
 *   - CollectWazeFeedMessage   → a cada 5 min (coleta feed PartnerHub por MonitoredLink)
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
            ->add(RecurringMessage::every('2 minutes', new FetchWazeAlertsMessage()))
            ->add(RecurringMessage::every('2 minutes', new FetchWazeTrafficMessage()))
            ->add(RecurringMessage::every('5 minutes', new CollectWazeFeedMessage()))
            ->stateful($this->cache);
    }
}
