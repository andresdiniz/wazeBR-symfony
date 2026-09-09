<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
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
    name: 'waze:collect-tvt',
    description: 'Collects TVT (Travel Time) data from Waze Partner API',
)]
class WazeCollectTvtCommand extends Command
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
            ->addOption('partner', 'p', InputOption::VALUE_REQUIRED, 'Partner ID')
            ->addOption('feed', 'f', InputOption::VALUE_REQUIRED, 'Feed ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not persist data')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $partnerId = $input->getOption('partner');
        $feedId = $input->getOption('feed');
        $dryRun = $input->getOption('dry-run');

        if (!$partnerId || !$feedId) {
            $io->error('Options --partner and --feed are required');
            return Command::FAILURE;
        }

        /** @var Partner|null $partner */
        $partner = $this->partnerRepo->find($partnerId);

        if (!$partner) {
            $io->error(sprintf('Partner with ID %s not found', $partnerId));
            return Command::FAILURE;
        }

        $io->text(sprintf('Collecting TVT feed %s for partner %s (%s)', $feedId, $partner->getName(), $partner->getId()));

        if ($dryRun) {
            $io->note('DRY RUN: No data will be persisted');
        }

        try {
            $result = $this->collectionService->collect($partner, $feedId, $dryRun);

            if (!$dryRun) {
                $feedCollection = $this->collectionService->getLastFeedCollection($partner, $feedId);
                if ($feedCollection) {
                    $this->collectionService->success($feedCollection);
                }
            }

            $io->success(sprintf(
                'Collection completed: %d routes, %d definitions, %d history items',
                $result['routes'] ?? 0,
                $result['definitions'] ?? 0,
                $result['history'] ?? 0
            ));

            return Command::SUCCESS;
        } catch (EntityManagerClosed $e) {
            $this->logger->critical('EntityManager fechado durante coleta TVT', [
                'partner' => $partnerId,
                'feed' => $feedId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $io->error('Erro crítico: EntityManager fechado. Verifique os logs para detalhes.');

            try {
                $feedCollection = $this->collectionService->getLastFeedCollection($partner, $feedId);
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
                'feed' => $feedId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $io->error(sprintf('Erro de persistencia: %s', $e->getMessage()));

            try {
                $feedCollection = $this->collectionService->getLastFeedCollection($partner, $feedId);
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
                'feed' => $feedId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $io->error(sprintf('Erro: %s', $e->getMessage()));

            try {
                $feedCollection = $this->collectionService->getLastFeedCollection($partner, $feedId);
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
