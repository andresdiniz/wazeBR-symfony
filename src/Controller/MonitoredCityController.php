<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MonitoredCityController extends AbstractController
{
    #[Route('/monitored-cities', name: 'monitored_city_index', methods: ['GET'])]
    public function index(): Response
    {
        // Aqui você pode listar as cidades monitoradas do parceiro
        // Por enquanto, redireciona para o dashboard
        $this->addFlash('info', 'Página em construção – em breve você poderá gerenciar cidades.');
        return $this->redirectToRoute('dashboard_index');
    }
}
