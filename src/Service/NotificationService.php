<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\WazeAlert;
use App\Entity\WazeTrafficJam;
use App\Entity\CemadenData;
use App\Entity\Partner;
use App\Repository\UserRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeTrafficJamRepository;
use App\Repository\CemadenDataRepository;
use App\Service\PhpMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PhpMailerService       $mailer,
        private readonly UserRepository         $userRepository,
        private readonly WazeAlertRepository    $wazeAlertRepository,
        private readonly WazeTrafficJamRepository $wazeTrafficJamRepository,
        private readonly CemadenDataRepository  $cemadenDataRepository,
        private readonly LoggerInterface        $logger,
        private readonly string                 $appName,
    ) {}

    public function notifyHighRiskAlerts(): int
    {
        $alerts = $this->wazeAlertRepository->findHighRiskAlerts();
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

    /**
     * Envia o relatório diário para todos os usuários ativos de um parceiro.
     */
    public function sendDailyReportForPartner(Partner $partner, \DateTimeImmutable $date): void
    {
        $this->logger->info('Enviando relatório diário', [
            'partner' => $partner->getName(),
            'date' => $date->format('Y-m-d'),
        ]);

        $alertCount   = $this->wazeAlertRepository->countByDate($partner, $date);
        $jamCount     = $this->wazeTrafficJamRepository->countByDate($partner, $date);
        $cemadenCount = $this->cemadenDataRepository->countActiveAlerts($partner);
        $users        = $this->userRepository->findActiveUsers($partner);

        foreach ($users as $user) {
            $sent = $this->mailer->send(
                toEmail: $user->getEmail(),
                toName: $user->getName() ?? $user->getEmail(),
                subject: "[{$this->appName}] Relatório Diário — " . $date->format('d/m/Y'),
                htmlBody: $this->buildDailyReportHtml($user, $alertCount, $jamCount, $cemadenCount, $date),
            );

            if (!$sent) {
                $this->logger->error('Erro ao enviar relatório', [
                    'user' => $user->getEmail(),
                ]);
            }
        }

        $this->logger->info('Relatórios enviados', [
            'partner' => $partner->getName(),
            'users' => count($users),
        ]);
    }

    /**
     * Versão antiga, mantida apenas se houver chamadas legadas.
     *
     * @deprecated Use sendDailyReportForPartner() em vez disso.
     */
    public function sendDailyReport(\DateTimeImmutable $date): void
    {
        // Se realmente quiser um relatório "global", itere por parceiros no command
        // e chame sendDailyReportForPartner() para cada um.
        throw new \BadMethodCallException(
            'sendDailyReport() sem parceiro não é mais suportado. Use sendDailyReportForPartner().'
        );
    }

    private function buildDailyReportHtml(
        User $user,
        int $alertCount,
        int $jamCount,
        int $cemadenCount,
        \DateTimeImmutable $date,
    ): string {
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
