<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('default')]
final class MainSchedule implements ScheduleProviderInterface
{
    public function __construct(private readonly CacheInterface $cache) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->add(RecurringMessage::every('1 minute',new RunCommandMessage('app:partner-feed:update-json --no-interaction')))
            ->add(RecurringMessage::every('2 minutes',new RunCommandMessage('app:fetch-waze-feed --no-interaction')))
            ->add(RecurringMessage::every('2 minutes',new RunCommandMessage('app:fetch:waze:tvt --no-interaction')))
            ->add(RecurringMessage::every('10 minutes',new RunCommandMessage('app:fetch:cemaden:hidro --no-interaction')))
            ->add(RecurringMessage::every('10 minutes',new RunCommandMessage('app:fetch:cemaden:pluviometric --no-interaction')))
            ->add(RecurringMessage::every('5 minutes',new RunCommandMessage('app:weather:fetch-observations --no-interaction')))
            ->add(RecurringMessage::every('15 minutes',new RunCommandMessage('app:tvt:refresh-congestion-forecast --slot=15 --slots-ahead=24 --no-interaction')))
            ->add(RecurringMessage::every('5 minutes',new RunCommandMessage('app:tvt:evaluate-congestion-forecast --capture --no-interaction')))
            ->add(RecurringMessage::every('5 minutes',new RunCommandMessage('app:tvt:evaluate-congestion-forecast --hours=1 --no-interaction')))
            // Global maintenance. No forecast/audit deletion, no automatic OPTIMIZE.
            ->add(RecurringMessage::cron(
                '17 3 * * *',
                new RunCommandMessage('app:db:maintain-snapshots --execute --keep-payload-hours=48 --keep-traffic-days=30 --batch-size=1000 --max-batches=50 --max-seconds=120 --pause-ms=100 --no-interaction'),
                new \DateTimeZone('America/Sao_Paulo'),
            ));
        // Traffic-light polling remains disabled.
    }
}
