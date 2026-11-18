<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\UserNotificationSubscription;
use App\Entity\WazeAlert;
use App\Repository\UserRepository;
use App\Service\Notification\AlertEmailPresenter;
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
        private readonly AlertEmailPresenter $presenter,
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
        $info = $this->presenter->describe($type, $subtype !== '' ? $subtype : null);
        $facts = $this->presenter->facts($alert);
        $title = $info['headline'];
        $settingsUrl = $this->routeUrl('account_notifications');
        $dashboardUrl = $this->routeUrl('dashboard');

        $reported = ($alert->getPubMillis() ?? 0) > 0;
        $when = $reported
            ? new \DateTimeImmutable('@' . (int) floor($alert->getPubMillis() / 1000))
            : ($alert->getCollectedAt() ?? new \DateTimeImmutable());
        $when = $when->setTimezone(new \DateTimeZone('America/Sao_Paulo'));
        $whenLabel = $reported ? 'Reportado pela fonte' : 'Horário de referência da coleta';
        $whenText = $when->format('d/m/Y H:i');

        $street = trim((string) $alert->getStreet());
        $city = trim((string) ($alert->getCity() ?: $partner->getCity()));
        $location = implode(' · ', array_filter([$street, $city], static fn (string $value): bool => $value !== ''));
        $description = trim((string) $alert->getReportDescription());

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

        // Sinais de confiança do relato, na ordem em que ajudam a decidir.
        $trust = [];
        if ($facts['reliability'] !== null) {
            $trust[] = sprintf('Confiabilidade %s (%d/10)', mb_strtolower($facts['reliability']['label']), $facts['reliability']['score']);
        }
        if ($facts['confirmations'] > 0) {
            $trust[] = $facts['confirmations'] === 1 ? '1 motorista confirmou' : $facts['confirmations'] . ' motoristas confirmaram';
        }
        if ($facts['municipality']) {
            $trust[] = 'Reportado por usuário de prefeitura';
        }

        $subject = preg_replace('/[\r\n]+/', ' ', sprintf('[WazeBR] %s — %s', $title, $location ?: $partner->getName())) ?? '[WazeBR] Alerta';

        $lines = [
            mb_strtoupper($title) . ' · Gravidade: ' . $info['severity']['label'],
            $info['summary'],
            '',
            'ONDE',
            'Via: ' . ($street ?: 'Via não informada'),
            'Cidade: ' . ($city ?: 'Cidade não informada'),
        ];
        if ($facts['heading'] !== null) {
            $lines[] = 'Sentido aproximado do relato: ' . $facts['heading'];
        }
        if ($facts['road_type'] !== null) {
            $lines[] = 'Tipo de via: ' . $facts['road_type'];
        }
        $lines[] = 'Coordenadas: ' . ($coordinates ?? 'não disponíveis');
        $lines[] = '';
        $lines[] = 'QUANDO';
        $lines[] = $whenLabel . ': ' . $whenText . ' (Brasília)' . ($facts['age'] !== null ? ' — ' . $facts['age'] : '');
        if ($trust !== []) {
            $lines[] = '';
            $lines[] = 'CONFIABILIDADE DO RELATO';
            $lines[] = implode(' · ', $trust);
        }
        $lines[] = '';
        $lines[] = 'O QUE FAZER';
        $lines[] = $info['action'];
        if ($description !== '') {
            $lines[] = '';
            $lines[] = 'RELATO DO MOTORISTA';
            $lines[] = $description;
        }
        $lines[] = '';
        $lines[] = $wazeUrl !== null ? 'Abrir localização no Waze: ' . $wazeUrl : 'Link de mapa indisponível: coordenadas ausentes ou inválidas.';
        if ($dashboardUrl !== null) {
            $lines[] = 'Abrir painel: ' . $dashboardUrl;
        }
        $lines[] = '';
        $lines[] = 'Relato da fonte Waze: valide com a operação antes de acionar recursos. O mapa mostra a situação no momento em que for aberto, não uma captura do horário do alerta.';
        $lines[] = 'Parceiro: ' . $partner->getName() . ' · Ocorrência: ' . $alert->getUuid();
        if ($settingsUrl !== null) {
            $lines[] = '';
            $lines[] = 'Para escolher quais alertas receber ou parar de receber: ' . $settingsUrl;
        }
        $text = implode("\n", $lines);

        try {
            $html = $this->twig->render('email/important_alert.html.twig', [
                'title' => $title, 'info' => $info, 'facts' => $facts, 'trust' => $trust,
                'partner_name' => (string) $partner->getName(),
                'street' => $street, 'city' => $city, 'description' => $description,
                'when_label' => $whenLabel, 'when_text' => $whenText,
                'coordinates' => $coordinates, 'waze_url' => $wazeUrl, 'dashboard_url' => $dashboardUrl,
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

    private function routeUrl(string $route): ?string
    {
        try {
            return $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return null;
        }
    }
}
