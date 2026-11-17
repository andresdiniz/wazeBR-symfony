<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\UserNotificationSubscription;
use App\Entity\WazeAlert;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationCatalog;
use App\Service\Notification\NotificationPreferenceService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class AlertNotificationService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
        private readonly Environment $twig,
        private readonly NotificationPreferenceService $preferences,
        private readonly NotificationCatalog $catalog,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(MAILER_FROM_EMAIL)%')]
        private readonly string $fromEmail,
        #[Autowire('%env(MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {}

    public function notifyImportantAlert(WazeAlert $alert): void
    {
        $partner = $alert->getPartner();
        if ($partner === null) {
            return;
        }

        [$type, $subtype] = $this->catalog->normalize($alert->getType(), $alert->getSubtype());
        if ($type === '') {
            return;
        }

        // Só quem ativou e-mail de alertas E marcou este tipo/subtipo recebe.
        $recipients = $this->preferences->recipientsFor(
            $this->deliverableUsers($partner),
            UserNotificationSubscription::CATEGORY_ALERT,
            $type,
            $subtype,
        );
        if ($recipients === []) {
            return;
        }

        $subtype ??= '';
        $title = match (true) {
            $type === 'ACCIDENT' => $subtype === 'ACCIDENT_MAJOR' ? 'Acidente grave reportado' : 'Acidente reportado',
            $type === 'ROAD_CLOSED' => 'Via fechada reportada',
            $subtype === 'HAZARD_WEATHER_FLOOD' => 'Alagamento reportado',
            \in_array($subtype, ['HAZARD_ON_ROAD', 'HAZARD_ON_ROAD_OBJECT'], true) => 'Perigo na pista reportado',
            default => $this->catalog->label($subtype !== '' ? $subtype : $type) . ' reportado',
        };
        $settingsUrl = $this->settingsUrl();
        $reported = ($alert->getPubMillis() ?? 0) > 0;
        $when = $reported
            ? new \DateTimeImmutable('@' . (int) floor($alert->getPubMillis() / 1000))
            : ($alert->getCollectedAt() ?? new \DateTimeImmutable());
        $when = $when->setTimezone(new \DateTimeZone('America/Sao_Paulo'));
        $street = trim((string) $alert->getStreet());
        $city = trim((string) ($alert->getCity() ?: $partner->getCity()));
        $location = implode(' · ', array_filter([$street, $city], static fn (string $value): bool => $value !== ''));
        $description = $alert->getReportDescription() ?: 'Sem descrição adicional.';
        $latitude = $alert->getLatitude();
        $longitude = $alert->getLongitude();
        $coordinates = null;
        $wazeUrl = null;
        if (is_numeric($latitude) && is_numeric($longitude)) {
            $lat = (float) $latitude;
            $lng = (float) $longitude;
            if (is_finite($lat) && is_finite($lng) && abs($lat) <= 90 && abs($lng) <= 180) {
                $coordinates = number_format($lat, 7, '.', '') . ', ' . number_format($lng, 7, '.', '');
                $wazeUrl = 'https://www.waze.com/ul?' . http_build_query(['ll' => number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', ''), 'z' => 16], '', '&', PHP_QUERY_RFC3986);
            }
        }
        $subject = preg_replace('/[\r\n]+/', ' ', sprintf('[WazeBR] %s — %s', $title, $location ?: $partner->getName())) ?? '[WazeBR] Alerta';
        $whenLabel = $reported ? 'Reportado pela fonte' : 'Horário de referência da coleta';
        $text = sprintf("%s\nParceiro: %s\nVia: %s\nCidade: %s\n%s: %s (Brasília)\nCoordenadas: %s\n\n%s\n\n%s\n\nRelato da fonte Waze; valide a ocorrência com a operação antes de acionar recursos. O mapa aberto pelo link mostra a situação disponível no momento da consulta, não uma captura do horário do alerta.%s", $title, $partner->getName(), $street ?: 'Via não informada', $city ?: 'Cidade não informada', $whenLabel, $when->format('d/m/Y H:i'), $coordinates ?? 'Não disponíveis', $description, $wazeUrl !== null ? 'Abrir localização no Waze: ' . $wazeUrl : 'Link de mapa indisponível: coordenadas ausentes ou inválidas.', $settingsUrl !== null ? "\n\nPara escolher quais alertas receber ou parar de receber: " . $settingsUrl : '');
        try {
            $html = $this->twig->render('email/important_alert.html.twig', [
                'title' => $title, 'partner_name' => (string) $partner->getName(),
                'street' => $street, 'city' => $city, 'description' => $description,
                'when_label' => $whenLabel, 'when_text' => $when->format('d/m/Y H:i'),
                'coordinates' => $coordinates, 'waze_url' => $wazeUrl,
                'alert_uuid' => (string) $alert->getUuid(), 'settings_url' => $settingsUrl,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('notifications.email.render_failed', ['partner_id' => $partner->getId(), 'alert_uuid' => $alert->getUuid(), 'exception' => $exception]);
            return;
        }
        foreach ($recipients as $user) {
            $recipient = trim((string) $user->getEmail());
            try {
                $email = (new Email())
                    ->from(new Address($this->fromEmail, $this->fromName))
                    ->to($recipient)->subject($subject)->text($text)->html($html);
                if ($settingsUrl !== null) {
                    $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $settingsUrl . '>');
                }
                $this->mailer->send($email);
                $this->logger->info('notifications.email.submitted', ['partner_id' => $partner->getId(), 'alert_uuid' => $alert->getUuid(), 'user_id' => $user->getId()]);
            } catch (\Throwable $exception) {
                $this->logger->error('notifications.email.failed', ['partner_id' => $partner->getId(), 'alert_uuid' => $alert->getUuid(), 'user_id' => $user->getId(), 'exception' => $exception]);
            }
        }
    }

    /**
     * Usuários ativos do parceiro com e-mail válido, sem endereços repetidos.
     *
     * @return list<User>
     */
    private function deliverableUsers(Partner $partner): array
    {
        $seen = [];
        $users = [];
        foreach ($this->users->findActiveUsersByPartner($partner) as $user) {
            $recipient = trim((string) $user->getEmail());
            $key = mb_strtolower($recipient);
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $users[] = $user;
        }

        return $users;
    }

    private function settingsUrl(): ?string
    {
        try {
            return $this->urlGenerator->generate('account_notifications', [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return null;
        }
    }
}
