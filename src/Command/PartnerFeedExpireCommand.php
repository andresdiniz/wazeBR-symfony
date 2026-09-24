<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PartnerFeedEvent;
use App\Repository\PartnerFeedEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'partner-feed:expire',
    description: 'Desativa eventos do feed cujo endtime já passou.',
)]
final class PartnerFeedExpireCommand extends Command
{
    public function __construct(
        private readonly PartnerFeedEventRepository $eventRepository,
        private readonly EntityManagerInterface     $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $expired = $this->eventRepository->findExpired();

        if (empty($expired)) {
            $io->success('Nenhum evento expirado encontrado.');

            return Command::SUCCESS;
        }

        foreach ($expired as $event) {
            $event->setStatus(PartnerFeedEvent::STATUS_INACTIVE);
            $event->setUpdatedAt(new \DateTimeImmutable());
        }

        $this->em->flush();

        $io->success(sprintf('%d evento(s) expirado(s) desativado(s).', count($expired)));

        return Command::SUCCESS;
    }
}
