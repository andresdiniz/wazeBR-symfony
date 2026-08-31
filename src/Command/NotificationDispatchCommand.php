<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Repository\CemadenDataRepository;
use App\Repository\NotificationRepository;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use App\Repository\WazeAlertRepository;
use App\Service\PhpMailerService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'notifications:dispatch',
    description: 'Gera notificações para administradores de cada parceiro.',
)]
final class NotificationDispatchCommand extends Command
{
    public function __construct(
        private readonly PartnerRepository $partnerRepo,
        private readonly UserRepository $userRepo,
        private readonly WazeAlertRepository $alertRepo,
        private readonly CemadenDataRepository $cemadenRepo,
        private readonly NotificationRepository $notifRepo,
        private readonly TenantContext $tenantContext,
        private readonly PhpMailerService $mailer,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $io->title('Notification Dispatch — Multi-Tenant');

        $partners = $this->partnerRepo->findActivePartners();

        if ($partners === []) {
            $io->warning('Nenhum parceiro ativo encontrado.');

            return Command::SUCCESS;
        }

        $totalNotifications = 0;

        foreach ($partners as $partner) {
            $this->tenantContext->setPartner($partner);

            $io->section(sprintf(
                'Parceiro: %s',
                $partner->getName(),
            ));

            $admins = $this->userRepo->findAdminsByPartner($partner);

            if ($admins === []) {
                $io->text('Nenhum administrador ativo. Pulando.');

                continue;
            }

            $count = 0;

            $count += $this->dispatchAlertNotifications(
                $partner,
                $admins,
                $io,
            );

            $count += $this->dispatchCemadenNotifications(
                $partner,
                $admins,
                $io,
            );

            $totalNotifications += $count;

            $io->success(sprintf(
                '%d notificação(ões) gerada(s).',
                $count,
            ));
        }

        $io->success(sprintf(
            'Processamento concluído. Total: %d notificação(ões).',
            $totalNotifications,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<int, object> $admins
     */
    private function dispatchAlertNotifications(
        Partner $partner,
        array $admins,
        SymfonyStyle $io,
    ): int {
        /*
         * A assinatura do repository é:
         *
         * findCriticalByPartner(
         *     Partner $partner,
         *     int $minReliability = 8,
         *     int $windowMinutes = 30
         * )
         *
         * Portanto, não passe DateTimeImmutable aqui.
         */
        $critical = $this->alertRepo->findCriticalByPartner(
            $partner,
            8,
            30,
        );

        $count = 0;

        foreach ($critical as $alert) {
            $wazeId = (string) $alert->getWazeId();

            if ($wazeId === '') {
                continue;
            }

            foreach ($admins as $user) {
                if ($this->notifRepo->existsForAlert($user, $wazeId)) {
                    continue;
                }

                $notification = (new Notification())
                    ->setPartner($partner)
                    ->setUser($user)
                    ->setType('waze_alert')
                    ->setReferenceId($wazeId)
                    ->setTitle(sprintf(
                        'Alerta %s — %s',
                        $alert->getType(),
                        $alert->getCity() ?: 'Local não informado',
                    ))
                    ->setBody(sprintf(
                        '%s | Confiabilidade: %s | Confiança: %s',
                        $alert->getStreet() ?: 'Via não informada',
                        $alert->getReliability(),
                        $alert->getConfidence(),
                    ));

                $this->notifRepo->save($notification, false);
                $count++;

                $sent = $this->sendNotificationEmail(
                    $user,
                    $notification->getTitle(),
                    $notification->getBody(),
                );

                if (!$sent) {
                    $io->warning(sprintf(
                        'Falha ao enviar alerta para %s.',
                        $this->maskEmail((string) $user->getEmail()),
                    ));
                }
            }
        }

        if ($count > 0) {
            $this->notifRepo->getEntityManager()->flush();
        }

        return $count;
    }

    /**
     * @param array<int, object> $admins
     */
    private function dispatchCemadenNotifications(
        Partner $partner,
        array $admins,
        SymfonyStyle $io,
    ): int {
        $critical = $this->cemadenRepo->findByPartnerAndLevels(
            $partner,
            ['VERMELHO', 'LARANJA'],
        );

        $count = 0;

        foreach ($critical as $item) {
            foreach ($admins as $user) {
                if ($this->notifRepo->existsForCemaden(
                    $user,
                    $item->getStationCode(),
                    $item->getMeasuredAt(),
                )) {
                    continue;
                }

                $referenceId = $item->getStationCode()
                    .'_'.$item->getMeasuredAt()->format('YmdHi');

                $notification = (new Notification())
                    ->setPartner($partner)
                    ->setUser($user)
                    ->setType('cemaden')
                    ->setReferenceId($referenceId)
                    ->setTitle(sprintf(
                        'Alerta %s — %s/%s',
                        $item->getAlertLevel(),
                        $item->getMunicipality(),
                        $item->getState(),
                    ))
                    ->setBody(sprintf(
                        '%s | Chuva: %s mm',
                        $item->getStationName(),
                        $item->getAccumulatedRain() ?? 'N/A',
                    ));

                $this->notifRepo->save($notification, false);
                $count++;

                $sent = $this->sendNotificationEmail(
                    $user,
                    $notification->getTitle(),
                    $notification->getBody(),
                );

                if (!$sent) {
                    $io->warning(sprintf(
                        'Falha ao enviar CEMADEN para %s.',
                        $this->maskEmail((string) $user->getEmail()),
                    ));
                }
            }
        }

        if ($count > 0) {
            $this->notifRepo->getEntityManager()->flush();
        }

        return $count;
    }

    private function sendNotificationEmail(
        object $user,
        string $title,
        string $body,
    ): bool {
        $email = (string) $user->getEmail();

        if ($email === '') {
            return false;
        }

        $html = sprintf(
            '<div style="font-family:Arial,sans-serif;">'
            .'<h2>%s</h2>'
            .'<p>%s</p>'
            .'<p style="color:#777;font-size:12px;">WazeBR</p>'
            .'</div>',
            htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        );

        return $this->mailer->send(
            toEmail: $email,
            toName: method_exists($user, 'getName')
                ? (string) ($user->getName() ?? $email)
                : $email,
            subject: '[WazeBR] '.$title,
            htmlBody: $html,
            textBody: $title."\n\n".$body,
        );
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        if (count($parts) !== 2) {
            return '[e-mail inválido]';
        }

        return mb_substr($parts[0], 0, 2).'***@'.$parts[1];
    }
}
