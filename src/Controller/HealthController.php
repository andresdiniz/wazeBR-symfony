<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\HealthCheckService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    public function __construct(
        private readonly HealthCheckService $healthCheckService,
    ) {}

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $result   = $this->healthCheckService->check();
        $allOk    = !in_array('error', array_column($result, 'status'), true);
        $httpCode = $allOk ? 200 : 503;

        return $this->json($result, $httpCode);
    }
}
