<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\WazeAlertRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Fornece a variável global `header_notifications` para o header.
 *
 * Retorna uma lista de arrays com as chaves:
 *   title, message, time, tone (orange|blue|green), unread (bool)
 *
 * Se não houver dados, retorna [] e o header cai no fallback do Twig.
 */
final class HeaderNotificationsExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly WazeAlertRepository $alertRepository,
        private readonly Security $security,
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'header_notifications' => $this->buildNotifications(),
        ];
    }

    private function buildNotifications(): array
    {
        $user = $this->security->getUser();

        if ($user === null) {
            return [];
        }

        /*
         * Aqui você pode montar as notificações a partir de qualquer fonte:
         *   - últimas ocorrências do partner
         *   - alertas ativos recentes
         *   - dados de um NotificationService
         *
         * Neste exemplo, usamos os 3 alertas ativos mais recentes.
         * Troque pela sua regra de negócio.
         */
        $alerts = $this->alertRepository->findBy(
            ['isActive' => true],
            ['lastSeenAt' => 'DESC'],
            3,
        );

        if ($alerts === []) {
            return [];
        }

        $notifications = [];

        foreach ($alerts as $alert) {
            $notifications[] = [
                'title'   => $alert->getTypeLabel(),
                'message' => $alert->getCity()
                    ? sprintf('%s, %s', $alert->getStreet() ?? '—', $alert->getCity())
                    : ($alert->getStreet() ?? 'Sem localização'),
                'time'    => $this->formatRelative($alert->getLastSeenAt()),
                'tone'    => $this->toneForType($alert->getType()),
                'unread'  => true,
            ];
        }

        return $notifications;
    }

    private function formatRelative(?\DateTimeImmutable $date): string
    {
        if ($date === null) {
            return '—';
        }

        $diff = time() - $date->getTimestamp();

        if ($diff < 60) {
            return 'Agora';
        }
        if ($diff < 3600) {
            return sprintf('Há %d min', intdiv($diff, 60));
        }
        if ($diff < 86400) {
            return sprintf('Há %d h', intdiv($diff, 3600));
        }

        return $date->format('d/m H:i');
    }

    private function toneForType(?string $type): string
    {
        return match ($type) {
            'ROAD_CLOSED', 'ACCIDENT' => 'orange',
            'HAZARD', 'WEATHERHAZARD' => 'blue',
            default                    => 'green',
        };
    }
}
