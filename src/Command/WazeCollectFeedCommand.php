<?php

namespace App\Command;

use App\Repository\WazeFeedRepository;
use App\Service\WazeFeedCollectionService;
use App\Service\WazeAlertSynchronizer;
use App\Service\WazeJamSynchronizer;
use App\Service\WazeEventLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'waze:collect-feed',
    description: 'Coleta alertas e congestionamentos dos feeds operacionais Waze (tipo EVENTS)'
)]
class WazeCollectFeedCommand extends Command
{
    public function __construct(
        private readonly WazeFeedRepository $feedRepository,
        private readonly WazeFeedCollectionService $collectionService,
        private readonly WazeAlertSynchronizer $alertSynchronizer,
        private readonly WazeJamSynchronizer $jamSynchronizer,
        private readonly WazeEventLifecycleService $lifecycleService,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('partner', 'p', InputOption::VALUE_OPTIONAL, 'Filtrar por partner ID');
        $this->addOption('feed', 'f', InputOption::VALUE_OPTIONAL, 'Filtrar por feed ID específico');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Apenas busca o feed, sem persistir');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');

        $feeds = $this->feedRepository->findActiveEventsFeeds();

        if ($partnerId = $input->getOption('partner')) {
            $feeds = array_filter($feeds, fn($f) => $f->getPartner()->getId() == (int)$partnerId);
        }
        if ($feedId = $input->getOption('feed')) {
            $feeds = array_filter($feeds, fn($f) => $f->getId() == (int)$feedId);
        }

        if (empty($feeds)) {
            $io->warning('Nenhum feed EVENTS ativo encontrado.');
            return Command::SUCCESS;
        }

        $totalAlerts = 0;
        $totalJams   = 0;
        $errors      = 0;

        foreach ($feeds as $feed) {
            $label = sprintf('[Feed #%d | %s | Partner #%d]',
                $feed->getId(),
                $feed->getLabel() ?? $feed->getFeedUuid(),
                $feed->getPartner()->getId()
            );

            if ($dryRun) {
                $io->note("$label dry-run, pulando persistência.");
                continue;
            }

            // Reabrir EM se fechou por erro anterior
            if (!$this->em->isOpen()) {
                $this->em->getConnection()->close();
                $this->em->getConnection()->connect();
            }

            $collection = $this->collectionService->start($feed);

            try {
                $response = $this->httpClient->request('GET', $feed->getEndpointUrl(), [
                    'timeout' => 30,
                    'headers' => ['Accept' => 'application/json'],
                ]);

                $payload     = $response->toArray();
                $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                $alertsCount = 0;
                foreach ($payload['alerts'] ?? [] as $alertData) {
                    $this->alertSynchronizer->upsert($feed, $collection, $alertData);
                    $alertsCount++;
                }

                $jamsCount = 0;
                foreach ($payload['jams'] ?? [] as $jamData) {
                    $this->jamSynchronizer->upsert($feed, $collection, $jamData);
                    $jamsCount++;
                }

                $this->collectionService->succeed($collection, $alertsCount, $jamsCount, 0, $payloadHash);
                $this->lifecycleService->markMissingAndDeactivateExpired($feed, $collection);

                $totalAlerts += $alertsCount;
                $totalJams   += $jamsCount;

                $io->writeln(sprintf('%s %d alerts, %d jams', $label, $alertsCount, $jamsCount));

            } catch (\Throwable $e) {
                $this->collectionService->fail($collection, $e);
                $io->error(sprintf('%s FALHOU: %s', $label, $e->getMessage()));
                $errors++;
            }
        }

        $io->success(sprintf('Concluído. Alerts: %d | Jams: %d | Erros: %d', $totalAlerts, $totalJams, $errors));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
