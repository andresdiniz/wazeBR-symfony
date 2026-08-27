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
 * CronController — Disparo via URL (wget/curl) e health-check dos jobs de coleta.
 *
 * Pensado para hospedagem compartilhada (Hostinger) onde o Agendador de
 * Tarefas só permite configurar uma URL (ex.: via wget), em vez de rodar
 * um binário PHP diretamente.
 *
 * Aceita também o job especial "all" (roda todos os jobs em sequência,
 * cada um com seu próprio lock/timeout) — útil pra debug manual, tanto
 * via CLI (`php cron.php all`) quanto via URL (`/cron/trigger/all`).
 * Não recomendado como entrada de cron regular — prefira uma linha por
 * job, cada uma na sua frequência (ver doc/cron-reference.md).
 *
 * SEM LOGIN: estas rotas precisam estar liberadas como PUBLIC_ACCESS em
 * config/packages/security.yaml (regra `{ path: '^/cron', roles:
 * PUBLIC_ACCESS }` ANTES do catch-all `^/`). A autenticação aqui é feita
 * só pelo token (CRON_TOKEN no .env), comparado com hash_equals() —
 * nunca por sessão/login, senão o wget cai numa tela de login em vez do JSON.
 *
 * BACKGROUND: /cron/trigger/{job} dispara o job e responde na hora, sem
 * esperar ele terminar — evita estourar o timeout do PHP/servidor web
 * quando chamado via wget.
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

    /**
     * Dispara um job em background via `php cron.php <job>` e responde
     * imediatamente — feito para ser chamado por wget/curl no cron da
     * Hostinger. O resultado real fica disponível em /cron/run (campo
     * cron_jobs) alguns segundos depois.
     */
    #[Route('/cron/trigger/{job}', name: 'cron_trigger', methods: ['GET'])]
    public function trigger(string $job, Request $request): JsonResponse
    {
        if (!$this->isTokenValid($request)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        if ($job !== 'all' && !in_array($job, self::ALLOWED_JOBS, true)) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => "Job desconhecido: {$job}",
                'allowed' => array_merge(self::ALLOWED_JOBS, ['all (debug — roda todos em sequência)']),
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!function_exists('exec')) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => 'exec() está desabilitado neste PHP (SAPI web). '
                    . 'Configure o cron da Hostinger para rodar "php cron.php '
                    . $job . '" via CLI em vez desta URL — ver doc/cron-reference.md.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $phpBinary = $_ENV['CRON_PHP_BINARY'] ?? null;

        if ($phpBinary === null || $phpBinary === '') {
            return new JsonResponse([
                'status'  => 'error',
                'message' => 'CRON_PHP_BINARY não está configurado no .env. '
                    . 'PHP_BINARY não é confiável aqui: sob Apache/mod_php (XAMPP) ele '
                    . 'resolve para o binário do SAPI web (CGI/Apache), não o PHP-CLI, '
                    . 'e o job falha com "Undefined constant STDERR". '
                    . 'Defina CRON_PHP_BINARY com o caminho do PHP-CLI (ex.: '
                    . 'C:\\xampp\\php\\php.exe no XAMPP local, ou /usr/local/bin/php8.5 na Hostinger).',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $projectDir  = $this->getParameter('kernel.project_dir');
        $cronScript  = $projectDir . '/cron.php';
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

    /** Health-check: expõe o resultado da última execução de cada job. */
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
            'note' => 'Coleta disparada por cron.php via /cron/trigger/{job} (ver doc/cron-reference.md). '
                . 'Campo cron_jobs vem de var/log/cron_status.json.',
        ]);
    }

    /**
     * Token obrigatório: sem CRON_TOKEN configurado no .env, a rota
     * sempre nega — evita repetir o erro anterior de token hardcoded
     * ("1") ou de rota aberta sem proteção nenhuma.
     */
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
