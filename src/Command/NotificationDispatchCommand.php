<?php

namespace App\Command;

use App\Entity\Partner;
use App\Repository\PartnerRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'notifications:dispatch',
    description: 'Envia notificacoes pendentes por parceiro'
)]
class NotificationDispatchCommand extends Command
{
    public function __construct(
        private readonly PartnerRepository $partnerRepository,
        private readonly UserRepository $userRepository,
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $partners = $this->partnerRepository->findBy(['active' => true]);
        $output->writeln('<info>Notification Dispatch — Multi-Tenant</info>');

        foreach ($partners as $partner) {
            $output->writeln(sprintf('<comment>Parceiro: %s</comment>', $partner->getName()));
            $recipients = $this->userRepository->findNotificationRecipientsByPartner($partner);

            if ($recipients === []) {
                $output->writeln('Nenhum administrador com e-mail configurado.');
                continue;
            }

            $output->writeln(sprintf('Destinatarios: %d', count($recipients)));
            foreach ($recipients as $recipient) {
                $output->writeln(sprintf(' - %s', $recipient->getEmail()));
            }
        }

        return Command::SUCCESS;
    }
}
