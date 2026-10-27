<?php
declare(strict_types=1);
namespace App\Service;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
final class ForecastErrorExplainerService
{
    public function __construct(private readonly Connection $connection, private readonly LoggerInterface $logger) {}
    public function explain(int $routeId, \DateTimeImmutable $start, \DateTimeImmutable $end, float $errorSeconds): array
    {
        $utc = new \DateTimeZone('UTC');
        $params = ['route' => $routeId, 'start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'), 'end' => $end->setTimezone($utc)->format('Y-m-d H:i:s')];
        $parts = []; $causes = []; $failed = false; $weatherAvailable = false;
        try {
            $rows = $this->connection->fetchAllAssociative('SELECT i.type, i.subtype, i.street FROM waze_tvt_irregularity i INNER JOIN waze_tvt_route r ON r.id = i.route_id AND r.partner_id = i.partner_id WHERE i.route_id = :route AND i.recorded_at < :end AND i.last_seen_at >= :start ORDER BY i.recorded_at DESC LIMIT 3', $params);
            foreach ($rows as $row) $parts[] = 'Irregularidade TVT observada: ' . implode(' / ', array_filter([$row['type'], $row['subtype'], $row['street']]));
            if ($rows !== []) $causes[] = 'alerta_waze';
        } catch (\Throwable $e) { $failed = true; $this->logger->warning('Forecast context: irregularities unavailable', ['route' => $routeId, 'error' => $e->getMessage()]); }
        try {
            $weather = $this->connection->fetchAssociative('SELECT COUNT(*) AS n, MAX(o.precipitation) AS rain, MAX(o.wind_speed) AS wind FROM weather_observation o INNER JOIN waze_tvt_route r ON r.partner_id = o.partner_id WHERE r.id = :route AND o.observed_at >= :start AND o.observed_at < :end', $params);
            $weatherAvailable = (int) ($weather['n'] ?? 0) > 0;
            if ($weatherAvailable && $weather['rain'] !== null && (float) $weather['rain'] > 2) { $parts[] = sprintf('Maior precipitacao observada no parceiro: %.1f mm; nao localizada necessariamente na rota', (float) $weather['rain']); $causes[] = 'clima'; }
            if ($weatherAvailable && $weather['wind'] !== null && (float) $weather['wind'] > 30) { $parts[] = sprintf('Maior vento observado no parceiro: %.0f km/h', (float) $weather['wind']); $causes[] = 'clima'; }
        } catch (\Throwable $e) { $failed = true; $this->logger->warning('Forecast context: weather unavailable', ['route' => $routeId, 'error' => $e->getMessage()]); }
        $direction = $errorSeconds >= 0 ? 'acima' : 'abaixo';
        $prefix = sprintf('Previsao %.0f s %s do medido.', abs($errorSeconds), $direction);
        if (!$weatherAvailable) $parts[] = 'Sem observacoes meteorologicas disponiveis na janela.';
        if ($causes === []) $parts[] = 'Nenhum sinal relevante encontrado nos dados consultados; isso nao confirma ausencia de incidentes.';
        if ($failed) $parts[] = 'Uma das consultas de contexto falhou.';
        $parts[] = 'Contexto associado, nao causa comprovada.';
        return ['cause' => $causes[0] ?? ($failed ? 'indisponivel' : 'sem_causa_identificada'), 'explanation' => $prefix . ' ' . implode(' ', $parts)];
    }
}
