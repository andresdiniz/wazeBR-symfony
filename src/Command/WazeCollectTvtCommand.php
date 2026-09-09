<?php

namespace App\Command;

use App\Repository\WazeFeedRepository;
use App\Service\WazeFeedCollectionService;
use App\Service\WazeTvtSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'waze:collect-tvt',
    description: 'Coleta rotas TVT dos feeds Waze (tipo TVT)'
)]
class WazeCollectTvtCommand extends Command
{
    public function __construct(
        private readonly WazeFeedRepository $feedRepository,
        private readonly WazeFeedCollectionService $collectionService,
        private readonly WazeTvtSynchronizer $tvtSynchronizer,
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

        $feeds = $this->feedRepository->findActiveTvtFeeds();

        if ($partnerId = $input->getOption('partner')) {
            $feeds = array_filter($feeds, fn($f) => $f->getPartner()->getId() == (int)$partnerId);
        }
        if ($feedId = $input->getOption('feed')) {
            $feeds = array_filter($feeds, fn($f) => $f->getId() == (int)$feedId);
        }

        if (empty($feeds)) {
            $io->warning('Nenhum feed TVT ativo encontrado.');
            return Command::SUCCESS;
        }

        $totalRoutes = 0;
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

                // Payload TVT pode ter rotas em 'routes', 'tvtRoutes' ou ser objeto único
                $routes = $payload['routes'] ?? $payload['tvtRoutes'] ?? (isset($payload['id']) ? [$payload] : []);

                $routesCount = 0;
                foreach ($routes as $routeData) {
                    $this->tvtSynchronizer->upsert($feed, $collection, $routeData);
                    $routesCount++;
                }

                $this->collectionService->succeed($collection, 0, 0, $routesCount, $payloadHash);
                $totalRoutes += $routesCount;

                $io->writeln(sprintf('%s %d rotas', $label, $routesCount));

            } catch (\Throwable $e) {
                $this->collectionService->fail($collection, $e);
                $io->error(sprintf('%s FALHOU: %s', $label, $e->getMessage()));
                $errors++;
            }
        }

        $io->success(sprintf('Concluído. Rotas: %d | Erros: %d', $totalRoutes, $errors));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
