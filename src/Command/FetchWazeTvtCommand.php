<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Entity\WazeTvtIrregularity;
use App\Entity\WazeTvtRoute;
use App\Entity\WazeTvtRouteSnapshot;
use App\Entity\WazeTvtSubRoute;
use App\Entity\WazeTvtUserOnJam;
use App\Repository\PartnerApiLinkRepository;
use App\Repository\WazeTvtRouteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:fetch:waze:tvt',
    description: 'Coleta dados de rotas TVT do Waze para todos os partners configurados.',
)]
class FetchWazeTvtCommand extends Command
{
    private const TYPE = 'TVT';

    public function __construct(
        private readonly PartnerApiLinkRepository $apiLinkRepository,
        private readonly WazeTvtRouteRepository $routeRepository,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner', null, InputOption::VALUE_REQUIRED, 'ID do partner (processa só esse partner)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem persistir no banco');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $partnerId = $input->getOption('partner');

        $io->title('Coleta Waze TVT');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN — nenhum dado será persistido.');
        }

        $apiLinks = $this->apiLinkRepository->findAllByType(self::TYPE);

        if ($partnerId !== null) {
            $apiLinks = array_filter(
                $apiLinks,
                static fn ($al) => (string) $al->getPartner()->getId() === (string) $partnerId,
            );
            $apiLinks = array_values($apiLinks);
        }

        if (empty($apiLinks)) {
            $io->info(sprintf('Nenhum partner com type "%s" configurado.', self::TYPE));
            return Command::SUCCESS;
        }

        $io->info(sprintf('Processando %d partner(s) com TVT configurado.', count($apiLinks)));

        $errors = 0;

