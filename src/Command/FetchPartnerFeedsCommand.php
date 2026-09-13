<?php

namespace App\Command;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fetch-partner-feeds',
    description: 'Busca e persiste os feeds dos partners configurados.'
)]
final class FetchPartnerFeedsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $partners = $this->entityManager
            ->getRepository(Partner::class)
            ->findBy([], ['lastFetchAt' => 'ASC', 'id' => 'ASC']);

        foreach ($partners as $partner) {
            $io->title(sprintf(
                'Processando partner %d — %s',
                $partner->getId(),
                $partner->getName()
            ));

            $partnerCity = $this->normalizeCity($partner->getCity());

            if ($partnerCity === null) {
                $io->warning(sprintf(
                    'Partner %d (%s) não possui cidade configurada; seus itens serão ignorados.',
                    $partner->getId(),
                    $partner->getName()
                ));
            }
        }

        return Command::SUCCESS;
    }

    private function resolveCity(array $item, Partner $partner): ?string
    {
        foreach (['city', 'municipality'] as $key) {
            $city = $this->normalizeCity($item[$key] ?? null);

            if ($city !== null) {
                return $city;
            }
        }

        return $this->normalizeCity($partner->getCity());
    }

    private function normalizeCity(mixed $city): ?string
    {
        if (!is_string($city) && !is_scalar($city)) {
            return null;
        }

        $city = trim((string) $city);

        return $city === '' ? null : $city;
    }

    private function persistFeedItem(array $item, Partner $partner): void
    {
        $city = $this->resolveCity($item, $partner);

        if ($city === null) {
            return;
        }

        $alert = new WazeAlert();
        $alert->setCity($city);
        $alert->setPartner($partner);

        $this->entityManager->persist($alert);
    }
}
