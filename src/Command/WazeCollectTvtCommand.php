<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PartnerRepository;
use App\Repository\WazeFeedRepository;
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
    name: 'waze:collect-tvt',
    description: 'Collects TVT (Travel Time) data from Waze Partner API',
)]
class WazeCollectTvtCommand extends Command
{
    public function __construct(
        private readonly PartnerRepository $partnerRepo,
        private readonly WazeFeedRepository $feedRepo,
        private readonly WazeFeedCollectionService $collectionService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', 'p', InputOption::VALUE_REQUIRED, 'Partner ID')
            ->addOption('feed', 'f', InputOption::VALUE_REQUIRED, 'Feed UUID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not persist data')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $partnerId = $input->getOption('partner');
        $feedUuid = $input->getOption('feed');
        $dryRun = $input->getOption('dry-run');

        if (!$partnerId || !$feedUuid) {
            $io->error('Options --partner and --feed are required');
            return Command::FAILURE;
        }

        $feed = $this->feedRepo->findOneBy(['partner' => $this->partnerRepo->find($partnerId), 'feedUuid' => $feedUuid]);

        if (!$feed) {
            $io->error(sprintf('Feed with UUID %s not found for partner %s', $feedUuid, $partnerId));
            return Command::FAILURE;
        }

        $io->text(sprintf('Collecting TVT feed %s for partner %s (%s)', $feedUuid, $feed->getPartner()->getName(), $feed->getPartner()->getId()));

        if ($dryRun) {
            $io->note('DRY RUN: No data will be persisted');
        }

        try {
            $result = $this->collectionService->collect($feed, $dryRun);

            if (!$dryRun) {
                $feedCollection = $this->collectionService->getLastFeedCollection($feed);
                if ($feedCollection) {
                    $this->collectionService->success($feedCollection);
                }
            }

            $io->success(sprintf('Collection completed: %d routes', $result['routes'] ?? 0));

            return Command::SUCCESS;
        } catch (EntityManagerClosed $e) {
            $this->logger->critical('EntityManager fechado durante coleta TVT', [
                'partner' => $partnerId,
                'feed' => $feedUuid,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $io->error('Erro crítico: EntityManager fechado. Verifique os logs para detalhes.');

            try {
                $feedCollection = $this->collectionService->getLastFeedCollection($feed);
                if ($feedCollection) {
                    $this->collectionService->fail($e->getMessage(), $feedCollection);
                }
            } catch (\Throwable $failError) {
                $this->logger->error('Falha ao marcar coleta como erro', [
                    'message' => $failError->getMessage(),
                ]);
            }

            return Command::FAILURE;
        } catch (ORMException $e) {
            $this->logger->error('Erro ORM durante coleta TVT', [
                'partner' => $partnerId,
                'feed' => $feedUuid,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $io->error(sprintf('Erro de persistencia: %s', $e->getMessage()));

            try {
                $feedCollection = $this->collectionService->getLastFeedCollection($feed);
                if ($feedCollection) {
                    $this->collectionService->fail($e->getMessage(), $feedCollection);
                }
            } catch (\Throwable $failError) {
                $this->logger->error('Falha ao marcar coleta como erro', [
                    'message' => $failError->getMessage(),
                ]);
            }

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->logger->error('Erro inesperado durante coleta TVT', [
                'partner' => $partnerId,
                'feed' => $feedUuid,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $io->error(sprintf('Erro: %s', $e->getMessage()));

            try {
                $feedCollection = $this->collectionService->getLastFeedCollection($feed);
                if ($feedCollection) {
                    $this->collectionService->fail($e->getMessage(), $feedCollection);
                }
            } catch (\Throwable $failError) {
                $this->logger->error('Falha ao marcar coleta como erro', [
                    'message' => $failError->getMessage(),
                ]);
            }

            return Command::FAILURE;
        }
    }
}
