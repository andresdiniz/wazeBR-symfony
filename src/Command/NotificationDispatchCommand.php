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
use App\Service\TenantContext;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'notifications:dispatch',
    description: 'Gera notificações para alertas críticos e CEMADEN por parceiro.',
)]
class NotificationDispatchCommand extends Command
{
    private const MIN_RELIABILITY = 8;

    public function __construct(
        private readonly PartnerRepository $partnerRepo,
        private readonly WazeAlertRepository $alertRepo,
        private readonly CemadenDataRepository $cemadenRepo,
        private readonly UserRepository $userRepo,
        private readonly NotificationRepository $notifRepo,
        private readonly TenantContext $tenantContext,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Notification Dispatch — Multi-Tenant');

        foreach ($this->partnerRepo->findActivePartners() as $partner) {
            $this->tenantContext->setPartner($partner);
            $io->section(sprintf('Parceiro: %s', $partner->getName()));

            $admins = $this->userRepo->findAdminsByPartner($partner);

            if ($admins === []) {
                $io->text('Nenhum administrador ativo. Pulando.');
                continue;
            }

            $count = 0;
            $count += $this->dispatchAlertNotifications($partner, $admins);
            $count += $this->dispatchCemadenNotifications($partner, $admins);

            $io->success(sprintf('%d notificação(ões) gerada(s).', $count));
        }

        return Command::SUCCESS;
    }

    private function dispatchAlertNotifications(Partner $partner, array $admins): int
    {
        $critical = $this->alertRepo->findCriticalByPartner(
            $partner,
            new \DateTimeImmutable('-30 minutes', new \DateTimeZone('UTC')),
            100,
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

                $notif = (new Notification())
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

                $this->notifRepo->save($notif, false);
                $count++;
            }
        }

        if ($count > 0) {
            $this->notifRepo->getEntityManager()->flush();
        }

        return $count;
    }

    private function dispatchCemadenNotifications(Partner $partner, array $admins): int
    {
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

                $referenceId = $item->getStationCode() . '_' . $item->getMeasuredAt()->format('YmdHi');

                $notif = (new Notification())
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
                        $item->getAccumulatedRain(),
                    ));

                $this->notifRepo->save($notif, false);
                $count++;
            }
        }

        if ($count > 0) {
            $this->notifRepo->getEntityManager()->flush();
        }

        return $count;
    }
}
