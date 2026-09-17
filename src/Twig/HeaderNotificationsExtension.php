<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\WazeAlertRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Fornece a variável global `header_notifications` para o header.
 *
 * Retorna uma lista de arrays com as chaves:
 *   title, message, time, tone (orange|blue|green), unread (bool)
 *
 * Se não houver dados, retorna [] e o header cai no fallback do Twig.
 *
 * IMPORTANTE (shared hosting):
 *   NÃO consulta o banco em rotas públicas (landing, login, etc.)
 *   nem quando não há usuário logado. Cada request dessas rotas
 *   abriria uma conexão MySQL desnecessária — em Hostinger isso
 *   estoura o `max_connections_per_hour` rapidamente.
 */
final class HeaderNotificationsExtension extends AbstractExtension implements GlobalsInterface
{
    /**
     * Rotas que NÃO devem disparar consulta ao banco.
     * Ajuste conforme necessário.
     */
    private const PUBLIC_ROUTES = [
        'app_landing',
        'app_login',
        'app_register',
        'app_logout',
        'app_forgot_password_request',
        'app_reset_password',
    ];

    public function __construct(
        private readonly WazeAlertRepository $alertRepository,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getGlobals(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $route   = $request?->attributes->get('_route');

        // Sem request (ex.: CLI), sem consulta.
        if ($route === null) {
            return ['header_notifications' => []];
        }

        // Rotas públicas: nunca consulta o banco.
        if (in_array($route, self::PUBLIC_ROUTES, true)) {
            return ['header_notifications' => []];
        }

        // Sem usuário logado: nem tenta.
        if ($this->security->getUser() === null) {
            return ['header_notifications' => []];
        }

        return [
            'header_notifications' => $this->buildNotifications(),
        ];
    }

    /**
     * @return list<array{
     *     title: string,
     *     message: string,
     *     time: string,
     *     tone: string,
     *     unread: bool
     * }>
     */
    private function buildNotifications(): array
    {
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
