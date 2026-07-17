<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CronController extends AbstractController
{
    #[Route('/cron/run', name: 'cron_run', methods: ['GET'])]
    public function run(Request $request): Response
    {
        $token = $request->query->get('token');
        if ($token !== $_ENV['CRON_TOKEN']) {
            return new Response('Forbidden', 403);
        }

        $php = 'php';
        $project = dirname(__DIR__, 2);
        $log = $project . '/var/log/cron_scheduler.log';

        $cmd = sprintf(
            'cd %s && %s bin/console messenger:consume scheduler_main --time-limit=90 --limit=10 --env=prod -vv >> %s 2>&1 &',
            escapeshellarg($project),
            escapeshellarg($php),
            escapeshellarg($log)
        );

        exec($cmd);

        return new Response('OK');
    }
}
