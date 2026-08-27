<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\WazeAlertRepository;
use App\Repository\WazeAlertTypeRepository;
use App\Service\TenantContext;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/alertas', name: 'alert_')]
#[IsGranted('ROLE_USER')]
class AlertController extends AbstractController
{
    private const PERIOD_PRESETS = [
        'today' => ['label' => 'Hoje', 'days' => 0],
        'yesterday' => ['label' => 'Ontem', 'days' => 1],
        'week' => ['label' => '7 dias', 'days' => 7],
        'month' => ['label' => '30 dias', 'days' => 30],
        'six_months' => ['label' => '6 meses', 'days' => 182],
        'year' => ['label' => '1 ano', 'days' => 365],
    ];

    private const ROAD_TYPES = [
        1 => 'Rua local',
        2 => 'Via primária',
        3 => 'Rodovia',
        4 => 'Via rápida',
        5 => 'Freeway',
        6 => 'Passagem municipal',
        7 => 'Via secundária',
        8 => 'Trilha / Caminho',
        14 => 'Rua 4x4',
        15 => 'Via de pedestres',
        17 => 'Private road',
    ];

    private const EXPORT_ROW_LIMIT = 200000;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WazeAlertRepository $alertRepo,
        private readonly WazeAlertTypeRepository $alertTypeRepo,
    ) {
    }

    private function filtersFromRequest(Request $request): array
    {
        $filters = [
            'type' => $request->query->get('type') ?: null,
            'subtype' => $request->query->get('subtype') ?: null,
            'city' => $request->query->get('city') ?: null,
            'street' => $request->query->get('street') ?: null,
            'excludeStreet' => $request->query->get('excludeStreet') ?: null,
            'dateFrom' => $request->query->get('dateFrom') ?: null,
            'dateTo' => $request->query->get('dateTo') ?: null,
        ];

        $period = $request->query->get('period');

        if (
            $period
            && isset(self::PERIOD_PRESETS[$period])
            && !$filters['dateFrom']
            && !$filters['dateTo']
        ) {
            $timezone = new DateTimeZone('America/Sao_Paulo');
            $now = new DateTimeImmutable('now', $timezone);
            $days = self::PERIOD_PRESETS[$period]['days'];

            $start = $days === 0
                ? $now->setTime(0, 0, 0)
                : $now->modify(sprintf('-%d days', $days))->setTime(0, 0, 0);

            $end = $period === 'yesterday'
                ? $now->modify('-1 day')->setTime(23, 59, 59)
                : $now->setTime(23, 59, 59);

            $filters['dateFrom'] = $start->format('Y-m-d H:i:s');
            $filters['dateTo'] = $end->format('Y-m-d H:i:s');
        }

        return $filters;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $locale = $request->getLocale() ?: 'pt';
        $filters = $this->filtersFromRequest($request);
        $period = $request->query->get('period');
        $hourlyTrend = in_array($period, ['today', 'yesterday'], true);
        $page = max(1, (int) $request->query->get('page', 1));

        $result = $this->alertRepo->findFilteredByPartner(
            $partner,
            $filters,
            $page,
            30,
        );

        $bySubtype = array_map(
            static fn (array $row): array => [
                'label' => $row['subtype'] ?: 'Sem subtipo',
                'total' => (int) $row['total'],
            ],
            $this->alertRepo->countBySubtypeFiltered($partner, $filters, 10),
        );

        $byDay = $this->alertRepo->countByDayFiltered($partner, $filters);
        $byHour = $this->alertRepo->countByHourOfDayFiltered($partner, $filters);

        $byHourTrend = $hourlyTrend
            ? array_map(
                static fn (int $hour, int $total): array => [
                    'hour_label' => sprintf('%02dh', $hour),
                    'total' => $total,
                ],
                array_keys($byHour),
                $byHour,
            )
            : $byDay;

        return $this->render('alert/index.html.twig', [
            'partner' => $partner,
            'alerts' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => $result['pages'],
            'types' => $this->alertRepo->findDistinctTypes($partner),
            'subtypes' => $this->alertRepo->findDistinctSubtypes($partner, $filters['type']),
            'cities' => $this->alertRepo->findDistinctCities($partner),
            'streets' => $this->alertRepo->findDistinctStreets($partner),
            'bySubtype' => $bySubtype,
            'byConfidence' => $this->alertRepo->countByConfidenceFiltered($partner, $filters),
            'byDay' => $byDay,
            'byHour' => $byHour,
            'byHourTrend' => $byHourTrend,
            'trendType' => $hourlyTrend ? 'hour' : 'day',
            'byWeekday' => $this->alertRepo->countByWeekdayFiltered($partner, $filters),
            'topStreets' => $this->alertRepo->topStreetsFiltered($partner, $filters, 10),
            'hotspots' => $this->alertRepo->findHotspotsFiltered($partner, $filters, 15),
            'mapAlerts' => $this->alertRepo->findForMapFiltered($partner, $filters, 500),
            'type' => $filters['type'],
            'subtype' => $filters['subtype'],
            'city' => $filters['city'],
            'street' => $filters['street'],
            'excludeStreet' => $filters['excludeStreet'],
            'period' => $period,
            'periods' => self::PERIOD_PRESETS,
            'dateFrom' => $filters['dateFrom'],
            'dateTo' => $filters['dateTo'],
            'typesMap' => $this->alertTypeRepo->getTypesMap($locale),
            'subtypesMap' => $this->alertTypeRepo->getSubtypesMap($locale),
        ]);
    }

    #[Route('/ao-vivo', name: 'live', methods: ['GET'])]
    public function live(): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $alerts = $this->alertRepo->findActiveByPartner($partner, 10);

        return $this->render('alert/live.html.twig', [
            'partner' => $partner,
            'alerts' => $alerts,
            'total' => count($alerts),
            'typesMap' => $this->alertTypeRepo->getTypesMap('pt'),
        ]);
    }

    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $locale = $request->getLocale() ?: 'pt';
        $filters = $this->filtersFromRequest($request);
        $typesMap = $this->alertTypeRepo->getTypesMap($locale);
        $subtypesMap = $this->alertTypeRepo->getSubtypesMap($locale);
        $timezone = new DateTimeZone('America/Sao_Paulo');
        $limit = self::EXPORT_ROW_LIMIT;
        $alertRepo = $this->alertRepo;

        $response = new StreamedResponse(
            static function () use (
                $alertRepo,
                $partner,
                $filters,
                $typesMap,
                $subtypesMap,
                $timezone,
                $limit,
            ): void {
                $out = fopen('php://output', 'w');

                if ($out === false) {
                    throw new \RuntimeException('Não foi possível abrir a saída do CSV.');
                }

                fwrite($out, "\xEF\xBB\xBF");

                fputcsv($out, [
                    'ID',
                    'Tipo',
                    'Subtipo',
                    'Via',
                    'Cidade',
                    'País',
                    'Tipo de via',
                    'Publicado',
                    'Confiabilidade',
                    'Confiança',
                    'Avaliação',
                    'Curtidas',
                    'Comentários',
                    'Descrição do relato',
                    'UUID Waze',
                    'Latitude',
                    'Longitude',
                ], ';');

                foreach ($alertRepo->iterateFilteredByPartnerForExport($partner, $filters, $limit) as $alert) {
                    $roadType = $alert->getRoadType();
                    $pubDate = $alert->getPubDate();

                    fputcsv($out, [
                        $alert->getId(),
                        $typesMap[$alert->getType()] ?? $alert->getType(),
                        $alert->getSubtype()
                            ? ($subtypesMap[$alert->getType() . '|' . $alert->getSubtype()] ?? $alert->getSubtype())
                            : '',
                        $alert->getStreet(),
                        $alert->getCity(),
                        $alert->getCountry(),
                        $roadType !== null
                            ? (self::ROAD_TYPES[$roadType] ?? ('Código ' . $roadType))
                            : '',
                        $pubDate ? $pubDate->setTimezone($timezone)->format('d/m/Y H:i:s') : '',
                        $alert->getReliability(),
                        $alert->getConfidence(),
                        $alert->getReportRating(),
                        $alert->getNThumbsUp(),
                        $alert->getNComments(),
                        $alert->getReportDescription(),
                        $alert->getWazeId(),
                        $alert->getLatitude(),
                        $alert->getLongitude(),
                    ], ';');
                }

                fclose($out);
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => sprintf(
                    'attachment; filename="%s"',
                    $this->exportFilename($filters, $request->query->get('period')),
                ),
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ],
        );

        if ($this->alertRepo->countFilteredByPartner($partner, $filters) > $limit) {
            $response->headers->set('X-Export-Truncated', (string) $limit);
        }

        $token = preg_replace(
            '/[^a-zA-Z0-9_-]/',
            '',
            (string) $request->query->get('dlToken'),
        );

        if ($token) {
            $response->headers->setCookie(
                Cookie::create('dl_' . $token, '1', time() + 60, '/'),
            );
        }

        return $response;
    }

    private function exportFilename(array $filters, ?string $period): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $suffix = 'todos';

        if ($period && isset(self::PERIOD_PRESETS[$period])) {
            $suffix = (string) preg_replace(
                '/[^a-z0-9]+/i',
                '-',
                mb_strtolower(self::PERIOD_PRESETS[$period]['label']),
            );
        } elseif (!empty($filters['dateFrom']) || !empty($filters['dateTo'])) {
            $from = $filters['dateFrom'] ? substr($filters['dateFrom'], 0, 10) : 'inicio';
            $to = $filters['dateTo'] ? substr($filters['dateTo'], 0, 10) : 'hoje';
            $suffix = $from . '_a_' . $to;
        }

        return sprintf('alertas_%s_%s.csv', $now->format('Y-m-d_His'), $suffix);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $partner = $this->tenantContext->requirePartner();

        $alert = $this->alertRepo->findOneByIdAndPartner($id, $partner);

        if (!$alert) {
            throw $this->createNotFoundException('Alerta não encontrado.');
        }

        return $this->render('alert/show.html.twig', [
            'partner' => $partner,
            'alert' => $alert,
            'typesMap' => $this->alertTypeRepo->getTypesMap('pt'),
            'subtypesMap' => $this->alertTypeRepo->getSubtypesMap('pt'),
        ]);
    }
}
