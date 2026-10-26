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
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)

            // ── Coleta de feeds ──────────────────────────────────

            ->add(RecurringMessage::every(
                '1 minute',
                new RunCommandMessage(
                    'app:partner-feed:update-json --no-interaction'
                )
            ))

            ->add(RecurringMessage::every(
                '2 minutes',
                new RunCommandMessage(
                    'app:fetch-waze-feed --no-interaction'
                )
            ))

            ->add(RecurringMessage::every(
                '2 minutes',
                new RunCommandMessage(
                    'app:fetch:waze:tvt --no-interaction'
                )
            ))

            ->add(RecurringMessage::every(
                '10 minutes',
                new RunCommandMessage(
                    'app:fetch:cemaden:hidro --no-interaction'
                )
            ))

            ->add(RecurringMessage::every(
                '10 minutes',
                new RunCommandMessage(
                    'app:fetch:cemaden:pluviometric --no-interaction'
                )
            ))

            ->add(RecurringMessage::every(
                '5 minutes',
                new RunCommandMessage(
                    'app:weather:fetch-observations --no-interaction'
                )
            ))

            // ── Geração de previsões ─────────────────────────────
            // Atualiza 24 janelas futuras de 15 minutos.

            ->add(RecurringMessage::every(
                '15 minutes',
                new RunCommandMessage(
                    'app:tvt:refresh-congestion-forecast --slot=15 --slots-ahead=24 --no-interaction'
                )
            ))

            // ── Captura das previsões ────────────────────────────
            // Preserva as emissões antes da janela prevista.

            ->add(RecurringMessage::every(
                '5 minutes',
                new RunCommandMessage(
                    'app:tvt:evaluate-congestion-forecast --capture --no-interaction'
                )
            ))

            // ── Avaliação das previsões ──────────────────────────
            // A cada 5 minutos, avalia somente a última hora.

            ->add(RecurringMessage::every(
                '5 minutes',
                new RunCommandMessage(
                    'app:tvt:evaluate-congestion-forecast --hours=1 --no-interaction'
                )
            ))

            // ── Semáforos ────────────────────────────────────────
            // Mantido desabilitado conforme sua configuração.
            //
            // ->add(RecurringMessage::every(
            //     '5 seconds',
            //     new RunCommandMessage(
            //         'app:traffic-light:poll --no-interaction'
            //     )
            // ))
            ;
    }
}
