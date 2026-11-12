<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DashboardChangeController extends AbstractController
{
    #[Route('/dashboard/api/change', name: 'dashboard_api_change', methods: ['GET'])]
    public function __invoke(Request $request, Connection $connection, CacheInterface $cache): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $user = $this->getUser();
        if (!$user instanceof User || (!$user->isGlobalAdmin() && $user->getPartner() === null)) {
            throw $this->createAccessDeniedException();
        }
        $partnerId = $user->isGlobalAdmin() ? null : $user->getPartner()->getId();
        $selected = $request->query->getInt('partner', 0);
        if ($selected > 0) {
            if (!$user->isGlobalAdmin() && $selected !== $partnerId) {
                throw $this->createAccessDeniedException();
            }
            $partnerId = $selected;
        }
        $minutes = $request->query->getInt('comparison_minutes', 30);
        if (!in_array($minutes, [30, 60, 180], true)) $minutes = 30;
        $city = mb_substr(trim((string) $request->query->get('city', '')), 0, 200);
        $type = (string) $request->query->get('type', 'all');
        $reason = null;
        if (!in_array($type, ['all', 'routes'], true)) $reason = 'Comparação de rotas fora do tipo selecionado.';
        if (trim((string) $request->query->get('query', '')) !== '' || trim((string) $request->query->get('exclude_streets', '')) !== '') {
            $reason = 'Comparação indisponível com busca livre ou exclusão de vias: esses filtros ainda não são suportados pelo histórico de rotas.';
        }
        if ($reason !== null) {
            return $this->json(['available' => false, 'reason' => $reason], 200, ['Cache-Control' => 'private, no-store']);
        }
        $end = intdiv(time(), 120) * 120;
        $key = 'dashboard_change_v1_' . hash('sha256', json_encode([$partnerId, $city, $minutes, $end], JSON_THROW_ON_ERROR));
        $data = $cache->get($key, function (ItemInterface $item) use ($connection, $partnerId, $city, $minutes, $end): array {
            $item->expiresAfter(120);
            $middle = $end - $minutes * 60;
            $start = $middle - $minutes * 60;
            $params = ['start' => gmdate('Y-m-d H:i:s', $start), 'middle' => gmdate('Y-m-d H:i:s', $middle), 'end' => gmdate('Y-m-d H:i:s', $end)];
            $where = ['s.recorded_at >= :start', 's.recorded_at < :end', 's.time > 0', 's.historic_time > 0', 'r.is_active = 1'];
            if ($partnerId !== null) {
                $where[] = 's.partner_id = :partner';
                $params['partner'] = $partnerId;
            }
            if ($city !== '') {
                $where[] = 's.city = :city';
                $params['city'] = $city;
            }
            $sql = "SELECT s.route_id, MAX(r.name) AS name,
                CASE WHEN s.recorded_at < :middle THEN 0 ELSE 1 END AS window_id,
                COUNT(*) AS samples,
                COUNT(DISTINCT FLOOR(UNIX_TIMESTAMP(s.recorded_at) / 600)) AS buckets,
                AVG(GREATEST(s.time - s.historic_time, 0)) AS delay_seconds,
                MAX(s.recorded_at) AS last_observed_at
                FROM waze_tvt_route_snapshot s
                INNER JOIN waze_tvt_route r ON r.id = s.route_id AND r.partner_id = s.partner_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY s.route_id, window_id";
            $rows = $connection->executeQuery($sql, $params)->fetchAllAssociative();
            $pairs = [];
            foreach ($rows as $row) $pairs[(int) $row['route_id']][(int) $row['window_id']] = $row;
            $routes = [];
            $worse = $better = $stable = 0;
            $previousTotal = $currentTotal = 0.0;
            $latest = null;
            foreach ($pairs as $id => $pair) {
                if (!isset($pair[0], $pair[1]) || (int) $pair[0]['buckets'] < 2 || (int) $pair[1]['buckets'] < 2) continue;
                $previous = (float) $pair[0]['delay_seconds'];
                $current = (float) $pair[1]['delay_seconds'];
                $delta = $current - $previous;
                $status = $delta > 1 ? 'worse' : ($delta < -1 ? 'better' : 'stable');
                if ($status === 'worse') ++$worse;
                elseif ($status === 'better') ++$better;
                else ++$stable;
                $previousTotal += $previous;
                $currentTotal += $current;
                $observed = (string) $pair[1]['last_observed_at'];
                if ($latest === null || $observed > $latest) $latest = $observed;
                $routes[] = ['id' => $id, 'name' => $pair[1]['name'] ?: 'Rota ' . $id, 'previous' => round($previous, 1), 'current' => round($current, 1), 'delta' => round($delta, 1), 'percent' => $previous > 0 ? round($delta / $previous * 100, 1) : null, 'status' => $status, 'previous_samples' => (int) $pair[0]['samples'], 'current_samples' => (int) $pair[1]['samples']];
            }
            usort($routes, static fn (array $a, array $b): int => ($b['delta'] <=> $a['delta']) ?: ($a['id'] <=> $b['id']));
            $count = count($routes);
            return ['available' => $count > 0, 'reason' => $count > 0 ? null : 'Sem rotas com observações em pelo menos dois intervalos distintos de 10 minutos em cada janela.', 'minutes' => $minutes, 'previous_from' => gmdate(DATE_ATOM, $start), 'current_from' => gmdate(DATE_ATOM, $middle), 'current_to' => gmdate(DATE_ATOM, $end), 'generated_at' => gmdate(DATE_ATOM), 'last_observed_at' => $latest !== null ? str_replace(' ', 'T', $latest) . '+00:00' : null, 'comparable' => $count, 'excluded' => count($pairs) - $count, 'worse' => $worse, 'better' => $better, 'stable' => $stable, 'previous_avg' => $count ? round($previousTotal / $count, 1) : null, 'current_avg' => $count ? round($currentTotal / $count, 1) : null, 'delta_avg' => $count ? round(($currentTotal - $previousTotal) / $count, 1) : null, 'routes' => array_slice($routes, 0, 10)];
        });
        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'private, must-revalidate, max-age=0');
        $response->setEtag(hash('sha256', (string) $response->getContent()));
        $response->isNotModified($request);
        return $response;
    }
}
