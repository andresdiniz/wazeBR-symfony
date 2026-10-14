<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\Partner;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/routes/{id}/forecast.json', name: 'route_forecast_')]
final class RouteForecastController extends AbstractController
{
    public function __construct(private readonly Connection $connection) {}

    #[Route('', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $partner = $this->resolvePartnerScope();
        if (!$this->routeExists($id, $partner)) {
            throw $this->createNotFoundException('Rota não encontrada.');
        }
        $tz = 'America/Sao_Paulo';
        $now = new \DateTimeImmutable('now', new \DateTimeZone($tz));
        $items = [];
        for ($i = 1; $i <= 6; $i++) {
            $slot = $now->modify("+{$i} hours");
            $at = $slot->setTime((int)$slot->format('G'), 0, 0);
            $row = $this->connection->fetchAssociative(
                "SELECT AVG(s.time) a, AVG(s.historic_time) h, AVG(s.jam_level) j, COUNT(*) c
                 FROM waze_tvt_route_snapshot s
                 WHERE s.route_id = :id AND s.time IS NOT NULL
                 AND s.recorded_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
                 AND WEEKDAY(CONVERT_TZ(s.recorded_at,'UTC',:tz)) = :w
                 AND HOUR(CONVERT_TZ(s.recorded_at,'UTC',:tz)) = :h",
                ['id'=>$id,'tz'=>$tz,'w'=>((int)$at->format('w') + 6) % 7,'h'=>(int)$at->format('G')]
            );
            $t = $row['a'] !== null ? (float)$row['a'] : null;
            $ht = $row['h'] !== null ? (float)$row['h'] : null;
            $j = $row['j'] !== null ? (float)$row['j'] : null;
            $c = (int)($row['c'] ?? 0);
            [$s,$l] = $this->risk($t,$ht,$j,$c);
            $items[] = ['forecastAt'=>$at->format(DATE_ATOM),'hour'=>(int)$at->format('G'),'avgTime'=>$t!==null?round($t,1):null,'avgHistoricTime'=>$ht!==null?round($ht,1):null,'avgJamLevel'=>$j!==null?round($j,2):null,'samples'=>$c,'riskScore'=>round($s,1),'riskLevel'=>$l];
        }
        return $this->json(['ok'=>true,'routeId'=>$id,'generatedAt'=>$now->format(DATE_ATOM),'items'=>$items]);
    }

    private function routeExists(int $id, ?Partner $p): bool
    {
        $q = "SELECT id FROM waze_tvt_route WHERE id = :id AND is_active = 1";
        $a = ['id'=>$id];
        if ($p !== null) { $q .= " AND partner_id = :pid"; $a['pid'] = $p->getId(); }
        return $this->connection->fetchAssociative($q." LIMIT 1", $a) !== false;
    }

    private function risk(?float $t, ?float $h, ?float $j, int $c): array
    {
        if ($c < 1) return [0.0,'low'];
        $sl = ($t!==null && $h!==null && $h>0) ? max(0, (($t-$h)/$h)*100) : 0;
        $s = min(100, $sl*1.5 + max(0, ($j ?? 0)-1)*15);
        $l = $s>=75?'critical':($s>=55?'high':($s>=30?'medium':'low'));
        return [$s,$l];
    }

    private function resolvePartnerScope(): ?Partner
    {
        $u = $this->getUser();
        return ($u instanceof User && !$u->isGlobalAdmin()) ? $u->getPartner() : null;
    }
}
