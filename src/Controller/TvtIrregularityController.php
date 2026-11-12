<?php

declare(strict_types=1);
namespace App\Controller;

use App\Service\OptionalIntegerFilter;
use App\Service\PartnerScopeResolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class TvtIrregularityController extends AbstractController
{
    #[Route('/tvt/irregularities', name: 'tvt_irregularities_index', methods: ['GET'])]
    public function index(Request $request, PartnerScopeResolver $scope, Connection $db, CacheInterface $cache): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $partner = $scope->resolve();
        $query = $request->query->all();
        $partnerValue = $query['partner'] ?? null;
        if (is_string($partnerValue) && trim($partnerValue) === '') $partnerValue = null;
        $filters = [
            'q' => mb_substr(trim((string) $request->query->get('q', '')), 0, 120),
            'status' => (string) $request->query->get('status', 'active'),
            'period' => (string) $request->query->get('period', '7d'),
            'level' => (string) $request->query->get('level', 'all'),
            'partner' => $partner !== null ? $partner->getId() : OptionalIntegerFilter::parse($partnerValue, 0, 0, 'partner'),
            'per_page' => OptionalIntegerFilter::parse($query['per_page'] ?? null, 25, 1, 'per_page'),
        ];
        if (!in_array($filters['status'], ['active', 'inactive', 'all'], true)) $filters['status'] = 'active';
        if (!in_array($filters['period'], ['24h', '7d', '30d', 'all'], true)) $filters['period'] = '7d';
        if (!in_array($filters['level'], ['all', '0', '1', '2', '3', '4', '5'], true)) $filters['level'] = 'all';
        if (!in_array($filters['per_page'], [10,25,50,100], true)) $filters['per_page'] = 25;
        $requestedPage = min(1000000, OptionalIntegerFilter::parse($query['page'] ?? null, 1, 1, 'page'));
        $anchor = intdiv(time(), 120) * 120;
        $compute = function () use ($db, $filters, $requestedPage, $anchor): array {
            $where = ['1=1']; $params = [];
            if ($filters['partner'] > 0) { $where[] = 'i.partner_id = :partner'; $params['partner'] = $filters['partner']; }
            if ($filters['status'] !== 'all') { $where[] = 'i.is_active = :active'; $params['active'] = $filters['status'] === 'active' ? 1 : 0; }
            $hours = ['24h' => 24, '7d' => 168, '30d' => 720][$filters['period']] ?? null;
            if ($hours !== null) { $where[] = 'i.last_seen_at >= :since'; $params['since'] = gmdate('Y-m-d H:i:s', $anchor - $hours * 3600); }
            if ($filters['level'] !== 'all') { $where[] = "JSON_UNQUOTE(JSON_EXTRACT(i.payload, '$.jamLevel')) = :level"; $params['level'] = $filters['level']; }
            if ($filters['q'] !== '') {
                $where[] = "(i.street LIKE :q ESCAPE '!' OR i.city LIKE :q ESCAPE '!' OR JSON_UNQUOTE(JSON_EXTRACT(i.payload, '$.name')) LIKE :q ESCAPE '!')";
                $params['q'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']) . '%';
            }
            $condition = implode(' AND ', $where);
            $total = (int) $db->fetchOne('SELECT COUNT(*) FROM waze_tvt_irregularity i WHERE ' . $condition, $params);
            $pages = max(1, (int) ceil($total / $filters['per_page']));
            $page = min($requestedPage, $pages); $offset = ($page - 1) * $filters['per_page'];
            $sql = "SELECT i.id, i.partner_id, p.name AS partner_name, i.route_id, i.type, i.street, i.city, i.is_active,
                i.recorded_at, i.last_seen_at, i.deactivated_at,
                JSON_UNQUOTE(JSON_EXTRACT(i.payload, '$.name')) AS source_name,
                JSON_UNQUOTE(JSON_EXTRACT(i.payload, '$.time')) AS observed_seconds,
                JSON_UNQUOTE(JSON_EXTRACT(i.payload, '$.historicTime')) AS historic_seconds,
                JSON_UNQUOTE(JSON_EXTRACT(i.payload, '$.jamLevel')) AS jam_level,
                JSON_UNQUOTE(JSON_EXTRACT(i.payload, '$.length')) AS length_meters,
                JSON_EXTRACT(i.payload, '$.line') AS geometry_json
                FROM waze_tvt_irregularity i LEFT JOIN partner p ON p.id = i.partner_id
                WHERE $condition ORDER BY i.last_seen_at DESC, i.id DESC LIMIT " . $filters['per_page'] . ' OFFSET ' . $offset;
            $rows = $db->fetchAllAssociative($sql, $params);
            foreach ($rows as &$row) {
                $row['name'] = !empty($row['source_name']) && $row['source_name'] !== 'null' ? $row['source_name'] : ($row['street'] ?: 'Trecho não informado');
                $row['observed'] = is_numeric($row['observed_seconds']) && (float) $row['observed_seconds'] > 0 ? (float) $row['observed_seconds'] : null;
                $row['historic'] = is_numeric($row['historic_seconds']) && (float) $row['historic_seconds'] > 0 ? (float) $row['historic_seconds'] : null;
                $row['delay'] = $row['observed'] !== null && $row['historic'] !== null ? max(0, $row['observed'] - $row['historic']) : null;
                $row['level'] = in_array((string) $row['jam_level'], ['0','1','2','3','4','5'], true) ? (int) $row['jam_level'] : null;
                $line = is_string($row['geometry_json']) ? json_decode($row['geometry_json'], true) : null;
                unset($row['geometry_json']);
                $row['segments'] = []; $segment = []; $firstPoint = null;
                if (is_array($line) && array_is_list($line)) foreach ($line as $point) {
                    $valid = is_array($point) && is_numeric($point['x'] ?? null) && is_numeric($point['y'] ?? null);
                    if ($valid) {
                        $lng = (float) $point['x']; $lat = (float) $point['y'];
                        $valid = is_finite($lng) && is_finite($lat) && abs($lng) <= 180 && abs($lat) <= 90;
                    }
                    if (!$valid) {
                        if ($segment !== []) $row['segments'][] = $segment;
                        $segment = [];
                        continue;
                    }
                    $segment[] = [$lat, $lng];
                    $firstPoint ??= [$lat, $lng];
                }
                if ($segment !== []) $row['segments'][] = $segment;
                $row['waze_url'] = $firstPoint === null ? null : 'https://www.waze.com/ul?' . http_build_query(['ll' => number_format($firstPoint[0],7,'.','') . ',' . number_format($firstPoint[1],7,'.',''), 'z' => 16], '', '&', PHP_QUERY_RFC3986);
            }
            unset($row);
            return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'first' => $total ? $offset + 1 : 0, 'last' => min($offset + $filters['per_page'], $total), 'loaded_at' => gmdate('Y-m-d H:i:s')];
        };
        $data = $filters['q'] !== '' ? $compute() : $cache->get('tvt_irregularities_list_map_v3_' . hash('sha256', json_encode([$filters, $requestedPage, $anchor], JSON_THROW_ON_ERROR)), function (ItemInterface $item) use ($compute): array { $item->expiresAfter(120); return $compute(); });
        $response = $this->render('tvt_irregularities/index.html.twig', $data + ['filters' => $filters, 'partner_locked' => $partner !== null]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
