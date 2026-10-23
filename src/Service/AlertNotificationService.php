<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\WazeAlert;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AlertNotificationService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default:empty:WAZEBR_NOTIFICATIONS_WORKER_URL)%')]
        private readonly string $workerUrl,
        #[Autowire('%env(default:empty:WAZEBR_NOTIFICATIONS_WEBHOOK_TOKEN)%')]
        private readonly string $webhookToken,
    ) {
    }

    public function notifyImportantAlert(WazeAlert $alert): void
    {
        $partner = $alert->getPartner();
        if ($partner === null || !$this->isImportant($alert)) {
            return;
        }

        if ($this->workerUrl === '' || $this->webhookToken === '') {
            return;
        }

        foreach ($this->users->findActiveUsersByPartner($partner) as $user) {
            $this->enqueueForUser($alert, $partner, $user);
        }
    }

    private function enqueueForUser(WazeAlert $alert, Partner $partner, User $user): void
    {
        $recipients = [];
        $channels = [];

        $email = trim((string) $user->getEmail());
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients['email'] = $email;
            $channels[] = 'email';
        }

        $phone = preg_replace('/\D+/', '', (string) $user->getPhone()) ?? '';
        if (preg_match('/^\d{10,11}$/', $phone) === 1) {
            $phone = '55'.$phone;
        }
        if (preg_match('/^\d{10,15}$/', $phone) === 1) {
            $recipients['whatsapp'] = $phone;
            $channels[] = 'whatsapp';
        }

        if ($channels === []) {
            return;
        }

        $eventId = sprintf(
            'waze:%s:%s:user:%s:seen:%s',
            $partner->getId(),
            $alert->getUuid(),
            $user->getId(),
            ($alert->getLastSeenAt() ?? new \DateTimeImmutable())->format('Ymd\\THis.u'),
        );
        $occurredAt = $alert->getPubMillis() > 0
            ? (new \DateTimeImmutable('@'.(int) floor($alert->getPubMillis() / 1000)))->format(DATE_ATOM)
            : ($alert->getCollectedAt() ?? new \DateTimeImmutable())->format(DATE_ATOM);

        $event = [
            'eventId' => $eventId,
            'type' => (string) $alert->getType(),
            'title' => $this->title($alert),
            'description' => $alert->getReportDescription() ?: 'Foi registrado um alerta importante de trânsito.',
            'occurredAt' => $occurredAt,
            'location' => [
                'latitude' => $alert->getLatitude(),
                'longitude' => $alert->getLongitude(),
                'road' => $alert->getStreet(),
                'city' => $alert->getCity() ?: $partner->getCity(),
            ],
            'user' => ['name' => $user->getName() ?: 'Olá'],
            'partner' => ['name' => $partner->getName() ?: 'WazeBR'],
            'channels' => $channels,
            'recipients' => $recipients,
        ];

        try {
            $response = $this->httpClient->request('POST', rtrim($this->workerUrl, '/').'/v1/events', [
                'timeout' => 5,
                'headers' => [
                    'Authorization' => 'Bearer '.$this->webhookToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $event,
            ]);

            if ($response->getStatusCode() !== 202) {
                $this->logger->warning('[notifications] Worker rejeitou alerta importante.', [
                    'eventId' => $eventId,
                    'status' => $response->getStatusCode(),
                ]);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('[notifications] Não foi possível enfileirar alerta importante.', [
                'eventId' => $eventId,
                'exception' => $exception,
            ]);
        }
    }

    private function isImportant(WazeAlert $alert): bool
    {
        $type = strtoupper((string) $alert->getType());
        $subtype = strtoupper((string) $alert->getSubtype());

        return $type === 'ACCIDENT'
            || $type === 'ROAD_CLOSED'
            || in_array($subtype, ['ACCIDENT_MAJOR', 'HAZARD_ON_ROAD', 'HAZARD_ON_ROAD_OBJECT'], true);
    }

    private function title(WazeAlert $alert): string
    {
        $type = strtoupper((string) $alert->getType());
        $subtype = strtoupper((string) $alert->getSubtype());

        if ($type === 'ACCIDENT') {
            return $subtype === 'ACCIDENT_MAJOR' ? 'Acidente grave' : 'Acidente';
        }
        if ($type === 'ROAD_CLOSED') {
            return 'Via fechada';
        }

        return 'Perigo na pista';
    }
}
