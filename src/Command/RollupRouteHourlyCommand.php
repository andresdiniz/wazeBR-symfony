<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Agrega os snapshots de rota por HORA (UTC) em waze_tvt_route_hourly.
 *
 * Idempotente (INSERT … SELECT … ON DUPLICATE KEY UPDATE): pode rodar quantas vezes
 * quiser e reprocessa dados que chegaram atrasados.
 *
 * Uso típico:
 *   # uma vez, para popular o histórico (processa em blocos de 1 dia)
 *   php bin/console app:routes:rollup-hourly --backfill-days=45
 *
 *   # no cron, a cada 15 minutos (reprocessa as últimas 48 h)
 *   *\/15 * * * * php /caminho/bin/console app:routes:rollup-hourly
 *
 * A página /routes/{id} só usa o agregado quando ele cobre toda a janela dos
 * snapshots brutos; enquanto o backfill não rodar, continua lendo os brutos.
 */
#[AsCommand(
    name: 'app:routes:rollup-hourly',
    description: 'Agrega snapshots de rotas TVT por hora (tabela waze_tvt_route_hourly).',
)]
final class RollupRouteHourlyCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Reprocessa as últimas N horas.', '48')
            ->addOption('backfill-days', null, InputOption::VALUE_REQUIRED, 'Reprocessa os últimos N dias (em blocos de 1 dia). Substitui --hours.')
            ->addOption('route', null, InputOption::VALUE_REQUIRED, 'Limita a uma rota (id interno).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $hours = filter_var($input->getOption('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 24 * 400]]);
        if ($hours === false) {
            $io->error('--hours inválido (1–9600).');

            return Command::INVALID;
        }

        $backfillOpt = $input->getOption('backfill-days');
        $backfill = $backfillOpt !== null
            ? filter_var($backfillOpt, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 400]])
            : null;
        if ($backfillOpt !== null && $backfill === false) {
            $io->error('--backfill-days inválido (1–400).');

            return Command::INVALID;
        }

        $routeOpt = $input->getOption('route');
        $routeId = $routeOpt !== null ? filter_var($routeOpt, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        if ($routeOpt !== null && $routeId === false) {
            $io->error('--route inválido.');

            return Command::INVALID;
        }

        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);
        $end = $now->setTime((int) $now->format('G'), 0, 0)->modify('+1 hour'); // exclusivo: inclui a hora corrente

        $start = $backfill !== null
            ? $now->modify("-{$backfill} days")->setTime(0, 0, 0)
            : $now->modify("-{$hours} hours")->setTime((int) $now->modify("-{$hours} hours")->format('G'), 0, 0);

        $step = $backfill !== null ? '+1 day' : null;
        $affected = 0;
        $chunks = 0;

        for ($from = $start; $from < $end; ) {
            $to = $step !== null ? min($from->modify($step), $end) : $end;

            $affected += $this->rollup($from, $to, $routeId ?: null);
            ++$chunks;

            if ($step === null) {
                break;
            }
            $from = $to;
        }

        $io->success(sprintf(
            'Janela %s → %s (UTC): %d bloco(s), %d linha(s) afetada(s).',
            $start->format('Y-m-d H:i'),
            $end->format('Y-m-d H:i'),
            $chunks,
            $affected,
        ));

        return Command::SUCCESS;
    }

    private function rollup(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $routeId): int
    {
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        $routeFilter = '';
        if ($routeId !== null) {
            $routeFilter = ' AND s.route_id = :route';
            $params['route'] = $routeId;
        }

        $valid = '(s.time IS NOT NULL AND s.historic_time IS NOT NULL)';

        $sql = "INSERT INTO waze_tvt_route_hourly
                    (route_id, partner_id, bucket_start, samples, sum_delay, sum_ratio, n_ratio,
                     sum_jam, n_jam, jam_0, jam_1, jam_2, jam_3, jam_4, jam_5, updated_at)
                SELECT
                    s.route_id,
                    s.partner_id,
                    DATE_FORMAT(s.recorded_at, '%Y-%m-%d %H:00:00') AS bucket,
                    SUM(CASE WHEN {$valid} THEN 1 ELSE 0 END),
                    COALESCE(SUM(CASE WHEN {$valid} THEN s.time - s.historic_time END), 0),
                    COALESCE(SUM(CASE WHEN {$valid} AND s.historic_time > 0
                                      THEN (s.time - s.historic_time) / s.historic_time END), 0),
                    SUM(CASE WHEN {$valid} AND s.historic_time > 0 THEN 1 ELSE 0 END),
                    COALESCE(SUM(CASE WHEN {$valid} THEN s.jam_level END), 0),
                    SUM(CASE WHEN {$valid} AND s.jam_level IS NOT NULL THEN 1 ELSE 0 END),
                    SUM(CASE WHEN s.jam_level = 0 THEN 1 ELSE 0 END),
                    SUM(CASE WHEN s.jam_level = 1 THEN 1 ELSE 0 END),
                    SUM(CASE WHEN s.jam_level = 2 THEN 1 ELSE 0 END),
                    SUM(CASE WHEN s.jam_level = 3 THEN 1 ELSE 0 END),
                    SUM(CASE WHEN s.jam_level = 4 THEN 1 ELSE 0 END),
                    SUM(CASE WHEN s.jam_level = 5 THEN 1 ELSE 0 END),
                    UTC_TIMESTAMP()
                FROM waze_tvt_route_snapshot s
                WHERE s.recorded_at >= :from
                  AND s.recorded_at < :to{$routeFilter}
                GROUP BY s.route_id, s.partner_id, bucket
                ON DUPLICATE KEY UPDATE
                    partner_id = VALUES(partner_id),
                    samples    = VALUES(samples),
                    sum_delay  = VALUES(sum_delay),
                    sum_ratio  = VALUES(sum_ratio),
                    n_ratio    = VALUES(n_ratio),
                    sum_jam    = VALUES(sum_jam),
                    n_jam      = VALUES(n_jam),
                    jam_0 = VALUES(jam_0), jam_1 = VALUES(jam_1), jam_2 = VALUES(jam_2),
                    jam_3 = VALUES(jam_3), jam_4 = VALUES(jam_4), jam_5 = VALUES(jam_5),
                    updated_at = VALUES(updated_at)";

        return (int) $this->connection->executeStatement($sql, $params);
    }
}
