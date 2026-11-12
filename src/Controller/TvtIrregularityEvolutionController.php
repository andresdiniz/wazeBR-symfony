<?php

declare(strict_types=1);
namespace App\Controller;

use App\Service\PartnerScopeResolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TvtIrregularityEvolutionController extends AbstractController
{
    #[Route('/tvt/irregularities/{id}/evolution', name: 'tvt_irregularities_evolution', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, PartnerScopeResolver $scope, Connection $db): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $partner = $scope->resolve();
        $params = ['id' => $id];
        $where = 'i.id = :id';
        if ($partner !== null) { $where .= ' AND i.partner_id = :partner'; $params['partner'] = $partner->getId(); }
        $item = $db->fetchAssociative("SELECT i.id, i.partner_id, i.is_active, i.recorded_at, i.last_seen_at, i.deactivated_at, i.street, p.name AS partner_name, JSON_UNQUOTE(JSON_EXTRACT(i.payload, '$.name')) AS source_name FROM waze_tvt_irregularity i LEFT JOIN partner p ON p.id = i.partner_id WHERE " . $where, $params);
        if (!$item) throw $this->createNotFoundException('Irregularidade não encontrada.');
        $item['name'] = !empty($item['source_name']) && $item['source_name'] !== 'null' ? $item['source_name'] : ($item['street'] ?: 'Irregularidade #' . $id);
        $ready = $db->createSchemaManager()->tablesExist(['waze_tvt_irregularity_sample']);
        $points = []; $sampleCount = 0; $bucket = 600;
        if ($ready) {
            $queryParams = ['id' => $id, 'partner' => $item['partner_id']];
            $bounds = $db->fetchAssociative('SELECT MIN(observed_at) AS first_at, MAX(observed_at) AS last_at, COUNT(*) AS total FROM waze_tvt_irregularity_sample WHERE irregularity_id = :id AND partner_id = :partner', $queryParams);
            $sampleCount = (int) ($bounds['total'] ?? 0);
            if ($sampleCount > 0) {
                $start = (new \DateTimeImmutable($bounds['first_at'], new \DateTimeZone('UTC')))->getTimestamp();
                $end = (new \DateTimeImmutable($bounds['last_at'], new \DateTimeZone('UTC')))->getTimestamp();
                $bucket = max(600, (int) ceil(max(1,$end-$start) / (480 * 600)) * 600);
                $sql = 'SELECT FLOOR(UNIX_TIMESTAMP(observed_at) / ' . $bucket . ') * ' . $bucket . ' AS bucket_at, AVG(observed_seconds) AS observed, AVG(historic_seconds) AS historic, AVG(delay_seconds) AS delay_value, COUNT(*) AS samples FROM waze_tvt_irregularity_sample WHERE irregularity_id = :id AND partner_id = :partner GROUP BY bucket_at ORDER BY bucket_at ASC';
                foreach ($db->fetchAllAssociative($sql, $queryParams) as $row) $points[(int) $row['bucket_at']] = ['observed' => $row['observed'] !== null ? (float) $row['observed'] / 60 : null, 'historic' => $row['historic'] !== null ? (float) $row['historic'] / 60 : null, 'delay' => $row['delay_value'] !== null ? (float) $row['delay_value'] / 60 : null, 'samples' => (int) $row['samples']];
            }
        }
        $series = [];
        $max = 1.0;
        foreach ($points as $values) foreach (['observed','historic','delay'] as $key) if ($values[$key] !== null) $max = max($max,$values[$key]);
        $max = ceil($max * 1.1);
        $first = $points !== [] ? min(array_keys($points)) : 0;
        $last = $points !== [] ? max(array_keys($points)) : 0;
        $steps = $points !== [] ? (int) (($last-$first)/$bucket) : 0;
        foreach (['observed' => ['Tempo observado','#2563eb'], 'historic' => ['Referência histórica','#64748b'], 'delay' => ['Atraso','#ea580c']] as $key => [$label,$color]) {
            $segments = []; $segment = []; $dots = [];
            for ($n = 0; $n <= $steps && $points !== []; ++$n) {
                $value = $points[$first+$n*$bucket][$key] ?? null;
                if ($value === null) { if ($segment !== []) $segments[] = implode(' ', $segment); $segment = []; continue; }
                $x = 60 + ($steps ? $n/$steps : .5) * 900;
                $y = 270 - $value/$max * 230;
                $xy = number_format($x,2,'.','') . ',' . number_format($y,2,'.','');
                $segment[] = $xy;
                $dots[] = ['x' => $x, 'y' => $y, 'time' => gmdate('c',$first+$n*$bucket), 'value' => $value];
            }
            if ($segment !== []) $segments[] = implode(' ', $segment);
            $series[] = ['label' => $label, 'color' => $color, 'segments' => $segments, 'dots' => $dots];
        }
        $ticks = [];
        for ($n=0; $n<=4; ++$n) $ticks[] = ['y' => 270-$n/4*230, 'value' => $max*$n/4];
        $table = [];
        foreach ($points as $time => $values) $table[] = $values + ['time' => gmdate('c',$time)];
        $response = $this->render('tvt_irregularities/evolution.html.twig', ['item' => $item, 'ready' => $ready, 'sample_count' => $sampleCount, 'bucket_minutes' => $bucket/60, 'points_count' => count($points), 'series' => $series, 'ticks' => $ticks, 'first_at' => $first ? gmdate('c',$first) : null, 'last_at' => $last ? gmdate('c',$last) : null, 'recent_points' => array_slice(array_reverse($table),0,50)]);
        $response->headers->set('Cache-Control','private, no-store');
        return $response;
    }
}
