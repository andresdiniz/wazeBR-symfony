<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/api')]
class ApiDocsController extends AbstractController
{
    /**
     * API Documentation page
     */
    #[Route(path: '/docs', name: 'api_docs')]
    public function docs(): Response
    {
        return $this->render('api/docs.html.twig', [
            'current_route' => 'api_docs',
        ]);
    }

    /**
     * API endpoint example - Traffic data
     */
    #[Route(path: '/traffic', name: 'api_traffic', methods: ['GET'])]
    public function traffic(): Response
    {
        // Exemplo de resposta da API
        $data = [
            'status' => 'success',
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => [
                'alerts' => [
                    [
                        'uuid' => 'alert-001',
                        'type' => 'ACCIDENT',
                        'subtype' => 'ACCIDENT_MINOR',
                        'location' => 'Av. Paulista, 1000 - São Paulo, SP',
                        'latitude' => -23.561414,
                        'longitude' => -46.655881,
                        'reported_by' => 'wazeBR User',
                        'reliability' => 8,
                        'confidence' => 5,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ],
                    [
                        'uuid' => 'alert-002',
                        'type' => 'HAZARD',
                        'subtype' => 'HAZARD_ON_ROAD',
                        'location' => 'Rua Oscar Freire, 500 - São Paulo, SP',
                        'latitude' => -23.555892,
                        'longitude' => -46.661234,
                        'reported_by' => 'wazeBR User',
                        'reliability' => 6,
                        'confidence' => 4,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ],
                ],
                'jams' => [
                    [
                        'uuid' => 'jam-001',
                        'level' => 4,
                        'speed' => 15.5,
                        'delay' => 180,
                        'length' => 500,
                        'street' => 'Av. Paulista',
                        'city' => ' São Paulo',
                        'start_latitude' => -23.561414,
                        'start_longitude' => -46.655881,
                        'end_latitude' => -23.565000,
                        'end_longitude' => -46.658000,
                        'timestamp' => date('Y-m-d H:i:s'),
                    ],
                ],
            ],
        ];

        return $this->json($data);
    }
}
