<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CronController — Health-check e disparo via URL dos jobs de coleta.
 *
 * DUAS FORMAS DE USO NA HOSTINGER (escolha uma por job, nunca as duas
 * para o mesmo job — senão ele roda em dobro):
 *
 *   1. CLI direto (recomendado): Agendador de Tarefas chamando
 *      `php cron.php <job>` — ver doc/cron-reference.md. Mais robusto,
 *      não depende de exec() estar liberado no PHP do Apache/LiteSpeed.
 *
 *   2. URL (/cron/trigger/{job}): útil se o seu plano permitir configurar
 *      o cron como "chamar uma URL" em vez de rodar um binário, ou se
 *      você preferir usar um serviço externo de ping (cron-job.org,
 *      EasyCron, etc). Exige que exec() esteja liberado no SAPI web —
 *      teste antes com uma chamada real, muita hospedagem compartilhada
 *      bloqueia exec() só no PHP do Apache/LiteSpeed mesmo liberando no
 *      CLI. A resposta HTTP retorna na hora (o job roda em background),
 *      então não sofre com o timeout do servidor web.
 *
 * Rotas públicas — ambas exigem CRON_TOKEN como query param.
 */
final class CronController extends AbstractController
{
    /**
     * Jobs que podem ser disparados via /cron/trigger/{job}.
     * Mantenha esta lista sincronizada com o array $jobs de cron.php.
     */
    private const ALLOWED_JOBS = [
        'waze_feed',
        'waze_routes',
        'waze_tvt',
        'cemaden',
        'cemaden_hydro',
        'notify',
        'notify_high_risk',
        'report',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    #[Route('/cron/run', name: 'cron_run', methods: ['GET'])]
    public function run(Request $request): Response
    {
        if (!$this->isTokenValid($request)) {
            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        try {
            $wazePending = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'waze' AND delivered_at IS NULL"
            );
            $cemadenPending = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'cemaden' AND delivered_at IS NULL"
            );
        } catch (\Throwable) {
            $wazePending    = -1;
            $cemadenPending = -1;
        }

        return new JsonResponse([
            'status'    => 'ok',
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'queue' => [
                'waze'    => $wazePending,
                'cemaden' => $cemadenPending,
            ],
            'cron_jobs' => $this->readCronStatus(),
            'note' => 'Coleta disparada por cron.php (ver doc/cron-reference.md). '
                . 'Campo cron_jobs vem de var/log/cron_status.json.',
        ]);
    }

    /**
     * Dispara um job em background via `php cron.php <job>` e responde
     * imediatamente — não espera o job terminar. O resultado real fica
     * disponível em /cron/run (campo cron_jobs) alguns segundos depois.
     */
    #[Route('/cron/trigger/{job}', name: 'cron_trigger', methods: ['GET'])]
    public function trigger(string $job, Request $request): JsonResponse
    {
        if (!$this->isTokenValid($request)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        if (!in_array($job, self::ALLOWED_JOBS, true)) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => "Job desconhecido: {$job}",
                'allowed' => self::ALLOWED_JOBS,
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!function_exists('exec')) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => 'exec() está desabilitado neste PHP (SAPI web). '
                    . 'Use o disparo via CLI direto (Agendador de Tarefas chamando '
                    . 'php cron.php <job>) em vez desta URL — ver doc/cron-reference.md.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $phpBinary  = $_ENV['CRON_PHP_BINARY'] ?? '/usr/local/bin/php8.5';
        $cronScript = $projectDir . '/cron.php';
        $dispatchLog = $projectDir . '/var/log/cron_http_trigger.log';

        // Comando em background (o "&" no final): a resposta HTTP não
        // espera o job terminar, evitando o timeout do servidor web.
        $cmd = sprintf(
            '%s %s %s >> %s 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($cronScript),
            escapeshellarg($job),
            escapeshellarg($dispatchLog),
        );

        exec($cmd);

        return new JsonResponse([
            'status'  => 'dispatched',
            'job'     => $job,
            'message' => 'Job disparado em background. Confira o resultado em /cron/run em alguns segundos.',
        ]);
    }

    private function isTokenValid(Request $request): bool
    {
        $token    = (string) $request->query->get('token', '');
        $expected = (string) ($_ENV['CRON_TOKEN'] ?? '');

        return $expected !== '' && hash_equals($expected, $token);
    }

    /**
     * Lê var/log/cron_status.json (gerado pelo cron.php a cada execução
     * de job) e retorna o conteúdo já decodificado, ou um array vazio
     * se o arquivo ainda não existir ou estiver corrompido.
     */
    private function readCronStatus(): array
    {
        $path = $this->getParameter('kernel.project_dir') . '/var/log/cron_status.json';

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
