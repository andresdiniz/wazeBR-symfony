<?php

declare(strict_types=1);

namespace App\Scheduler;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('default')]
final class MainSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)

            // ── Partner Feed JSON (atualiza a cada minuto) ──────────────────
            ->add(
                RecurringMessage::every(
                    '1 minute',
                    new RunCommandMessage('app:partner-feed:update-json --no-interaction'),
                ),
            )

            // ── Waze feed (alerts/jams) ─────────────────────────────────────
            ->add(
                RecurringMessage::every(
                    '2 minutes',
                    new RunCommandMessage('app:fetch-waze-feed --no-interaction'),
                ),
            )

            // ── Waze TVT (rotas) ────────────────────────────────────────────
            ->add(
                RecurringMessage::every(
                    '2 minutes',
                    new RunCommandMessage('app:fetch:waze:tvt --no-interaction'),
                ),
            )

            // ── CEMADEN Hidro (nível do rio + chuva) ───────────────────────
            ->add(
                RecurringMessage::every(
                    '10 minutes',
                    new RunCommandMessage('app:fetch:cemaden:hidro --no-interaction'),
                ),
            )

            // ── CEMADEN Pluviométrico (chuva acumulada por estação) ────────
            ->add(
                RecurringMessage::every(
                    '10 minutes',
                    new RunCommandMessage('app:fetch:cemaden:pluviometric --no-interaction'),
                ),
            )

            // ── Clima (Open-Meteo) ─────────────────────────────────────────
            ->add(
                RecurringMessage::every(
                    '10 minutes',
                    new RunCommandMessage('app:weather:fetch-observations --no-interaction'),
                ),
            );
    }
}
