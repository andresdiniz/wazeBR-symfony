<?php
// Standalone assembly checks; run with PHP 8.2+ without Composer.
declare(strict_types=1);

require __DIR__ . '/../src/Repository/RoutesRepository.php';

use App\Repository\RoutesRepository;

$repository = (new ReflectionClass(RoutesRepository::class))->newInstanceWithoutConstructor();
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$structure = [
    'routes' => [
        1 => ['route_name' => 'Rota A', 'from_name' => 'Origem', 'to_name' => 'Destino',
            'line' => [[-20.6, -43.7], [-20.7, -43.8]]],
        2 => ['route_name' => 'Rota removida', 'from_name' => '', 'to_name' => '', 'line' => []],
    ],
    'subroutes' => [1 => [[
        'id' => 10, 'subRouteId' => 'trecho', 'name' => 'Trecho',
        'fromName' => 'A', 'toName' => 'B',
        'line' => [[-20.6, -43.7], [-20.7, -43.8]],
    ]]],
    'irregularities' => [],
];
$row = [
    'id' => 1, 'waze_route_id' => 'external', 'route_name' => 'Rota A',
    'from_name' => 'A', 'to_name' => 'B', 'route_last_seen_at' => '2026-10-27 22:00:00',
    'snapshot_name' => null, 'snapshot_city' => 'Cidade', 'snapshot_state' => 'MG',
    'current_time_seconds' => 120, 'historic_time_seconds' => 100,
    'jam_level' => 2, 'recorded_at' => '2026-10-27 22:00:00',
];
$metrics = [
    'routes' => [$row],
    'subroutes' => [10 => ['time' => 60.0, 'historicTime' => 40.0, 'delaySeconds' => 20, 'jamLevel' => 2]],
];
$first = $repository->assembleDashboard($structure, $metrics);
check(count($first['routes']) === 1, 'Cached inactive route leaked into result');
check($first['routes'][0]['time'] === 120.0, 'Wrong current time');
check(abs($first['routes'][0]['delayRatio'] - 0.2) < 0.000001, 'Wrong delay percentage');
check($first['map']['segments'][0]['time'] === 60.0, 'Wrong live subroute metric');

$metrics['routes'][0]['current_time_seconds'] = 180;
$metrics['subroutes'][10]['time'] = 90.0;
$metrics['subroutes'][10]['delaySeconds'] = 50;
$second = $repository->assembleDashboard($structure, $metrics);
check($second['routes'][0]['time'] === 180.0, 'Travel time frozen by structure');
check(abs($second['routes'][0]['delayRatio'] - 0.8) < 0.000001, 'Percentage frozen by structure');
check($second['routes'][0]['delayLevel'] === 'severe', 'Severity did not update');
check($second['map']['segments'][0]['path'] === $first['map']['segments'][0]['path'], 'Geometry changed');
check($second['map']['segments'][0]['time'] === 90.0, 'Subroute metric did not update');
check($repository->assembleDashboard($structure, $metrics, ['query' => 'inexistente'])['routes'] === [], 'Search filter failed');
check($repository->assembleDashboard($structure, ['routes' => [], 'subroutes' => []])['routes'] === [], 'Empty metrics leaked routes');
echo "OK: assembly, scope intersection, filters and live metrics.\n";
