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
    public function __construct(
        private readonly PartnerRepository      $partnerRepo,
        private readonly WazeAlertRepository    $alertRepo,
        private readonly CemadenDataRepository  $cemadenRepo,
        private readonly UserRepository         $userRepo,
        private readonly NotificationRepository $notifRepo,
        private readonly TenantContext          $tenantContext,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Notification Dispatch — Multi-Tenant');

        foreach ($this->partnerRepo->findActivePartners() as $partner) {
            $this->tenantContext->setPartner($partner);
            $io->section("Parceiro: {$partner->getName()}");

            $admins = $this->userRepo->findAdminsByPartner($partner);
            if (empty($admins)) {
                $io->text('  Nenhum admin. Pulando.');
                continue;
            }

            $count = 0;
            $count += $this->dispatchAlertNotifications($partner, $admins);
            $count += $this->dispatchCemadenNotifications($partner, $admins);

            $io->success("{$count} notificações geradas.");
        }

        return Command::SUCCESS;
    }

    private function dispatchAlertNotifications(Partner $partner, array $admins): int
    {
        $critical = $this->alertRepo->findCriticalByPartner($partner, minReliability: 8);
        $count = 0;

        foreach ($critical as $alert) {
            foreach ($admins as $user) {
                $exists = $this->notifRepo->existsForAlert($user, $alert->getWazeId());
                if ($exists) continue;

                $notif = (new Notification())
                    ->setPartner($partner)
                    ->setUser($user)
                    ->setType('waze_alert')
                    ->setTitle("Alerta {$alert->getType()} — {$alert->getCity()}")
                    ->setBody("{$alert->getStreet()} | Confiança: {$alert->getConfidence()}");

                $this->notifRepo->save($notif, false);
                $count++;
            }
        }

        if ($count > 0) $this->notifRepo->getEntityManager()->flush();
        return $count;
    }

    private function dispatchCemadenNotifications(Partner $partner, array $admins): int
    {
        $critical = $this->cemadenRepo->findByPartnerAndLevels($partner, ['VERMELHO', 'LARANJA']);
        $count = 0;

        foreach ($critical as $item) {
            foreach ($admins as $user) {
                $exists = $this->notifRepo->existsForCemaden($user, $item->getStationCode(), $item->getMeasuredAt());
                if ($exists) continue;

                $notif = (new Notification())
                    ->setPartner($partner)
                    ->setUser($user)
                    ->setType('cemaden')
                    ->setTitle("Alerta {$item->getAlertLevel()} — {$item->getMunicipality()}/{$item->getState()}")
                    ->setBody("{$item->getStationName()} | Chuva: {$item->getAccumulatedRain()}mm");

                $this->notifRepo->save($notif, false);
                $count++;
            }
        }

        if ($count > 0) $this->notifRepo->getEntityManager()->flush();
        return $count;
    }
}
