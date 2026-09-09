<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PartnerRepository;
use App\Service\WazeFeedCollectionService;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Doctrine\ORM\ORMException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'waze:collect-all-tvt',
    description: 'Collects TVT (Travel Time) data from Waze Partner API for all partners and feeds',
)]
class WazeCollectAllTvtCommand extends Command
{
    public function __construct(
        private readonly PartnerRepository $partnerRepo,
        private readonly WazeFeedCollectionService $collectionService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not persist data')
            ->addOption('partner', 'p', InputOption::VALUE_REQUIRED, 'Filter by specific partner ID')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = $input->getOption('dry-run');
        $partnerFilter = $input->getOption('partner');

        if ($dryRun) {
            $io->note('DRY RUN: No data will be persisted');
        }

        $partners = $partnerFilter ? [$this->partnerRepo->find($partnerFilter)] : $this->partnerRepo->findAll();

        if (empty($partners)) {
            $io->warning('No partners found');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Collecting TVT feeds for %d partner(s)', count($partners)));

        $totalSuccess = 0;
        $totalErrors = 0;

        foreach ($partners as $partner) {
            $io->section(sprintf('Partner: %s (%d)', $partner->getName(), $partner->getId()));

            $feeds = $partner->getWazeFeeds()->toArray();

            if (empty($feeds)) {
                $io->text('  No feeds configured');
                continue;
            }

            foreach ($feeds as $feed) {
                $feedUuid = $feed->getFeedUuid();
                $io->text(sprintf('  Feed: %s', $feedUuid));

                try {
                    $result = $this->collectionService->collect($feed, $dryRun);

                    if (!$dryRun) {
                        $feedCollection = $this->collectionService->getLastFeedCollection($feed);
                        if ($feedCollection) {
                            $this->collectionService->success($feedCollection);
                        }
                    }

                    $io->text(sprintf(
                        '    ✓ %d routes',
                        $result['routes'] ?? 0
                    ));
                    $totalSuccess++;
                } catch (EntityManagerClosed $e) {
                    $this->logger->critical('EntityManager fechado durante coleta TVT', [
                        'partner' => $partner->getId(),
                        'feed' => $feedUuid,
                        'message' => $e->getMessage(),
                    ]);
                    $io->text(sprintf('    ✗ Critical error: %s', $e->getMessage()));
                    $totalErrors++;
                } catch (ORMException $e) {
                    $this->logger->error('Erro ORM durante coleta TVT', [
                        'partner' => $partner->getId(),
                        'feed' => $feedUuid,
                        'message' => $e->getMessage(),
                    ]);
                    $io->text(sprintf('    ✗ DB error: %s', $e->getMessage()));
                    $totalErrors++;
                } catch (\Throwable $e) {
                    $this->logger->error('Erro inesperado durante coleta TVT', [
                        'partner' => $partner->getId(),
                        'feed' => $feedUuid,
                        'message' => $e->getMessage(),
                    ]);
                    $io->text(sprintf('    ✗ Error: %s', $e->getMessage()));
                    $totalErrors++;
                }
            }
        }

        $io->newLine();
        $io->text(sprintf('Completed: %d successful, %d errors', $totalSuccess, $totalErrors));

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
