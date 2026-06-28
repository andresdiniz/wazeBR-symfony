<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CemadenDataRepository;
use App\Service\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/cemaden', name: 'cemaden_')]
#[IsGranted('ROLE_USER')]
class CemadenController extends AbstractController
{
    public function __construct(
        private readonly TenantContext         $tenantContext,
        private readonly CemadenDataRepository $cemadenRepo,
        private readonly Connection            $db,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $level   = $request->query->get('level');
        $state   = $request->query->get('state');

        // Dados pluviométricos / meteorológicos (tabela cemaden_data)
        $data = $this->cemadenRepo->findFilteredByPartner(
            partner: $partner,
            alertLevel: $level ?: null,
            state: $state ?: null,
        );

        // Dados hidrológicos: última leitura de cada estação do parceiro
        $hydroData = $this->db->fetchAllAssociative(
            "SELECT
                s.id            AS station_id,
                s.cod_estacao,
                s.nome,
                s.municipio,
                s.uf,
                r.measured_at,
                r.sensor_value,
                r.offset_value,
                r.river_level,
                r.is_offline
             FROM cemaden_stations s
             INNER JOIN cemaden_hydro_readings r
                ON r.station_id = s.id
                AND r.measured_at = (
                    SELECT MAX(r2.measured_at)
                    FROM cemaden_hydro_readings r2
                    WHERE r2.station_id = s.id
                )
             WHERE s.station_type = 'hydrological'
               AND s.is_active    = 1
               AND s.partner_slug = ?
             ORDER BY s.municipio, s.nome",
            [$partner->getSlug()],
        );

        return $this->render('cemaden/index.html.twig', [
            'partner'    => $partner,
            'data'       => $data,
            'hydro_data' => $hydroData,
            'level'      => $level,
            'state'      => $state,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $item    = $this->cemadenRepo->findOneByPartner($id, $partner);

        if (!$item) {
            throw $this->createNotFoundException('Dado CEMADEN não encontrado.');
        }

        return $this->render('cemaden/show.html.twig', [
            'partner' => $partner,
            'item'    => $item,
        ]);
    }
}
