<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\WazeAlert;
use App\Entity\WazeTrafficJam;
use App\Entity\CemadenData;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface        $mailer,
        private readonly UserRepository         $userRepository,
        private readonly LoggerInterface        $logger,
        private readonly string                 $appName,
        private readonly string                 $senderEmail,
    ) {}

    public function notifyHighRiskAlerts(): int
    {
        $alerts = $this->entityManager->getRepository(WazeAlert::class)->findHighRiskAlerts();
        $users  = $this->userRepository->findActiveUsers();
        $sent   = 0;

        foreach ($alerts as $alert) {
            foreach ($users as $user) {
                $notification = (new Notification())
                    ->setUser($user)
                    ->setType('waze_alert')
                    ->setTitle("Alerta Waze: {$alert->getType()} em {$alert->getCity()}")
                    ->setBody("Rua: {$alert->getStreet()} | Confiança: {$alert->getConfidence()}%")
                    ->setPayload(['alert_id' => $alert->getId(), 'lat' => $alert->getLatitude(), 'lng' => $alert->getLongitude()]);

                $this->entityManager->persist($notification);
                $sent++;
            }
        }

        $this->entityManager->flush();
        $this->logger->info('Notificações de alerta criadas', ['count' => $sent]);
        return $sent;
    }

    public function sendDailyReport(\DateTimeImmutable $date): void
    {
        $this->logger->info('Enviando relatório diário', ['date' => $date->format('Y-m-d')]);

        $alertCount   = $this->entityManager->getRepository(WazeAlert::class)->countByDate($date);
        $jamCount     = $this->entityManager->getRepository(WazeTrafficJam::class)->countByDate($date);
        $cemadenCount = $this->entityManager->getRepository(CemadenData::class)->countActiveAlerts();
        $users        = $this->userRepository->findActiveUsers();

        foreach ($users as $user) {
            $email = (new Email())
                ->from($this->senderEmail)
                ->to($user->getEmail())
                ->subject("[{$this->appName}] Relatório Diário — " . $date->format('d/m/Y'))
                ->html($this->buildDailyReportHtml($user, $alertCount, $jamCount, $cemadenCount, $date));

            try {
                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Erro ao enviar relatório', ['user' => $user->getEmail(), 'error' => $e->getMessage()]);
            }
        }

        $this->logger->info('Relatórios enviados', ['users' => count($users)]);
    }

    private function buildDailyReportHtml(User $user, int $alertCount, int $jamCount, int $cemadenCount, \DateTimeImmutable $date): string
    {
        return <<<HTML
        <h2>Relatório Diário — {$date->format('d/m/Y')}</h2>
        <p>Olá, {$user->getName()}!</p>
        <ul>
            <li>🚨 <strong>Alertas Waze:</strong> {$alertCount}</li>
            <li>🚗 <strong>Congestionamentos:</strong> {$jamCount}</li>
            <li>🌧️ <strong>Alertas CEMADEN ativos:</strong> {$cemadenCount}</li>
        </ul>
        <p>Acesse o painel para ver os detalhes.</p>
        HTML;
    }
}
