<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CemadenDataRepository;
use App\Repository\CemadenHydroDataRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/cemaden', name: 'cemaden_')]
#[IsGranted('ROLE_USER')] // ← apenas usuário logado, sem ROLE_CEMADEN
class CemadenController extends AbstractController
{
    public function __construct(
        private readonly TenantContext             $tenantContext,
        private readonly CemadenDataRepository      $cemadenRepo,
        private readonly CemadenHydroDataRepository $hydroRepo,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $partner = $this->tenantContext->requirePartner();
        $level   = $request->query->get('level');
        $state   = $request->query->get('state');

        // Dados pluviométricos
        $data = $this->cemadenRepo->findFilteredByPartner(
            partner: $partner,
            alertLevel: $level ?: null,
            state: $state ?: null,
        );

        // Dados hidrológicos (última leitura de cada estação).
        //
        // NOTA: aqui antes tinha uma query SQL bruta contra
        // cemaden_stations / cemaden_hydro_readings — tabelas que não
        // existem no schema atual (o modelo real é a tabela única
        // cemaden_hydro_data, mapeada pela entidade CemadenHydroData).
        // Isso fazia essa seção da página quebrar com erro de SQL toda
        // vez que carregava. Trocado pelo método que já existe e já é
        // usado em /hidrologico/live para o mesmo propósito.
        $hydroData = $this->hydroRepo->findLatestByPartner($partner);

        return $this->render('cemaden/index.html.twig', [
            'partner'    => $partner,
            'data'       => $data,
            'hydro_data' => $hydroData,
            'level'      => $level,
            'state'      => $state,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'])]
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

