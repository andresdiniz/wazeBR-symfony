<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/settings', name: 'admin_settings_')]
#[IsGranted('ROLE_ADMIN')]
class SettingsAdminController extends AbstractController
{
    public function __construct(private readonly Connection $db) {}

    private function getSetting(string $key, string $default = ''): string
    {
        try {
            $val = $this->db->fetchOne('SELECT value FROM system_settings WHERE `key` = ?', [$key]);
            return $val !== false ? (string) $val : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    private function setSetting(string $key, string $value): void
    {
        $exists = $this->db->fetchOne('SELECT 1 FROM system_settings WHERE `key` = ?', [$key]);
        if ($exists) {
            $this->db->update('system_settings', ['value' => $value], ['key' => $key]);
        } else {
            $this->db->insert('system_settings', ['key' => $key, 'value' => $value]);
        }
    }

    public function getWeatherApiKey(): string
    {
        return $this->getSetting('weather_api_key');
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            foreach (['weather_api_key', 'waze_refresh_interval', 'cemaden_refresh_interval', 'weather_cache_minutes'] as $key) {
                $val = $request->request->get($key, '');
                if ($val !== '') {
                    $this->setSetting($key, $val);
                }
            }
            $this->addFlash('success', 'Configurações salvas.');
            return $this->redirectToRoute('admin_settings_index');
        }

        return $this->render('admin/settings/index.html.twig', [
            'weather_api_key'           => $this->getSetting('weather_api_key'),
            'waze_refresh_interval'     => $this->getSetting('waze_refresh_interval', '60'),
            'cemaden_refresh_interval'  => $this->getSetting('cemaden_refresh_interval', '300'),
            'weather_cache_minutes'     => $this->getSetting('weather_cache_minutes', '10'),
        ]);
    }
}
