<?php

declare(strict_types=1);
namespace App\Command;

use App\Service\ForecastErrorExplainerService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[AsCommand(name: 'app:tvt:evaluate-congestion-forecast', description: 'Capture future TVT forecasts or evaluate captured forecasts against observations.')]
final class EvaluateCongestionForecastCommand extends Command
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly ForecastErrorExplainerService $explainer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('capture', null, InputOption::VALUE_NONE, 'Capture current forecasts BEFORE their target window; run frequently.')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Local target date YYYY-MM-DD; default yesterday.')
            ->addOption('route', null, InputOption::VALUE_REQUIRED, 'Optional route ID.')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Reporting timezone.', 'America/Sao_Paulo')
            ->addOption('forecast-timezone', null, InputOption::VALUE_REQUIRED, 'Timezone of existing forecast_at values.', 'America/Sao_Paulo')
            ->addOption('min-samples', null, InputOption::VALUE_REQUIRED, 'Minimum observed samples per window.', '3')
            ->addOption('min-span', null, InputOption::VALUE_REQUIRED, 'Minimum fraction of window between first and last observations.', '0.5')
            ->addOption('tolerance-seconds', null, InputOption::VALUE_REQUIRED, 'Absolute tolerance.', '10')
            ->addOption('tolerance-percent', null, InputOption::VALUE_REQUIRED, 'Relative tolerance in percent.', '15');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = (new LockFactory(new FlockStore()))->createLock('tvt-forecast-audit');
        if (!$lock->acquire()) { $io->warning('Another audit command is running on this host.'); return Command::FAILURE; }
        try {
            $utc = new \DateTimeZone('UTC');
            $tz = new \DateTimeZone((string) $input->getOption('timezone'));
            $sourceTz = new \DateTimeZone((string) $input->getOption('forecast-timezone'));
            $route = $input->getOption('route');
            if ($route !== null && (!ctype_digit((string) $route) || (int) $route < 1)) { throw new \InvalidArgumentException('Invalid route ID.'); }
            $now = new \DateTimeImmutable('now', $utc);
            if ($input->getOption('capture')) {
                $sql = 'SELECT id, route_id, forecast_at, updated_at, slot_minutes, avg_time, samples FROM waze_tvt_route_forecast WHERE avg_time IS NOT NULL AND avg_time >= 0 AND samples > 0';
                $params = [];
                if ($route !== null) { $sql .= ' AND route_id = :route'; $params['route'] = (int) $route; }
                $sql .= ' AND forecast_at > :now';
                $params['now'] = $now->setTimezone($sourceTz)->format('Y-m-d H:i:s');
                $count = 0;
                foreach ($this->db->fetchAllAssociative($sql, $params) as $row) {
                    if ($row['updated_at'] === null || (int) $row['slot_minutes'] < 1) { continue; }
                    $start = (new \DateTimeImmutable($row['forecast_at'], $sourceTz))->setTimezone($utc);
                    $issued = new \DateTimeImmutable($row['updated_at'], $utc);
                    if ($start <= $now || $issued >= $start || $issued > $now) { continue; }
                    $end = $start->modify('+' . (int) $row['slot_minutes'] . ' minutes');
                    $count += $this->db->executeStatement('INSERT INTO waze_tvt_forecast_audit (source_id, route_id, issued_at, captured_at, target_start_at, target_end_at, horizon_minutes, predicted_time, history_samples, actual_samples, status) VALUES (:source, :route, :issued, :captured, :start, :end, :horizon, :predicted, :samples, 0, \'pending\') ON DUPLICATE KEY UPDATE source_id = source_id', [
                        'source' => (int) $row['id'], 'route' => (int) $row['route_id'], 'issued' => $issued->format('Y-m-d H:i:s'), 'captured' => $now->format('Y-m-d H:i:s'), 'start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s'), 'horizon' => ($start->getTimestamp() - $issued->getTimestamp()) / 60, 'predicted' => (float) $row['avg_time'], 'samples' => (int) $row['samples'],
                    ]);
                }
                $io->success(sprintf('%d new forecast emissions captured. No historical backfill performed.', $count));
                return Command::SUCCESS;
            }
            $date = $input->getOption('date') ?? (new \DateTimeImmutable('yesterday', $tz))->format('Y-m-d');
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date, $tz);
            if (!$day || $day->format('Y-m-d') !== $date) { throw new \InvalidArgumentException('Invalid date; use YYYY-MM-DD.'); }
            $minSamples = filter_var($input->getOption('min-samples'), FILTER_VALIDATE_INT);
            $span = filter_var($input->getOption('min-span'), FILTER_VALIDATE_FLOAT);
            $seconds = filter_var($input->getOption('tolerance-seconds'), FILTER_VALIDATE_FLOAT);
            $percent = filter_var($input->getOption('tolerance-percent'), FILTER_VALIDATE_FLOAT);
            if ($minSamples === false || $minSamples < 1 || $span === false || $span < 0 || $span > 1 || $seconds === false || $seconds < 0 || $percent === false || $percent < 0) { throw new \InvalidArgumentException('Invalid evaluation thresholds.'); }

            $errors = []; $hits = 0; $missing = 0; $pending = 0; $total = 0;
            $lastId = 0;

            // Processa em lotes para reduzir pico de memória (especialmente em dev)
            while (true) {
                $params = [
                    'from' => $day->setTimezone($utc)->format('Y-m-d H:i:s'),
                    'to' => $day->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
                    'last_id' => $lastId,
                ];
                $sql = 'SELECT * FROM waze_tvt_forecast_audit WHERE target_start_at >= :from AND target_start_at < :to AND id > :last_id';
                if ($route !== null) { $sql .= ' AND route_id = :route'; $params['route'] = (int) $route; }
                // LIMIT deve ser literal inteiro (MariaDB/MySQL não aceita parâmetro nomeado em LIMIT)
                $sql .= ' ORDER BY id ASC LIMIT ' . self::BATCH_SIZE;

                $rows = $this->db->fetchAllAssociative($sql, $params);

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    $total++;

                    $start = new \DateTimeImmutable($row['target_start_at'], $utc);
                    $end = new \DateTimeImmutable($row['target_end_at'], $utc);
                    if ($end > $now) { ++$pending; continue; }
                    $actual = $this->db->fetchAssociative('SELECT AVG(time) AS actual, COUNT(*) AS samples, MIN(recorded_at) AS first_at, MAX(recorded_at) AS last_at FROM waze_tvt_route_snapshot WHERE route_id = :route AND recorded_at >= :start AND recorded_at < :end AND time IS NOT NULL AND time >= 0', ['route' => (int) $row['route_id'], 'start' => $row['target_start_at'], 'end' => $row['target_end_at']]);
                    $observedSpan = $actual['first_at'] === null ? 0 : (new \DateTimeImmutable($actual['last_at'], $utc))->getTimestamp() - (new \DateTimeImmutable($actual['first_at'], $utc))->getTimestamp();
                    $valid = (int) $actual['samples'] >= $minSamples && $actual['actual'] !== null && $observedSpan >= ($end->getTimestamp() - $start->getTimestamp()) * $span;
                    $error = $valid ? (float) $row['predicted_time'] - (float) $actual['actual'] : null;
                    $hit = $valid ? abs($error) <= max($seconds, (float) $actual['actual'] * $percent / 100) : null;

                    $explanation = null;
                    $causeType = null;
                    if ($valid && $hit === false) {
                        $result = $this->explainer->explain((int) $row['route_id'], $start, $end, $error);
                        $explanation = $result['explanation'];
                        $causeType = $result['cause'];
                    }

                    $this->db->update('waze_tvt_forecast_audit', [
                        'actual_time' => $actual['actual'],
                        'actual_samples' => (int) $actual['samples'],
                        'error_seconds' => $error,
                        'within_tolerance' => $hit === null ? null : (int) $hit,
                        'status' => $valid ? 'evaluated' : 'insufficient',
                        'evaluated_at' => $now->format('Y-m-d H:i:s'),
                        'error_explanation' => $explanation,
                        'error_cause_type' => $causeType,
                    ], ['id' => (int) $row['id']]);

                    if (!$valid) { ++$missing; continue; }
                    $errors[] = $error;
                    if ($hit) { ++$hits; }
                }

                // Libera memória entre lotes
                unset($rows);
                gc_collect_cycles();
            }

            $n = count($errors);
            $io->title(sprintf('TVT forecast audit: %s (%s)', $date, $tz->getName()));
            $io->table(['Metric', 'Value'], [['Captured emissions', $total], ['Evaluated', $n], ['Insufficient observations', $missing], ['Not yet completed', $pending], ['MAE (s)', $n ? round(array_sum(array_map('abs', $errors)) / $n, 2) : 'N/A'], ['RMSE (s)', $n ? round(sqrt(array_sum(array_map(static fn(float $e): float => $e * $e, $errors)) / $n), 2) : 'N/A'], ['Bias predicted - actual (s)', $n ? round(array_sum($errors) / $n, 2) : 'N/A'], ['Within tolerance (%)', $n ? round(100 * $hits / $n, 2) : 'N/A']]);
            $io->note(sprintf('Tolerance: max(%.2f seconds, %.2f%% of actual). Minimum %d samples; observation span >= %.0f%% of window. Negative bias means underestimated travel time. Metrics are per captured emission, not unique route/window. Processed in batches of %d.', $seconds, $percent, $minSamples, 100 * $span, self::BATCH_SIZE));
            if (!$total) { $io->warning('No captured emissions for this date. Run --capture before future target windows. This does not audit the independently calculated six-hour route endpoint.'); }
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } finally { $lock->release(); }
    }
}
