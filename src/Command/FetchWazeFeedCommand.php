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

/**
 * Coleta alertas e congestionamentos do feed Waze (non-TVT) para todos os partners
 * que possuem um PartnerApiLink com provider = 'waze_feed'.
 *
 * Estratégia:
 *   - Cada leitura do feed é tratada como snapshot do estado atual.
 *   - Alertas/jams identificados pelo uuid do Waze:
 *       → INSERT se uuid não existe ainda para o partner.
 *       → UPDATE is_active e campos mutáveis se já existe.
 *   - Registros anteriores não retornados pelo feed ficam com is_active = 0
 *     (desativados em lote após o processamento).
 *
 * Uso:
 *   php bin/console app:fetch:waze:feed
 *   php bin/console app:fetch:waze:feed --partner=42
 *   php bin/console app:fetch:waze:feed --dry-run
 *
 * Agendamento sugerido: a cada 2-5 minutos.
 */
#[AsCommand(
    name: 'app:fetch:waze:feed',
    description: 'Coleta alertas e congestionamentos do feed Waze para todos os partners configurados.',
)]
class FetchWazeFeedCommand extends Command
{
    private const PROVIDER = 'waze_feed';

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

        // ── 1. Selecionar partners com link de feed Waze ─────────────────────
        $apiLinks = $this->apiLinkRepository->findAllByProvider(self::PROVIDER);

        if ($partnerId !== null) {
            $apiLinks = array_filter(
                $apiLinks,
                static fn ($al) => (string) $al->getPartner()->getId() === (string) $partnerId,
            );
            $apiLinks = array_values($apiLinks);
        }

        if (empty($apiLinks)) {
            $io->info('Nenhum partner com provider "waze_feed" configurado.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Processando %d partner(s).', count($apiLinks)));

        $errors = 0;

