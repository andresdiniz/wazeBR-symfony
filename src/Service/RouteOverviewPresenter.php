<?php

declare(strict_types=1);

namespace App\Service;

final class RouteOverviewPresenter
{
    public function present(array $payload): array
    {
        $incidentsByRoute = [];
        foreach ($payload['map']['irregularities'] ?? [] as $incident) {
            $incidentsByRoute[(int) ($incident['routeId'] ?? 0)][] = $incident;
        }

        $pathsByRoute = [];
        foreach ($payload['map']['segments'] ?? [] as $segment) {
            $routeId = (int) ($segment['routeId'] ?? 0);
            $path = $segment['path'] ?? [];
            if ($path !== [] && !isset($pathsByRoute[$routeId])) {
                $pathsByRoute[$routeId] = $path;
            }
        }

        $routes = [];
        foreach ($payload['routes'] ?? [] as $route) {
            $id = (int) ($route['id'] ?? 0);
            $allIncidents = $incidentsByRoute[$id] ?? [];
            $routes[] = $route + [
                'statusClass' => (string) ($route['delayLevel'] ?? 'none'),
                'statusLabel' => (string) ($route['delayLabel'] ?? 'Sem dados'),
                'timeLabel' => $this->formatDuration($route['time'] ?? null),
                'historicLabel' => $this->formatDuration($route['historicTime'] ?? null),
                'delayLabel' => $this->formatDelay($route['delaySeconds'] ?? null),
                'delayPercent' => $this->formatPercent($route['delayRatio'] ?? null),
                'trend' => 'stable',
                'incidents' => array_slice($allIncidents, 0, 3),
                'incidentCount' => count($allIncidents),
                'miniMapPath' => $pathsByRoute[$id] ?? [],
            ];
        }

        return ['routes' => $routes, 'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)];
    }

    /**
     * Trajeto de cada rota (já simplificado) para o mini-mapa dos cartões.
     *
     * Prefere os trechos (sub-rotas), que vêm coloridos por nível; sem trechos, usa o traçado da rota.
     *
     * @param array<string, mixed> $payload resultado de RoutesRepository::assembleDashboard()
     * @param list<int>            $routeIds rotas que terão cartão
     *
     * @return array<int, list<array{path: list<array{0: float, 1: float}>, level: string}>>
     */
    public function segmentsByRoute(array $payload, array $routeIds): array
    {
        $wanted = array_flip(array_map('intval', $routeIds));
        $main = [];
        $subs = [];

        foreach ($payload['map']['segments'] ?? [] as $segment) {
            $routeId = (int) ($segment['routeId'] ?? 0);
            $path = $segment['path'] ?? [];
            if (!isset($wanted[$routeId]) || !is_array($path) || count($path) < 2) {
                continue;
            }

            $item = [
                'path' => $this->simplifyPath($path, 80),
                'level' => (string) ($segment['delayLevel'] ?? 'none'),
            ];
            if (($segment['source'] ?? null) === 'subroute') {
                $subs[$routeId][] = $item;
            } else {
                $main[$routeId][] = $item;
            }
        }

        $result = [];
        foreach (array_keys($wanted) as $routeId) {
            $result[$routeId] = $subs[$routeId] ?? $main[$routeId] ?? [];
        }

        return $result;
    }

    /**
     * Reduz a quantidade de pontos (mantendo o primeiro e o último) e arredonda as coordenadas,
     * para o cartão não carregar a geometria completa da rota no HTML.
     *
     * @param array<int, mixed> $path
     *
     * @return list<array{0: float, 1: float}>
     */
    private function simplifyPath(array $path, int $maxPoints): array
    {
        $path = array_values($path);
        $count = count($path);
        $step = $count > $maxPoints ? (int) ceil($count / $maxPoints) : 1;

        $out = [];
        foreach ($path as $index => $point) {
            if (!is_array($point) || !isset($point[0], $point[1])) {
                continue;
            }
            if ($index % $step === 0 || $index === $count - 1) {
                $out[] = [round((float) $point[0], 5), round((float) $point[1], 5)];
            }
        }

        return $out;
    }

    private function formatDuration(mixed $seconds): ?string
    {
        if ($seconds === null || (float) $seconds < 0) { return null; }
        $total = (int) round((float) $seconds);
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        return $hours > 0 ? sprintf('%dh %02dm', $hours, $minutes) : sprintf('%d min', $minutes);
    }

    private function formatDelay(mixed $seconds): ?string
    {
        if ($seconds === null || (int) $seconds <= 0) { return null; }
        $minutes = (int) round(((int) $seconds) / 60);
        return $minutes >= 1 ? sprintf('+%d min', $minutes) : sprintf('+%ds', (int) $seconds);
    }

    private function formatPercent(mixed $ratio): ?string
    {
        if ($ratio === null || (float) $ratio <= 0) { return null; }
        return sprintf('+%d%%', (int) round(((float) $ratio) * 100));
    }
}
