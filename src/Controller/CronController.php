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

        $php = '/usr/bin/php8.2';
        $project = dirname(__DIR__, 2);
        $cmd = sprintf(
            '%s %s/bin/console messenger:consume scheduler_main --time-limit=55 --limit=10 --env=prod',
            escapeshellarg($php),
            escapeshellarg($project)
        );

        exec($cmd . ' > /dev/null 2>&1 &');

        return new Response('OK');
    }
}
