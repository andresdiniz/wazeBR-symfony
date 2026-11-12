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
