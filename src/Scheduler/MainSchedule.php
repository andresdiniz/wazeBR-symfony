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
                    '5 minutes',
                    new RunCommandMessage('app:weather:fetch-observations --no-interaction'),
                ),
            )

            ->add(
                RecurringMessage::every(
                    '15 minutes',
                    new RunCommandMessage(
                        'app:tvt:evaluate-congestion-forecast --no-interaction'
                    ),
                    new \DateTimeZone('America/Sao_Paulo'),
                ),
            )

            // ── Previsão: 24 janelas de 15 minutos = próximas 6 horas ───────
            ->add(
                RecurringMessage::every(
                    '15 minutes',
                    new RunCommandMessage(
                        'app:tvt:refresh-congestion-forecast --slot=15 --slots-ahead=32 --no-interaction'
                    ),
                ),
            )

            // ── Auditoria: captura as previsões futuras disponíveis ───────
            ->add(
                RecurringMessage::every(
                    '1 minute',
                    new RunCommandMessage(
                        'app:tvt:evaluate-congestion-forecast --capture --no-interaction'
                    ),
                ),
            )

            // ── Semáforos (leitura de todos os controladores ativos) ───────
            ->add(
                RecurringMessage::every(
                    '1 seconds',
                    new RunCommandMessage('app:traffic-light:poll --no-interaction'),
                ),
            );
    }
}
