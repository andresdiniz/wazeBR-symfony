<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gerencia configurações do sistema armazenadas em uma tabela key/value simples.
 * Também expõe a chave WeatherAPI para o template de cidades.
 *
 * Tabela esperada (crie via migration se não existir):
 *   CREATE TABLE IF NOT EXISTS system_settings (
 *     `key` VARCHAR(80) PRIMARY KEY,
 *     `value` TEXT NOT NULL DEFAULT ''
 *   );
 */
#[Route('/admin/settings', name: 'admin_settings_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class SettingsAdminController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /** Lista todas as configurações */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/settings/index.html.twig', [
            'settings'      => $this->loadSettings(),
            'weatherApiKey' => $this->getWeatherApiKey(),
        ]);
    }

    /** Salva uma configuração (key/value via POST) */
    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $key   = trim((string) $request->request->get('key', ''));
        $value = trim((string) $request->request->get('value', ''));

        if (!$key) {
            $this->addFlash('error', 'Chave inválida.');
            return $this->redirectToRoute('admin_settings_index');
        }

        $this->upsert($key, $value);
        $this->addFlash('success', "Configuração '{$key}' salva.");

        return $this->redirectToRoute('admin_settings_index');
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    public function getWeatherApiKey(): string
    {
        return $this->db->fetchOne(
            "SELECT `value` FROM system_settings WHERE `key` = 'weatherapi_key'"
        ) ?: '';
    }

    private function loadSettings(): array
    {
        $rows = $this->db->fetchAllKeyValue('SELECT `key`, `value` FROM system_settings');
        return $rows ?: [];
    }

    private function upsert(string $key, string $value): void
    {
        $this->db->executeStatement(
            'INSERT INTO system_settings (`key`, `value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = :v',
            ['k' => $key, 'v' => $value]
        );
    }
}
