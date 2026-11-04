<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WazeAlert;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class AlertNotificationService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM_EMAIL)%')]
        private readonly string $fromEmail,
        #[Autowire('%env(MAILER_FROM_NAME)%')]
        private readonly string $fromName,
    ) {}

    public function notifyImportantAlert(WazeAlert $alert): void
    {
        $partner = $alert->getPartner();
        $type = strtoupper((string) $alert->getType());
        $subtype = strtoupper((string) $alert->getSubtype());
        if ($partner === null || !(in_array($type, ['ACCIDENT', 'ROAD_CLOSED'], true)
            || in_array($subtype, ['ACCIDENT_MAJOR', 'HAZARD_ON_ROAD', 'HAZARD_ON_ROAD_OBJECT', 'HAZARD_WEATHER_FLOOD'], true))) {
            return;
        }
        $title = match (true) {
            $type === 'ACCIDENT' => $subtype === 'ACCIDENT_MAJOR' ? 'Acidente grave reportado' : 'Acidente reportado',
            $type === 'ROAD_CLOSED' => 'Via fechada reportada',
            $subtype === 'HAZARD_WEATHER_FLOOD' => 'Alagamento reportado',
            default => 'Perigo na pista reportado',
        };
        $when = $alert->getPubMillis() > 0
            ? new \DateTimeImmutable('@'.(int) floor($alert->getPubMillis() / 1000))
            : ($alert->getCollectedAt() ?? new \DateTimeImmutable());
        $when = $when->setTimezone(new \DateTimeZone('America/Sao_Paulo'));
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $location = implode(' · ', array_filter([$alert->getStreet(), $alert->getCity() ?: $partner->getCity()]));
        $description = $alert->getReportDescription() ?: 'Sem descrição adicional.';
        $subject = preg_replace('/[\r\n]+/', ' ', sprintf('[WazeBR] %s — %s', $title, $location ?: $partner->getName())) ?? '[WazeBR] Alerta';
        $seen = [];
        foreach ($this->users->findActiveUsersByPartner($partner) as $user) {
            $recipient = trim((string) $user->getEmail());
            $key = mb_strtolower($recipient);
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || isset($seen[$key])) continue;
            $seen[$key] = true;
            try {
                $text = sprintf("%s\nParceiro: %s\nLocal: %s\nReportado: %s (São Paulo)\n\n%s\n\nRelato da fonte Waze; valide a ocorrência com a operação antes de acionar recursos.", $title, $partner->getName(), $location ?: 'Local não informado', $when->format('d/m/Y H:i'), $description);
                $email = (new Email())
                    ->from(new Address($this->fromEmail, $this->fromName))
                    ->to($recipient)
                    ->subject($subject)
                    ->text($text)
                    ->html('<!doctype html><html lang="pt-BR"><body><h1>'.$escape($title).'</h1><p>'.nl2br($escape($text)).'</p></body></html>');
                $this->mailer->send($email);
                $this->logger->info('notifications.email.submitted', ['partner_id' => $partner->getId(), 'alert_uuid' => $alert->getUuid(), 'user_id' => $user->getId()]);
            } catch (\Throwable $exception) {
                $this->logger->error('notifications.email.failed', ['partner_id' => $partner->getId(), 'alert_uuid' => $alert->getUuid(), 'user_id' => $user->getId(), 'exception' => $exception]);
            }
        }
    }
}
