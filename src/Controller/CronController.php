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
 * CronController — Health-check e fallback de coleta.
 *
 * IMPORTANTE: Não dispara mais `messenger:consume` diretamente via HTTP.
 * Os workers são mantidos vivos pelo Supervisor (ver supervisor/waze_scheduler.conf).
 *
 * Este endpoint serve para:
 *   1. Verificar se os transportes têm mensagens pendentes (backlog).
 *   2. Ser chamado pelo agendador externo da Hostinger como health-check.
 *   3. Fallback: se o Supervisor não estiver disponível, este endpoint
 *      pode iniciar um consume pontual via cron.php (ver documentação).
 *
 * Rota pública (requer CRON_TOKEN como query param).
 */
final class CronController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    #[Route('/cron/run', name: 'cron_run', methods: ['GET'])]
    public function run(Request $request): Response
    {
        $token = $request->query->get('token');
        if ($token !== $_ENV['CRON_TOKEN']) {
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
            'status'     => 'ok',
            'timestamp'  => (new \DateTimeImmutable())->format('c'),
            'queue' => [
                'waze'    => $wazePending,
                'cemaden' => $cemadenPending,
            ],
            'note' => 'Workers gerenciados pelo Supervisor. Ver supervisor/waze_scheduler.conf.',
        ]);
    }
}
