<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Scheduler central para coleta de dados externos.
 *
 * Intervalos configurados para equilibrar frescor dos dados e carga nas APIs:
 *
 *   waze_feed        →  a cada 2 min   (alertas/jams mudam rapidamente)
 *   waze_tvt         →  a cada 5 min   (rotas TVT têm granularidade de minutos)
 *   weather          →  a cada 15 min  (Open-Meteo atualiza em intervalos de 15 min)
 *   cemaden:pluvio   →  a cada 60 min  (dados são horários; lastFetchedAt evita duplos)
 *   cemaden:hidro    →  a cada 60 min  (idem)
 *
 * Os commands de CEMADEN possuem controle interno de lastFetchedAt + min-interval,
 * portanto a execução pelo scheduler a cada hora é suficiente — o command já filtra
 * stations que ainda não atingiram o intervalo mínimo.
 *
 * Para alterar a frequência de um command específico, basta modificar a string
 * de intervalo ('every X minutes') nesta classe, sem tocar no command em si.
 */
#[AsSchedule('data_collection')]
class DataCollectionSchedule implements ScheduleProviderInterface
{
    public function __construct(private readonly CacheInterface $cache) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->add(
                // Waze Feed: alertas e congestionamentos — a cada 2 minutos
                RecurringMessage::every('2 minutes', new RunCommandMessage('app:fetch:waze:feed')),
            )
            ->add(
                // Waze TVT: rotas, snapshots, users-on-jam, irregularidades — a cada 5 minutos
                RecurringMessage::every('5 minutes', new RunCommandMessage('app:fetch:waze:tvt')),
            )
            ->add(
                // Open-Meteo: temperatura, vento, chuva, etc. — a cada 15 minutos
                RecurringMessage::every('15 minutes', new RunCommandMessage('app:fetch:weather')),
            )
            ->add(
                // CEMADEN Pluviométrico: dados horários — a cada 60 minutos
                // O command filtra internamente estações que ainda não atingiram o min-interval.
                RecurringMessage::every('60 minutes', new RunCommandMessage('app:fetch:cemaden:pluviometric')),
            )
            ->add(
                // CEMADEN Hidrológico: nível de rios — a cada 60 minutos
                RecurringMessage::every('60 minutes', new RunCommandMessage('app:fetch:cemaden:hidro')),
            );
    }
}
