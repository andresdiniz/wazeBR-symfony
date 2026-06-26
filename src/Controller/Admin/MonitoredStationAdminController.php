<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CemadenData;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administração de Estações CEMADEN monitoradas.
 * Usa uma tabela simples `cemaden_stations` (codEstacao, nome, municipio, uf, parceiro).
 *
 * Migration mínima:
 *   CREATE TABLE IF NOT EXISTS cemaden_stations (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     cod_estacao VARCHAR(30) NOT NULL UNIQUE,
 *     nome VARCHAR(120) NOT NULL,
 *     municipio VARCHAR(80) NOT NULL,
 *     uf CHAR(2) NOT NULL,
 *     partner_slug VARCHAR(40) NOT NULL,
 *     is_active TINYINT(1) NOT NULL DEFAULT 1
 *   );
 */
#[Route('/admin/stations', name: 'admin_station_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class MonitoredStationAdminController extends AbstractController
{
    public function __construct(
        private readonly Connection             $db,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $stations = $this->db->fetchAllAssociative(
            'SELECT * FROM cemaden_stations ORDER BY partner_slug, uf, municipio'
        );

        // Agrupar por parceiro
        $byPartner = [];
        foreach ($stations as $s) {
            $byPartner[$s['partner_slug']][] = $s;
        }

        return $this->render('admin/station/index.html.twig', [
            'by_partner' => $byPartner,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->db->insert('cemaden_stations', [
                'cod_estacao'  => trim($request->request->get('cod_estacao', '')),
                'nome'         => trim($request->request->get('nome', '')),
                'municipio'    => trim($request->request->get('municipio', '')),
                'uf'           => strtoupper(trim($request->request->get('uf', ''))),
                'partner_slug' => trim($request->request->get('partner_slug', '')),
                'is_active'    => 1,
            ]);
            $this->addFlash('success', 'Estação CEMADEN cadastrada.');
            return $this->redirectToRoute('admin_station_index');
        }

        // Lista parceiros para o select
        $partners = $this->db->fetchAllAssociative('SELECT slug, name FROM partners WHERE is_active = 1 ORDER BY name');

        return $this->render('admin/station/new.html.twig', [
            'partners' => $partners,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $station = $this->db->fetchAssociative('SELECT * FROM cemaden_stations WHERE id = ?', [$id]);
        if (!$station) {
            throw $this->createNotFoundException("Estação #{$id} não encontrada.");
        }

        if ($request->isMethod('POST')) {
            $this->db->update('cemaden_stations', [
                'cod_estacao'  => trim($request->request->get('cod_estacao', '')),
                'nome'         => trim($request->request->get('nome', '')),
                'municipio'    => trim($request->request->get('municipio', '')),
                'uf'           => strtoupper(trim($request->request->get('uf', ''))),
                'partner_slug' => trim($request->request->get('partner_slug', '')),
            ], ['id' => $id]);
            $this->addFlash('success', 'Estação atualizada.');
            return $this->redirectToRoute('admin_station_index');
        }

        $partners = $this->db->fetchAllAssociative('SELECT slug, name FROM partners WHERE is_active = 1 ORDER BY name');

        return $this->render('admin/station/edit.html.twig', [
            'station'  => $station,
            'partners' => $partners,
        ]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(int $id): Response
    {
        $current = (int) $this->db->fetchOne('SELECT is_active FROM cemaden_stations WHERE id = ?', [$id]);
        $this->db->update('cemaden_stations', ['is_active' => $current ? 0 : 1], ['id' => $id]);
        $status = $current ? 'desativada' : 'ativada';
        $this->addFlash('success', "Estação {$status}.");
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