        foreach ($apiLinks as $apiLink) {
            $partner = $apiLink->getPartner();
            $label = sprintf('[Partner %d — %s]', $partner->getId(), $partner->getName());

            try {
                $io->section($label);
                $payload = $this->fetchTvtFeed($apiLink->getUrl(), $apiLink->getToken());
                $this->processFeed($payload, $partner, $dryRun, $io);
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('{label}: erro ao processar feed TVT — {msg}', [
                    'label' => $label,
                    'msg' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $io->error(sprintf('%s %s', $label, $e->getMessage()));
            }
        }

        $io->success('Coleta TVT concluí·ª.');

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processFeed(array $payload, Partner $partner, bool $dryRun, SymfonyStyle $io): void
    {
        $recordedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $routes = $payload['routes'] ?? [];
        $usersOnJam = $payload['usersOnJam'] ?? null;

        if ($usersOnJam !== null) {
            $this->persistUserOnJam($partner, $usersOnJam, null, $recordedAt, $dryRun);
        }

        foreach ($routes as $routeData) {
            $wazeRouteId = (string) ($routeData['id'] ?? '');

            if ($wazeRouteId === '') {
                $this->logger->warning('[TVT] Rota sem id no payload — ignorada.');
                continue;
            }

            $route = $this->upsertRoute($partner, $wazeRouteId, $routeData, $dryRun);
            $this->persistSnapshot($partner, $route, $wazeRouteId, $routeData, $recordedAt, $dryRun);

            if (isset($routeData['usersOnJam'])) {
                $this->persistUserOnJam($partner, $routeData['usersOnJam'], $route, $recordedAt, $dryRun);
            }

            foreach ($routeData['subRoutes'] ?? [] as $subRouteData) {
                $wazeSubRouteId = (string) ($subRouteData['id'] ?? '');
                if ($wazeSubRouteId === '') continue;

                $subRoute = $this->upsertSubRoute($partner, $route, $wazeRouteId, $wazeSubRouteId, $subRouteData, $dryRun);

                foreach ($subRouteData['irregularities'] ?? [] as $irregData) {
                    $this->persistIrregularity($partner, $route, $subRoute, $wazeRouteId, $wazeSubRouteId, $irregData, $recordedAt, $dryRun);
                }
            }

            foreach ($routeData['irregularities'] ?? [] as $irregData) {
                $this->persistIrregularity($partner, $route, null, $wazeRouteId, null, $irregData, $recordedAt, $dryRun);
            }

            $io->writeln(sprintf(
                '  Rota %s — subRoutes: %d, irregularidades: %d',
                $wazeRouteId,
                count($routeData['subRoutes'] ?? []),
                count($routeData['irregularities'] ?? []),
            ));
        }

        if (!$dryRun) {
            $this->em->flush();
        }
    }

    private function upsertRoute(Partner $partner, string $wazeRouteId, array $data, bool $dryRun): WazeTvtRoute
    {
        $route = $this->routeRepository->findOneByPartnerAndRouteId($partner, $wazeRouteId);

        if ($route === null) {
            $route = new WazeTvtRoute();
            $route->setPartner($partner)->setRouteId($wazeRouteId);
            if (!$dryRun) $this->em->persist($route);
        }

        $route->setName($data['name'] ?? null)
            ->setFromName($data['fromName'] ?? null)
            ->setToName($data['toName'] ?? null)
            ->setLength($data['length'] ?? null)
            ->setGeometry($data['line'] ?? null);

        return $route;
    }

    private function upsertSubRoute(Partner $partner, WazeTvtRoute $route, string $wazeRouteId, string $wazeSubRouteId, array $data, bool $dryRun): WazeTvtSubRoute
    {
        $subRoute = $this->em->getRepository(WazeTvtSubRoute::class)->findOneBy([
            'partner' => $partner,
            'route' => $route,
            'subRouteId' => $wazeSubRouteId,
        ]);

        if ($subRoute === null) {
            $subRoute = new WazeTvtSubRoute();
            $subRoute->setPartner($partner)->setRoute($route)->setWazeRouteId($wazeRouteId)->setSubRouteId($wazeSubRouteId);
            if (!$dryRun) $this->em->persist($subRoute);
        }

        $subRoute->setName($data['name'] ?? null)
            ->setFromName($data['fromName'] ?? null)
            ->setToName($data['toName'] ?? null)
            ->setLength($data['length'] ?? null)
            ->setTime($data['time'] ?? null)
            ->setHistoricTime($data['historicTime'] ?? null)
            ->setJamLevel($data['jamLevel'] ?? null)
            ->setLine($data['line'] ?? null)
            ->setBbox($data['bbox'] ?? null)
            ->setIrregularities($data['irregularities'] ?? null);

        return $subRoute;
    }

    private function persistSnapshot(Partner $partner, WazeTvtRoute $route, string $wazeRouteId, array $data, \DateTimeImmutable $recordedAt, bool $dryRun): void
    {
        $snapshot = new WazeTvtRouteSnapshot();
        $snapshot->setPartner($partner)->setRoute($route)->setWazeRouteId($wazeRouteId)
            ->setTime($data['time'] ?? null)
            ->setHistoricTime($data['historicTime'] ?? null)
            ->setJamLevel($data['jamLevel'] ?? null)
            ->setRecordedAt($recordedAt);

        if (!$dryRun) $this->em->persist($snapshot);
    }

    private function persistUserOnJam(Partner $partner, array $data, ?WazeTvtRoute $route, \DateTimeImmutable $recordedAt, bool $dryRun): void
    {
        $entity = new WazeTvtUserOnJam();
        $entity->setPartner($partner)->setRoute($route)->setWazeRouteId($route?->getRouteId())
            ->setWazersCount((int) ($data['wazersCount'] ?? $data['count'] ?? 0))
            ->setJamLevel($data['jamLevel'] ?? null)
            ->setRecordedAt($recordedAt);

        if (!$dryRun) $this->em->persist($entity);
    }

    private function persistIrregularity(Partner $partner, WazeTvtRoute $route, ?WazeTvtSubRoute $subRoute, string $wazeRouteId, ?string $wazeSubRouteId, array $data, \DateTimeImmutable $recordedAt, bool $dryRun): void
    {
        $contentHash = $this->computeContentHash($data);

        $criteria = [
            'partner' => $partner,
            'route' => $route,
            'subRoute' => $subRoute,
            'contentHash' => $contentHash,
        ];

        $existing = $this->em->getRepository(WazeTvtIrregularity::class)->findOneBy($criteria);

        if ($existing !== null) {
            $existing->setUpdatedAt($recordedAt);
            return;
        }

        $irregularity = new WazeTvtIrregularity();
        $irregularity->setPartner($partner)->setRoute($route)->setSubRoute($subRoute)
            ->setWazeRouteId($wazeRouteId)
            ->setWazeSubRouteId($wazeSubRouteId)
            ->setType($data['type'] ?? null)
            ->setSubtype($data['subtype'] ?? null)
            ->setDescription($data['description'] ?? null)
            ->setPayload($data)
            ->setContentHash($contentHash)
            ->setRecordedAt($recordedAt)
            ->setIsActive(true);

        if (!$dryRun) $this->em->persist($irregularity);
    }

    private function computeContentHash(array $data): string
    {
        $canonical = $data;
        unset($canonical['recordedAt'], $canonical['updatedAt'], $canonical['createdAt']);
        ksort($canonical);
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function fetchTvtFeed(string $url, ?string $token): array
    {
        $options = ['timeout' => 15];
        if ($token !== null && $token !== '') $options['headers'] = ['Authorization' => 'Bearer ' . $token];
        $response = $this->httpClient->request('GET', $url, $options);
        return $response->toArray();
    }
}
