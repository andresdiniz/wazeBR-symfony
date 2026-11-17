<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PartnerFeedEvent;
use App\Entity\UserNotificationSubscription;
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

/**
 * Avisa por e-mail, conforme as preferências de cada usuário, quando um evento
 * do feed CIFS do parceiro é criado.
 */
final class EventNotificationService
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
    ) {
    }

    public function notifyEventCreated(PartnerFeedEvent $event): void
    {
        $partner = $event->getPartner();
        if ($partner === null) {
            return;
        }

        [$type, $subtype] = $this->catalog->normalize($event->getCifsType(), $event->getCifsSubtype());
        if ($type === '') {
            return;
        }

        $seen = [];
        $candidates = [];
        foreach ($this->users->findActiveUsersByPartner($partner) as $user) {
            $address = trim((string) $user->getEmail());
            $key = mb_strtolower($address);
            if (!filter_var($address, FILTER_VALIDATE_EMAIL) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $candidates[] = $user;
        }

        $recipients = $this->preferences->recipientsFor($candidates, UserNotificationSubscription::CATEGORY_EVENT, $type, $subtype);
        if ($recipients === []) {
            return;
        }

        $typeLabel = $this->catalog->label($type);
        $subtypeLabel = $subtype !== null ? $this->catalog->label($subtype) : null;
        $street = trim((string) $event->getStreet());
        $city = trim((string) ($event->getCity() ?: $partner->getCity()));
        $title = 'Novo evento: ' . ($subtypeLabel ?? $typeLabel);
        $period = $this->period($event);
        $description = trim((string) $event->getDescription());
        $settingsUrl = $this->settingsUrl();

        $subject = preg_replace('/[\r\n]+/', ' ', sprintf('[WazeBR] %s — %s', $title, $street !== '' ? $street : (string) $partner->getName())) ?? '[WazeBR] Novo evento';
        $text = sprintf(
            "%s\nParceiro: %s\nTipo: %s\nVia: %s\nCidade: %s\nPeríodo: %s%s%s",
            $title,
            $partner->getName(),
            $subtypeLabel !== null ? $typeLabel . ' · ' . $subtypeLabel : $typeLabel,
            $street !== '' ? $street : 'Via não informada',
            $city !== '' ? $city : 'Cidade não informada',
            $period,
            $description !== '' ? "\n\n" . $description : '',
            $settingsUrl !== null ? "\n\nPara escolher o que receber ou parar de receber: " . $settingsUrl : '',
        );

        try {
            $html = $this->twig->render('email/partner_event.html.twig', [
                'title' => $title,
                'partner_name' => (string) $partner->getName(),
                'type_label' => $typeLabel,
                'subtype_label' => $subtypeLabel,
                'street' => $street,
                'city' => $city,
                'period' => $period,
                'description' => $description,
                'event_uuid' => (string) $event->getUuid(),
                'settings_url' => $settingsUrl,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('notifications.event_email.render_failed', ['partner_id' => $partner->getId(), 'event_uuid' => $event->getUuid(), 'exception' => $exception]);

            return;
        }

        foreach ($recipients as $user) {
            try {
                $email = (new Email())
                    ->from(new Address($this->fromEmail, $this->fromName))
                    ->to(trim((string) $user->getEmail()))
                    ->subject($subject)
                    ->text($text)
                    ->html($html);
                if ($settingsUrl !== null) {
                    $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $settingsUrl . '>');
                }
                $this->mailer->send($email);
                $this->logger->info('notifications.event_email.submitted', ['partner_id' => $partner->getId(), 'event_uuid' => $event->getUuid(), 'user_id' => $user->getId()]);
            } catch (\Throwable $exception) {
                $this->logger->error('notifications.event_email.failed', ['partner_id' => $partner->getId(), 'event_uuid' => $event->getUuid(), 'user_id' => $user->getId(), 'exception' => $exception]);
            }
        }
    }

    private function period(PartnerFeedEvent $event): string
    {
        $start = $event->getStartTime();
        $end = $event->getEndTime();
        $format = static fn (?\DateTimeInterface $date): ?string => $date?->format('d/m/Y H:i');

        return match (true) {
            $start !== null && $end !== null => sprintf('%s até %s', $format($start), $format($end)),
            $start !== null => sprintf('A partir de %s', $format($start)),
            $end !== null => sprintf('Até %s', $format($end)),
            default => 'Não informado',
        };
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
