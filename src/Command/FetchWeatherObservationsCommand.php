<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WeatherObservationFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Command\AbstractCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:weather:fetch-observations',
    description: 'Fetches weather observations from INMET API',
    hidden: false,
)]
final class FetchWeatherObservationsCommand extends AbstractCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WeatherObservationFetcher $observationFetcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->observationFetcher->fetchAndStoreObservations();
            $io->success('Weather observations fetched and stored successfully.');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to fetch weather observations: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
