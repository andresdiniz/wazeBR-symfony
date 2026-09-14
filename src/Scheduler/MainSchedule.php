<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('default')]
final class MainSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            // ── Waze Alerts + Jams ─────────────────────────────────────────
            // Frequência real é controlada pelo próprio comando, que respeita
            // o partner.fetchFrequency. O scheduler só garante que ele roda.
            ->add(
                RecurringMessage::every(
                    '1 minute',
                    new RunCommandMessage('app:fetch-waze-feed --no-interaction'),
                ),
            )

            // ── Waze TVT (rotas) ───────────────────────────────────────────
            ->add(
                RecurringMessage::every(
                    '1 minute',
                    new RunCommandMessage('app:fetch:waze:tvt --no-interaction'),
                ),
            )

            // ── CEMADEN Hidro (nível do rio + chuva) ───────────────────────
            // Dados são horários; 30 min dá folga para o CEMADEN publicar.
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
            // Open-Meteo atualiza "current" a cada ~15 min. Coletar de
            // 10 em 10 garante que pegamos cada atualização.
            ->add(
                RecurringMessage::every(
                    '10 minutes',
                    new RunCommandMessage('app:weather:fetch-observations --no-interaction'),
                ),
            );
    }
}
