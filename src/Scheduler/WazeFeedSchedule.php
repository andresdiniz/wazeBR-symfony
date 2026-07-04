<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Scheduler\Message\CollectWazeFeedMessage;
use App\Scheduler\Message\CollectWazeTvtMessage;
use App\Scheduler\Message\FetchCemadenHydroMessage;
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
 * Tarefas agendadas:
 *   - FetchWazeAlertsMessage    → a cada 5 min  (coleta alertas via feed PartnerHub)
 *   - FetchWazeTrafficMessage   → a cada 5 min  (coleta jams via feed PartnerHub)
 *   - CollectWazeFeedMessage    → a cada 5 min  (PartnerHub — fallback via Command)
 *   - CollectWazeTvtMessage     → a cada 5 min  (TVT — fallback via Command)
 *   - FetchCemadenHydroMessage  → a cada 10 min (dados hidrológicos CEMADEN)
 *
 * Iniciar o worker:
 *   php bin/console messenger:consume scheduler_main --time-limit=3600 -vv
 */
#[AsSchedule('main')]
class WazeFeedSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('5 minutes',  new FetchWazeAlertsMessage()))
            ->add(RecurringMessage::every('5 minutes',  new FetchWazeTrafficMessage()))
            ->add(RecurringMessage::every('5 minutes',  new CollectWazeFeedMessage()))
            ->add(RecurringMessage::every('5 minutes',  new CollectWazeTvtMessage()))
            ->add(RecurringMessage::every('10 minutes', new FetchCemadenHydroMessage()))
            ->stateful($this->cache);
    }
}
