<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\PartnerRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD de estações CEMADEN via SQL direto (tabela cemaden_stations).
 * Agrupado por partner_slug na listagem.
 */
#[Route('/admin/stations', name: 'admin_station_')]
#[IsGranted('ROLE_ADMIN')]
class MonitoredStationAdminController extends AbstractController
{
    public function __construct(
        private readonly Connection        $db,
        private readonly PartnerRepository $partnerRepo,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM cemaden_stations ORDER BY partner_slug ASC, municipio ASC'
        );

        $byPartner = [];
        foreach ($rows as $r) {
            $byPartner[$r['partner_slug']][] = $r;
        }

        return $this->render('admin/station/index.html.twig', [
            'by_partner' => $byPartner,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $partners = $this->partnerRepo->findActivePartners();

        if ($request->isMethod('POST')) {
            $this->db->insert('cemaden_stations', [
                'cod_estacao'  => $request->request->get('cod_estacao'),
                'nome'         => $request->request->get('nome'),
                'municipio'    => $request->request->get('municipio'),
                'uf'           => strtoupper($request->request->get('uf')),
                'partner_slug' => $request->request->get('partner_slug'),
                'is_active'    => 1,
            ]);

            $this->addFlash('success', 'Estação criada com sucesso.');
            return $this->redirectToRoute('admin_station_index');
        }

        return $this->render('admin/station/new.html.twig', [
            'partners' => $partners,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $station = $this->db->fetchAssociative(
            'SELECT * FROM cemaden_stations WHERE id = ?', [$id]
        );

        if (!$station) {
            throw $this->createNotFoundException();
        }

        $partners = $this->partnerRepo->findActivePartners();

        if ($request->isMethod('POST')) {
            $this->db->update('cemaden_stations', [
                'cod_estacao'  => $request->request->get('cod_estacao'),
                'nome'         => $request->request->get('nome'),
                'municipio'    => $request->request->get('municipio'),
                'uf'           => strtoupper($request->request->get('uf')),
                'partner_slug' => $request->request->get('partner_slug'),
            ], ['id' => $id]);

            $this->addFlash('success', 'Estação atualizada.');
            return $this->redirectToRoute('admin_station_index');
        }

        return $this->render('admin/station/edit.html.twig', [
            'station'  => $station,
            'partners' => $partners,
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(int $id): Response
    {
        $station = $this->db->fetchAssociative(
            'SELECT id, is_active FROM cemaden_stations WHERE id = ?', [$id]
        );

        if ($station) {
            $this->db->update('cemaden_stations', [
                'is_active' => $station['is_active'] ? 0 : 1,
            ], ['id' => $id]);
        }

        return $this->redirectToRoute('admin_station_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('del_station_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido.');
            return $this->redirectToRoute('admin_station_index');
        }

        $this->db->delete('cemaden_stations', ['id' => $id]);
        $this->addFlash('success', 'Estação removida.');
        return $this->redirectToRoute('admin_station_index');
    }
}
