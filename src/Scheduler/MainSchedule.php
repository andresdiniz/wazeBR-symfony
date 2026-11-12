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
        $timezone = new \DateTimeZone('America/Sao_Paulo');

        return (new Schedule())
            ->stateful($this->cache)
            ->add(RecurringMessage::every('1 minute', new RunCommandMessage('app:partner-feed:update-json --no-interaction')))
            // A coleta Waze também dispara os e-mails de alertas importantes.
            ->add(RecurringMessage::every('2 minutes', new RunCommandMessage('app:fetch-waze-feed --no-interaction')))
            // Lê a última observação disponível; a ordem não garante coleta prévia.
            // O comando tem lock por parceiro e não duplica snapshots com o mesmo last_seen_at.
            ->add(RecurringMessage::every('1 minute', new RunCommandMessage('app:jams:forecast --no-interaction')))
            ->add(RecurringMessage::every('2 minutes', new RunCommandMessage('app:fetch:waze:tvt --no-interaction')))
            // Sem --partner: percorre parceiros ativos com links TVT. A trava é compartilhada com a coleta acima.
            ->add(RecurringMessage::every('2 minutes', new RunCommandMessage('app:fetch:waze:tvt-irregularities --no-interaction')))
            ->add(RecurringMessage::every('10 minutes', new RunCommandMessage('app:fetch:cemaden:hidro --no-interaction')))
            ->add(RecurringMessage::every('10 minutes', new RunCommandMessage('app:fetch:cemaden:pluviometric --no-interaction')))
            ->add(RecurringMessage::every('5 minutes', new RunCommandMessage('app:weather:fetch-observations --no-interaction')))
            ->add(RecurringMessage::every('15 minutes', new RunCommandMessage('app:tvt:refresh-congestion-forecast --slot=15 --slots-ahead=24 --no-interaction')))
            ->add(RecurringMessage::every('5 minutes', new RunCommandMessage('app:tvt:evaluate-congestion-forecast --capture --no-interaction')))
            ->add(RecurringMessage::every('5 minutes', new RunCommandMessage('app:tvt:evaluate-congestion-forecast --hours=1 --no-interaction')))
            // Boletim diário por e-mail, às 07h de São Paulo.
            ->add(RecurringMessage::cron('0 7 * * *', new RunCommandMessage('waze:briefing:daily --no-ai --no-interaction'), $timezone))
            // Manutenção às 03h17: sem apagar previsões/auditorias ou executar OPTIMIZE.
            ->add(RecurringMessage::cron(
                '17 3 * * *',
                new RunCommandMessage('app:db:maintain-snapshots --execute --keep-payload-hours=48 --keep-traffic-days=30 --batch-size=1000 --max-batches=50 --max-seconds=120 --pause-ms=100 --no-interaction'),
                $timezone,
            ));
        // A coleta de semáforos permanece desativada.
    }
}
