<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/cron')]
class CronController extends AbstractController
{
    #[Route('/trigger/{job}', name: 'cron_trigger')]
    public function trigger(string $job, Request $request): JsonResponse
    {
        $token = $request->query->get('token');

        // Validar token (substitua pelo seu token real)
        if ($token !== '1') {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        // Executar o job via CLI
        $rootDir = $this->getParameter('kernel.project_dir');
        $cronPath = $rootDir . '/cron.php';

        // Mudar para o diretorio do projeto antes de executar
        $command = sprintf('cd %s && php %s %s 2>&1', escapeshellarg($rootDir), escapeshellarg($cronPath), escapeshellarg($job));
        $output = [];
        $returnCode = 0;

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return new JsonResponse([
                'error' => 'Job failed',
                'job' => $job,
                'output' => $output,
                'returnCode' => $returnCode
            ], 500);
        }

        return new JsonResponse([
            'success' => true,
            'job' => $job,
            'output' => $output
        ]);
    }
}