        foreach ($apiLinks as $apiLink) {
            $partner = $apiLink->getPartner();
            $label = sprintf('[Partner %d — %s]', $partner->getId(), $partner->getName());

            try {
                $io->section($label);
                $payload = $this->fetchFeed($apiLink->getBaseUrl(), $apiLink->getToken());
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

        $io->success('Coleta Waze Feed concluída.');

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // ── Feed processing ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private function processFeed(
        array $payload,
        Partner $partner,
        bool $dryRun,
        SymfonyStyle $io,
    ): void {
        $alertsData = $payload['alerts'] ?? [];
        $jamsData = $payload['jams'] ?? [];

        // ── A. Processar Alertas ─────────────────────────────────────────────
        $activeAlertUuids = [];
        $alertsInserted = 0;
        $alertsUpdated = 0;

        foreach ($alertsData as $alertData) {
            $uuid = (string) ($alertData['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $activeAlertUuids[] = $uuid;

            $existing = $this->em->getRepository(WazeAlert::class)->findOneBy([
                'partner' => $partner,
                'uuid' => $uuid,
            ]);

            if ($existing === null) {
                $entity = new WazeAlert();
                $entity->setPartner($partner);
                $entity->setUuid($uuid);
                $this->mapAlertFields($entity, $alertData);
                $entity->setIsActive(true);

                if (!$dryRun) {
                    $this->em->persist($entity);
                }

                ++$alertsInserted;
            } else {
                $this->mapAlertFields($existing, $alertData);
                $existing->setIsActive(true);
                ++$alertsUpdated;
            }
        }

        // Desativar alertas que não vieram no feed atual
        if (!$dryRun && !empty($activeAlertUuids)) {
            $this->deactivateMissing(WazeAlert::class, $partner, $activeAlertUuids, 'uuid');
        } elseif (!$dryRun && empty($activeAlertUuids)) {
            $this->deactivateAll(WazeAlert::class, $partner);
        }

        // ── B. Processar Jams ────────────────────────────────────────────────
        $activeJamUuids = [];
        $jamsInserted = 0;
        $jamsUpdated = 0;

        foreach ($jamsData as $jamData) {
            $uuid = (string) ($jamData['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $activeJamUuids[] = $uuid;

            $existing = $this->em->getRepository(WazeJam::class)->findOneBy([
                'partner' => $partner,
                'uuid' => $uuid,
            ]);

            if ($existing === null) {
                $entity = new WazeJam();
                $entity->setPartner($partner);
                $entity->setUuid($uuid);
                $this->mapJamFields($entity, $jamData);
                $entity->setIsActive(true);

                if (!$dryRun) {
                    $this->em->persist($entity);
                }

                ++$jamsInserted;
            } else {
                $this->mapJamFields($existing, $jamData);
                $existing->setIsActive(true);
                ++$jamsUpdated;
            }
        }

        // Desativar jams que não vieram no feed atual
        if (!$dryRun && !empty($activeJamUuids)) {
            $this->deactivateMissing(WazeJam::class, $partner, $activeJamUuids, 'uuid');
        } elseif (!$dryRun && empty($activeJamUuids)) {
            $this->deactivateAll(WazeJam::class, $partner);
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->writeln(sprintf(
            '  Alertas → inseridos: %d, atualizados: %d | Jams → inseridos: %d, atualizados: %d',
            $alertsInserted,
            $alertsUpdated,
            $jamsInserted,
            $jamsUpdated,
        ));
    }

    // ── Mapeamento de campos ──────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    private function mapAlertFields(WazeAlert $entity, array $data): void
    {
        $entity->setType($data['type'] ?? null);
        $entity->setSubtype($data['subtype'] ?? null);
        $entity->setStreet($data['street'] ?? null);
        $entity->setCity($data['city'] ?? null);
        $entity->setCountry($data['country'] ?? null);
        $entity->setLatitude(isset($data['location']['y']) ? (float) $data['location']['y'] : null);
        $entity->setLongitude(isset($data['location']['x']) ? (float) $data['location']['x'] : null);
        $entity->setReliability($data['reliability'] ?? null);
        $entity->setConfidence($data['confidence'] ?? null);
        $entity->setReportRating($data['reportRating'] ?? null);
        $entity->setNThumbsUp($data['nThumbsUp'] ?? null);
        $entity->setPayload($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mapJamFields(WazeJam $entity, array $data): void
    {
        $entity->setUuid($data['uuid'] ?? null);
        $entity->setStreet($data['street'] ?? null);
        $entity->setCity($data['city'] ?? null);
        $entity->setCountry($data['country'] ?? null);
        $entity->setLevel($data['level'] ?? null);
        $entity->setSpeedKmh($data['speedKMH'] ?? null);
        $entity->setLength($data['length'] ?? null);
        $entity->setDelay($data['delay'] ?? null);
        $entity->setLine($data['line'] ?? null);
        $entity->setPayload($data);
    }

    // ── Helpers de ciclo de vida (is_active) ──────────────────────────────────

    /**
     * Desativa (is_active = 0) registros do partner cujos uuids NÃO estão
     * na lista de ativos retornada pelo feed.
     *
     * @param class-string         $entityClass
     * @param list<string>         $activeUuids
     */
    private function deactivateMissing(
        string $entityClass,
        Partner $partner,
        array $activeUuids,
        string $uuidField,
    ): void {
        $this->em->createQueryBuilder()
            ->update($entityClass, 'e')
            ->set('e.isActive', ':inactive')
            ->where('e.partner = :partner')
            ->andWhere('e.isActive = true')
            ->andWhere(sprintf('e.%s NOT IN (:uuids)', $uuidField))
            ->setParameter('inactive', false)
            ->setParameter('partner', $partner)
            ->setParameter('uuids', $activeUuids)
            ->getQuery()
            ->execute();
    }

    /**
     * Desativa todos os registros ativos de um partner (quando o feed retorna vazio).
     *
     * @param class-string $entityClass
     */
    private function deactivateAll(string $entityClass, Partner $partner): void
    {
        $this->em->createQueryBuilder()
            ->update($entityClass, 'e')
            ->set('e.isActive', ':inactive')
            ->where('e.partner = :partner')
            ->andWhere('e.isActive = true')
            ->setParameter('inactive', false)
            ->setParameter('partner', $partner)
            ->getQuery()
            ->execute();
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function fetchFeed(string $baseUrl, ?string $token): array
    {
        $options = ['timeout' => 15];

        if ($token !== null && $token !== '') {
            $options['headers'] = ['Authorization' => 'Bearer ' . $token];
        }

        $response = $this->httpClient->request('GET', $baseUrl, $options);

        return $response->toArray();
    }
}
