<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CronController extends AbstractController
{
    private const ALLOWED_JOBS = [
        'all',
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
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async_waze'"
            );
        } catch (\Throwable) {
            $wazePending = null;
        }

        try {
            $cemadenPending = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async_cemaden'"
            );
        } catch (\Throwable) {
            $cemadenPending = null;
        }

        return new JsonResponse([
            'status' => 'ok',
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'queue' => [
                'waze' => $wazePending,
                'cemaden' => $cemadenPending,
            ],
            'cron_jobs' => $this->readCronStatus(),
            'note' => 'Coleta disparada por cron.php. Campo cron_jobs vem de var/log/cron_status.json.',
        ]);
    }

    #[Route('/cron/trigger/{job}', name: 'cron_trigger', methods: ['GET'])]
    public function trigger(string $job, Request $request): JsonResponse
    {
        if (!$this->isTokenValid($request)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        if (!in_array($job, self::ALLOWED_JOBS, true)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => "Job desconhecido: {$job}",
                'allowed' => self::ALLOWED_JOBS,
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!function_exists('exec')) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'exec() está desabilitado neste PHP (SAPI web). Use o modo CLI: php cron.php <job>.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $isWindows = PHP_OS_FAMILY === 'Windows';
        $configuredBinary = $_ENV['CRON_PHP_BINARY'] ?? $_SERVER['CRON_PHP_BINARY'] ?? getenv('CRON_PHP_BINARY');
        $phpBinary = is_string($configuredBinary) && trim($configuredBinary) !== ''
            ? trim($configuredBinary, " \t\n\r\0\x0B\"")
            : ($isWindows ? PHP_BINARY : '/usr/local/bin/php8.5');

        $cronScript = $projectDir . '/cron.php';
        $dispatchLog = $projectDir . '/var/log/cron_http_trigger.log';

        if (!is_file($phpBinary)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Binário PHP não encontrado.',
                'php_binary' => $phpBinary,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (!is_file($cronScript)) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Arquivo cron.php não encontrado.',
                'cron_script' => $cronScript,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $args = [$phpBinary, $cronScript, $job];

        if ($isWindows) {
            $cmd = 'start /B "" ' . implode(' ', array_map(
                static fn(string $value): string => escapeshellarg($value),
                $args
            )) . ' >> ' . escapeshellarg($dispatchLog) . ' 2>&1';
        } else {
            $cmd = implode(' ', array_map(
                static fn(string $value): string => escapeshellarg($value),
                $args
            )) . ' >> ' . escapeshellarg($dispatchLog) . ' 2>&1 &';
        }

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Não foi possível disparar o processo PHP.',
                'exit_code' => $exitCode,
                'php_binary' => $phpBinary,
                'command' => $cmd,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'status' => 'dispatched',
            'job' => $job,
            'os' => $isWindows ? 'windows' : 'linux',
            'message' => $job === 'all'
                ? 'Todos os jobs foram disparados em sequência. Confira /cron/run depois de alguns segundos.'
                : 'Job disparado em background. Confira /cron/run depois de alguns segundos.',
        ]);
    }

    private function isTokenValid(Request $request): bool
    {
        $token = (string) $request->query->get('token', '');
        $expected = (string) ($_ENV['CRON_TOKEN'] ?? '');

        return $expected !== '' && hash_equals($expected, $token);
    }

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
