<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Scheduler\Message\FetchWazeAlertsMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Agenda a coleta de alertas Waze a cada 2 minutos.
 *
 * O worker deve ser iniciado com:
 *   php bin/console messenger:consume scheduler_waze_feed --time-limit=3600
 *
 * Em produ\u00e7\u00e3o (Supervisor):
 *   command = php /var/www/wazeBR-symfony/bin/console messenger:consume scheduler_waze_feed --time-limit=3600 --memory-limit=128M
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
            ->add(
                RecurringMessage::every('2 minutes', new FetchWazeAlertsMessage())
            )
            ->stateful($this->cache);
    }
}
