<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use App\Message\CollectWazeFeedMessage;

/**
 * Scheduler central do WazeBR.
 *
 * Tarefas agendadas:
 *   - Coleta de alertas + jams do feed PartnerHub    → a cada 5 minutos
 *   - (futuramente) relatório diário, coleta CEMADEN, etc.
 *
 * Para rodar:
 *   php bin/console messenger:consume scheduler_default
 *
 * Para testar manualmente:
 *   php bin/console app:waze:collect-feed --dry-run
 */
#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= (new Schedule())
            ->stateful($this->cache)
            ->add(
                RecurringMessage::every('5 minutes', new CollectWazeFeedMessage()),
            );
    }
}
