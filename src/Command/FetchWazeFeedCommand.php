<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Entity\WazeAlert;
use App\Entity\WazeJam;
use App\Repository\PartnerApiLinkRepository;
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
    name: 'app:fetch:waze:feed',
    description: 'Coleta alertas e congestionamentos do feed Waze para todos os partners configurados.',
)]
class FetchWazeFeedCommand extends Command
{
    private const TYPE = 'Alerts';

    public function __construct(
        private readonly PartnerApiLinkRepository $apiLinkRepository,
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

        $io->title('Coleta Waze Feed (Alertas e Jams)');

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

        $io->info(sprintf('Processando %d partner(s).', count($apiLinks)));

        $errors = 0;

        foreach ($apiLinks as $apiLink) {
            $partner = $apiLink->getPartner();
            $label = sprintf('[Partner %d — %s]', $partner->getId(), $partner->getName());

            try {
                $io->section($label);
                $payload = $this->fetchFeed($apiLink->getUrl());
                $this->processFeed($payload, $partner, $dryRun, $io);
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('{label}: erro ao processar feed Waze — {msg}', [
                    'label' => $label,
                    'msg' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $io->error(sprintf('%s %s', $label, $e->getMessage()));
            }
        }

        $io->success('Coleta Waze Feed concluí·ª.');

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processFeed(array $payload, Partner $partner, bool $dryRun, SymfonyStyle $io): void
    {
        $alertsData = $payload['alerts'] ?? [];
        $jamsData = $payload['jams'] ?? [];

        $activeAlertUuids = [];
        $alertsInserted = 0;
        $alertsUpdated = 0;

        foreach ($alertsData as $alertData) {
            $uuid = (string) ($alertData['uuid'] ?? '');
            if ($uuid === '') continue;
            $activeAlertUuids[] = $uuid;

            $existing = $this->em->getRepository(WazeAlert::class)->findOneBy(['partner' => $partner, 'uuid' => $uuid]);

            if ($existing === null) {
                $entity = new WazeAlert();
                $entity->setPartner($partner)->setUuid($uuid);
                $this->mapAlertFields($entity, $alertData);
                $entity->setIsActive(true);
                if (!$dryRun) $this->em->persist($entity);
                ++$alertsInserted;
            } else {
                $this->mapAlertFields($existing, $alertData);
                $existing->setIsActive(true);
                ++$alertsUpdated;
            }
        }

        if (!$dryRun && !empty($activeAlertUuids)) {
            $this->deactivateMissing(WazeAlert::class, $partner, $activeAlertUuids, 'uuid');
        } elseif (!$dryRun && empty($activeAlertUuids)) {
            $this->deactivateAll(WazeAlert::class, $partner);
        }

        $activeJamUuids = [];
        $jamsInserted = 0;
        $jamsUpdated = 0;

        foreach ($jamsData as $jamData) {
            $uuid = (string) ($jamData['uuid'] ?? '');
            if ($uuid === '') continue;
            $activeJamUuids[] = $uuid;

            $existing = $this->em->getRepository(WazeJam::class)->findOneBy(['partner' => $partner, 'uuid' => $uuid]);

            if ($existing === null) {
                $entity = new WazeJam();
                $entity->setPartner($partner)->setUuid($uuid);
                $this->mapJamFields($entity, $jamData);
                $entity->setIsActive(true);
                if (!$dryRun) $this->em->persist($entity);
                ++$jamsInserted;
            } else {
                $this->mapJamFields($existing, $jamData);
                $existing->setIsActive(true);
                ++$jamsUpdated;
            }
        }

        if (!$dryRun && !empty($activeJamUuids)) {
            $this->deactivateMissing(WazeJam::class, $partner, $activeJamUuids, 'uuid');
        } elseif (!$dryRun && empty($activeJamUuids)) {
            $this->deactivateAll(WazeJam::class, $partner);
        }

        if (!$dryRun) $this->em->flush();

        $io->writeln(sprintf('  Alertas → inseridos: %d, atualizados: %d | Jams → inseridos: %d, atualizados: %d', $alertsInserted, $alertsUpdated, $jamsInserted, $jamsUpdated));
    }

    private function mapAlertFields(WazeAlert $entity, array $data): void
    {
        $entity->setType($data['type'] ?? null)
            ->setSubtype($data['subtype'] ?? null)
            ->setStreet($data['street'] ?? null)
            ->setCity($data['city'] ?? null)
            ->setCountry($data['country'] ?? null)
            ->setLatitude(isset($data['location']['y']) ? (float) $data['location']['y'] : null)
            ->setLongitude(isset($data['location']['x']) ? (float) $data['location']['x'] : null)
            ->setReliability($data['reliability'] ?? null)
            ->setConfidence($data['confidence'] ?? null)
            ->setReportRating($data['reportRating'] ?? null)
            ->setNThumbsUp($data['nThumbsUp'] ?? null)
            ->setPayload($data);
    }

    private function mapJamFields(WazeJam $entity, array $data): void
    {
        $entity->setUuid($data['uuid'] ?? null)
            ->setStreet($data['street'] ?? null)
            ->setCity($data['city'] ?? null)
            ->setCountry($data['country'] ?? null)
            ->setLevel($data['level'] ?? null)
            ->setSpeedKmh($data['speedKMH'] ?? null)
            ->setLength($data['length'] ?? null)
            ->setDelay($data['delay'] ?? null)
            ->setLine($data['line'] ?? null)
            ->setPayload($data);
    }

    private function deactivateMissing(string $entityClass, Partner $partner, array $activeUuids, string $uuidField): void
    {
        $this->em->createQueryBuilder()->update($entityClass, 'e')->set('e.isActive', ':inactive')
            ->where('e.partner = :partner')->andWhere('e.isActive = true')->andWhere(sprintf('e.%s NOT IN (:uuids)', $uuidField))
            ->setParameter('inactive', false)->setParameter('partner', $partner)->setParameter('uuids', $activeUuids)
            ->getQuery()->execute();
    }

    private function deactivateAll(string $entityClass, Partner $partner): void
    {
        $this->em->createQueryBuilder()->update($entityClass, 'e')->set('e.isActive', ':inactive')
            ->where('e.partner = :partner')->andWhere('e.isActive = true')
            ->setParameter('inactive', false)->setParameter('partner', $partner)
            ->getQuery()->execute();
    }

    private function fetchFeed(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 15]);
        return $response->toArray();
    }
}
