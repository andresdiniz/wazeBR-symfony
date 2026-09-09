<?php

namespace App\Command;

use App\Repository\WazeFeedRepository;
use App\Service\WazeFeedCollectionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'waze:collect-feed',
    description: 'Coleta alertas e congestionamentos de todos os feeds ativos'
)]
class WazeCollectFeedCommand extends Command
{
    public function __construct(
        private WazeFeedRepository $feedRepository,
        private WazeFeedCollectionService $collectionService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Coletando feeds Waze');

        $feeds = $this->feedRepository->findAllActive();

        if (empty($feeds)) {
            $io->warning('Nenhum feed ativo encontrado');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Encontrados %d feed(s) ativos', count($feeds)));

        $successCount = 0;
        $errorCount = 0;

        foreach ($feeds as $feed) {
            $partner = $feed->getPartner();
            $feedUuid = $feed->getFeedUuid();
            $label = $feed->getLabel() ?? 'Sem label';
            $feedType = $feed->getType() ?? 'UNKNOWN';

            $io->section(sprintf('Feed: %s (%s) - %s', $label, $feedUuid, $feedType));

            try {
                $result = $this->collectionService->collect($feed);
                
                $io->success(sprintf(
                    '%d alerts, %d jams, %d routes',
                    $result['alerts'] ?? 0,
                    $result['jams'] ?? 0,
                    $result['routes'] ?? 0
                ));
                
                $successCount++;
            } catch (\Throwable $e) {
                $io->error(sprintf('Erro: %s', $e->getMessage()));
                $errorCount++;
            }
        }

        $io->text(sprintf(
            '<info>Completed: %d successful, %d errors</info>',
            $successCount,
            $errorCount
        ));

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
