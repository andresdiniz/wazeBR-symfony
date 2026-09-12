<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Entity\PartnerApiLink;
use App\Entity\WazeAlert;
use App\Entity\WazeJam;
use App\Repository\PartnerApiLinkRepository;
use App\Repository\WazeAlertRepository;
use App\Repository\WazeJamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:fetch-waze-feed',
    description: 'Busca e sincroniza alerts e jams dos links Waze ativos.',
)]
final class FetchWazeFeedCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly WazeAlertRepository $alertRepository,
        private readonly WazeJamRepository $jamRepository,
        private readonly PartnerApiLinkRepository $apiLinkRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'partner',
                'p',
                InputOption::VALUE_OPTIONAL,
                'ID do partner. Sem este parâmetro, processa todos os partners.',
            )
            ->addOption(
                'type',
                't',
                InputOption::VALUE_OPTIONAL,
                'Tipo do link: Alerts ou Jams. O padrão é Alerts.',
                'Alerts',
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Executa sem gravar, reativar ou desativar registros.',
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Limite de alerts e jams por resposta.',
                '1000',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Ignora temporariamente a frequência configurada no partner.',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $partnerOption = $input->getOption('partner');
        $type = trim((string) $input->getOption('type'));
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $limit = max(1, (int) $input->getOption('limit'));

        $partnerId = $partnerOption !== null
            ? (int) $partnerOption
            : null;

        $io->title('Sincronização do Feed Waze');

        if ($dryRun) {
            $io->warning(
                'DRY-RUN: nenhum registro será criado, atualizado, reativado ou desativado.',
            );
        }

        $apiLinks = $this->getApiLinks($partnerId, $type);

        if ($apiLinks === []) {
            $io->error('Nenhum link Waze ativo foi encontrado.');

            $io->writeln(
                'Confira a tabela partner_api_link: active = 1, partner_id válido e type correto.',
            );

            return Command::FAILURE;
        }

        $io->table(
            ['Partner', 'Link ID', 'Tipo', 'Nome', 'URL', 'Frequência'],
            array_map(
                static function (PartnerApiLink $link): array {
                    $partner = $link->getPartner();

                    return [
                        $partner?->getName() ?? 'Não informado',
                        $link->getId(),
                        $link->getType(),
                        $link->getName(),
                        $link->getUrl(),
                        $partner?->getFetchFrequency() . ' '
                            . ($partner?->getFetchFrequencyUnit() ?? ''),
                    ];
                },
                $apiLinks,
            ),
        );

        $linksByPartner = $this->groupLinksByPartner($apiLinks);

        $totals = [
            'partnersProcessed' => 0,
            'partnersSkipped' => 0,
            'partnersFailed' => 0,
            'alertsCreated' => 0,
            'alertsUpdated' => 0,
            'alertsReactivated' => 0,
            'alertsDeactivated' => 0,
            'jamsCreated' => 0,
            'jamsUpdated' => 0,
            'jamsReactivated' => 0,
            'jamsDeactivated' => 0,
            'recordsSkipped' => 0,
        ];

        foreach ($linksByPartner as $links) {
            $partner = $links[0]->getPartner();

            if ($partner === null) {
                continue;
            }

            if (!$force && !$this->shouldFetchPartner($partner)) {
                $io->note(sprintf(
                    'Partner "%s" ignorado: frequência ainda não vencida (%s).',
                    $partner->getName() ?? ('ID ' . $partner->getId()),
                    $this->getFrequencyLabel($partner),
                ));

                $totals['partnersSkipped']++;

                continue;
            }

            $io->section(sprintf(
                'Partner: %s',
                $partner->getName() ?? ('ID ' . $partner->getId()),
            ));

            $partnerSuccess = true;

            foreach ($links as $apiLink) {
                try {
                    $result = $this->fetchAndSyncApiLink(
                        $io,
                        $partner,
                        $apiLink,
                        $dryRun,
                        $limit,
                    );

                    foreach ($result as $key => $value) {
                        $totals[$key] += $value;
                    }
                } catch (\Throwable $exception) {
                    $partnerSuccess = false;

                    $io->error(sprintf(
                        'Erro no link "%s": %s',
                        $apiLink->getName(),
                        $exception->getMessage(),
                    ));

                    if ($io->isVerbose()) {
                        $io->writeln($exception->getTraceAsString());
                    }
                }
            }

            /*
             * Só registra a última execução quando todos os links
             * do partner terminaram com sucesso.
             */
            if ($partnerSuccess) {
                if (!$dryRun) {
                    $partner->setLastFetchAt(new \DateTimeImmutable());

                    $this->entityManager->persist($partner);
                    $this->entityManager->flush();
                }

                $totals['partnersProcessed']++;
            } else {
                $totals['partnersFailed']++;
            }
        }

        $io->title('Resumo da sincronização');

        $io->table(
            ['Métrica', 'Quantidade'],
            [
                ['Partners processados', $totals['partnersProcessed']],
                ['Partners ignorados', $totals['partnersSkipped']],
                ['Partners com erro', $totals['partnersFailed']],
                ['Alerts criados', $totals['alertsCreated']],
                ['Alerts atualizados', $totals['alertsUpdated']],
                ['Alerts reativados', $totals['alertsReactivated']],
                ['Alerts desativados', $totals['alertsDeactivated']],
                ['Jams criados', $totals['jamsCreated']],
                ['Jams atualizados', $totals['jamsUpdated']],
                ['Jams reativados', $totals['jamsReactivated']],
                ['Jams desativados', $totals['jamsDeactivated']],
                ['Registros ignorados', $totals['recordsSkipped']],
            ],
        );

        return $totals['partnersFailed'] > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    /**
     * @return PartnerApiLink[]
     */
    private function getApiLinks(
        ?int $partnerId,
        string $type,
    ): array {
        $queryBuilder = $this->apiLinkRepository
            ->createQueryBuilder('link')
            ->innerJoin('link.partner', 'partner')
            ->addSelect('partner')
            ->andWhere('link.active = :active')
            ->setParameter('active', true);

        if ($partnerId !== null) {
            $queryBuilder
                ->andWhere('partner.id = :partnerId')
                ->setParameter('partnerId', $partnerId);
        }

        if ($type !== '') {
            $queryBuilder
                ->andWhere('UPPER(link.type) = :type')
                ->setParameter('type', mb_strtoupper($type));
        }

        return $queryBuilder
            ->orderBy('partner.id', 'ASC')
            ->addOrderBy('link.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param PartnerApiLink[] $apiLinks
     *
     * @return array<int, PartnerApiLink[]>
     */
    private function groupLinksByPartner(array $apiLinks): array
    {
        $grouped = [];

        foreach ($apiLinks as $apiLink) {
            $partner = $apiLink->getPartner();

            if ($partner === null || $partner->getId() === null) {
                continue;
            }

            $grouped[$partner->getId()][] = $apiLink;
        }

        return $grouped;
    }

    /**
     * @return array{
     *     alertsCreated: int,
     *     alertsUpdated: int,
     *     alertsReactivated: int,
     *     alertsDeactivated: int,
     *     jamsCreated: int,
     *     jamsUpdated: int,
     *     jamsReactivated: int,
     *     jamsDeactivated: int,
     *     recordsSkipped: int
     * }
     */
    private function fetchAndSyncApiLink(
        SymfonyStyle $io,
        Partner $partner,
        PartnerApiLink $apiLink,
        bool $dryRun,
        int $limit,
    ): array {
        $url = trim((string) $apiLink->getUrl());

        if ($url === '') {
            throw new \RuntimeException(
                sprintf(
                    'O link ID %s não possui URL.',
                    (string) $apiLink->getId(),
                ),
            );
        }

        $io->writeln(sprintf(
            '<info>Processando:</info> %s (%s)',
            $apiLink->getName(),
            $apiLink->getType(),
        ));

        $io->writeln(sprintf(
            'URL: <comment>%s</comment>',
            $url,
        ));

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 60,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'WazeBR-Symfony/1.0',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        $io->writeln(sprintf(
            'HTTP <info>%d</info> | %d bytes',
            $statusCode,
            strlen($content),
        ));

        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf(
                'O Waze retornou HTTP %d.',
                $statusCode,
            ));
        }

        try {
            $data = json_decode(
                $content,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'A resposta HTTP 200 não contém JSON válido.',
                previous: $exception,
            );
        }

        if (!is_array($data)) {
            throw new \RuntimeException(
                'A resposta JSON não possui estrutura de objeto.',
            );
        }

        $linkType = mb_strtoupper((string) $apiLink->getType());

        /*
         * O feed Alerts retorna:
         * {
         *     "alerts": [],
         *     "jams": []
         * }
         */
        if ($linkType === 'ALERTS') {
            if (!array_key_exists('alerts', $data)) {
                throw new \RuntimeException(
                    'A resposta não possui a chave "alerts".',
                );
            }

            if (!is_array($data['alerts'])) {
                throw new \RuntimeException(
                    'A chave "alerts" não é uma lista.',
                );
            }

            $alerts = $data['alerts'];
            $jams = is_array($data['jams'] ?? null)
                ? $data['jams']
                : [];
        } elseif ($linkType === 'JAMS') {
            if (!array_key_exists('jams', $data)) {
                throw new \RuntimeException(
                    'A resposta não possui a chave "jams".',
                );
            }

            if (!is_array($data['jams'])) {
                throw new \RuntimeException(
                    'A chave "jams" não é uma lista.',
                );
            }

            $alerts = [];
            $jams = $data['jams'];
        } elseif ($linkType === 'TVT') {
            throw new \RuntimeException(
                'O tipo TVT deve ser processado pelo FetchWazeTvtCommand.',
            );
        } else {
            throw new \RuntimeException(sprintf(
                'Tipo de link não suportado: %s.',
                $apiLink->getType(),
            ));
        }

        $io->writeln(sprintf(
            'Encontrados: <info>%d alerts</info> e <info>%d jams</info>.',
            count($alerts),
            count($jams),
        ));

        $alertResult = $this->syncAlerts(
            $io,
            $partner,
            $alerts,
            $dryRun,
            $limit,
        );

        $jamResult = $this->syncJams(
            $io,
            $partner,
            $jams,
            $dryRun,
            $limit,
        );

        return [
            'alertsCreated' => $alertResult['created'],
            'alertsUpdated' => $alertResult['updated'],
            'alertsReactivated' => $alertResult['reactivated'],
            'alertsDeactivated' => $alertResult['deactivated'],
            'jamsCreated' => $jamResult['created'],
            'jamsUpdated' => $jamResult['updated'],
            'jamsReactivated' => $jamResult['reactivated'],
            'jamsDeactivated' => $jamResult['deactivated'],
            'recordsSkipped' => $alertResult['skipped']
                + $jamResult['skipped'],
        ];
    }

    /**
     * @param array<int, mixed> $alerts
     *
     * @return array{
     *     created: int,
     *     updated: int,
     *     reactivated: int,
     *     deactivated: int,
     *     skipped: int
     * }
     */
    private function syncAlerts(
        SymfonyStyle $io,
        Partner $partner,
        array $alerts,
        bool $dryRun,
        int $limit,
    ): array {
        $created = 0;
        $updated = 0;
        $reactivated = 0;
        $deactivated = 0;
        $skipped = 0;
        $currentUuids = [];
        $now = new \DateTimeImmutable();

        $items = array_slice($alerts, 0, $limit);

        if ($items === []) {
            $io->note(
                'Nenhum alert foi retornado. Nenhum alert existente foi desativado.',
            );

            return [
                'created' => 0,
                'updated' => 0,
                'reactivated' => 0,
                'deactivated' => 0,
                'skipped' => 0,
            ];
        }

        $io->section('Sincronizando alerts');

        $progressBar = $io->createProgressBar(count($items));
        $progressBar->start();

        foreach ($items as $item) {
            if (!is_array($item)) {
                $skipped++;
                $progressBar->advance();
                continue;
            }

            $data = $this->normalizeAlertData($item);
            $uuid = trim((string) ($data['uuid'] ?? ''));

            if ($uuid === '') {
                $skipped++;
                $progressBar->advance();
                continue;
            }

            $currentUuids[] = $uuid;

            if (!$dryRun) {
                $alert = $this->alertRepository
                    ->findOneByPartnerAndUuid($partner, $uuid);

                if ($alert === null) {
                    $alert = new WazeAlert();

                    $alert
                        ->setPartner($partner)
                        ->setUuid($uuid);

                    $this->entityManager->persist($alert);
                    $created++;
                } else {
                    $updated++;

                    if (!$alert->isActive()) {
                        $reactivated++;
                    }
                }

                $this->hydrateAlert($alert, $data, $now);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine();

        $currentUuids = array_values(array_unique($currentUuids));

        if (!$dryRun && $currentUuids !== []) {
            $this->entityManager->flush();

            $deactivated = $this->alertRepository
                ->deactivateMissingForPartner(
                    $partner,
                    $currentUuids,
                    $now,
                );
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'reactivated' => $reactivated,
            'deactivated' => $deactivated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<int, mixed> $jams
     *
     * @return array{
     *     created: int,
     *     updated: int,
     *     reactivated: int,
     *     deactivated: int,
     *     skipped: int
     * }
     */
    private function syncJams(
        SymfonyStyle $io,
        Partner $partner,
        array $jams,
        bool $dryRun,
        int $limit,
    ): array {
        $created = 0;
        $updated = 0;
        $reactivated = 0;
        $deactivated = 0;
        $skipped = 0;
        $currentUuids = [];
        $now = new \DateTimeImmutable();

        $items = array_slice($jams, 0, $limit);

        if ($items === []) {
            $io->note(
                'Nenhum jam foi retornado. Nenhum jam existente foi desativado.',
            );

            return [
                'created' => 0,
                'updated' => 0,
                'reactivated' => 0,
                'deactivated' => 0,
                'skipped' => 0,
            ];
        }

        $io->section('Sincronizando jams');

        $progressBar = $io->createProgressBar(count($items));
        $progressBar->start();

        foreach ($items as $item) {
            if (!is_array($item)) {
                $skipped++;
                $progressBar->advance();
                continue;
            }

            $data = $this->normalizeJamData($item);
            $uuid = trim((string) ($data['uuid'] ?? ''));

            if ($uuid === '') {
                $skipped++;
                $progressBar->advance();
                continue;
            }

            $currentUuids[] = $uuid;

            if (!$dryRun) {
                $jam = $this->jamRepository
                    ->findOneByPartnerAndUuid($partner, $uuid);

                if ($jam === null) {
                    $jam = new WazeJam();

                    $jam
                        ->setPartner($partner)
                        ->setUuid($uuid);

                    $this->entityManager->persist($jam);
                    $created++;
                } else {
                    $updated++;

                    if (!$jam->isActive()) {
                        $reactivated++;
                    }
                }

                $this->hydrateJam($jam, $data, $now);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine();

        $currentUuids = array_values(array_unique($currentUuids));

        if (!$dryRun && $currentUuids !== []) {
            $this->entityManager->flush();

            $deactivated = $this->jamRepository
                ->deactivateMissingForPartner(
                    $partner,
                    $currentUuids,
                    $now,
                );
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'reactivated' => $reactivated,
            'deactivated' => $deactivated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateAlert(
        WazeAlert $alert,
        array $data,
        \DateTimeImmutable $now,
    ): void {
        $location = is_array($data['location'] ?? null)
            ? $data['location']
            : [];

        $subtype = trim((string) ($data['subtype'] ?? ''));
        $street = trim((string) ($data['street'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $description = trim(
            (string) ($data['reportDescription'] ?? ''),
        );

        $alert
            ->setType((string) ($data['type'] ?? 'HAZARD'))
            ->setSubtype($subtype !== '' ? $subtype : null)
            ->setPubMillis((int) ($data['pubMillis'] ?? 0))
            ->setReportByMunicipalityUser(
                filter_var(
                    $data['reportByMunicipalityUser'] ?? false,
                    FILTER_VALIDATE_BOOLEAN,
                ),
            )
            ->setReportRating((int) ($data['reportRating'] ?? 0))
            ->setConfidence((int) ($data['confidence'] ?? 0))
            ->setReliability((int) ($data['reliability'] ?? 0))
            ->setLongitude((float) ($location['x'] ?? 0))
            ->setLatitude((float) ($location['y'] ?? 0))
            ->setStreet($street !== '' ? $street : null)
            ->setCity($city !== '' ? $city : null)
            ->setCountry((string) ($data['country'] ?? 'BR'))
            ->setRoadType((int) ($data['roadType'] ?? 0))
            ->setReportDescription(
                $description !== '' ? $description : null,
            )
            ->setNThumbsUp((int) ($data['nThumbsUp'] ?? 0))
            ->setMagvar((int) ($data['magvar'] ?? 0))
            ->setIsActive(true)
            ->setLastSeenAt($now);

        if ($alert->getCollectedAt() === null) {
            $alert->setCollectedAt($now);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateJam(
        WazeJam $jam,
        array $data,
        \DateTimeImmutable $now,
    ): void {
        $street = trim((string) ($data['street'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $endNode = trim((string) ($data['endNode'] ?? ''));

        $jam
            ->setJamId((int) ($data['id'] ?? 0))
            ->setLine(
                is_array($data['line'] ?? null)
                    ? $data['line']
                    : [],
            )
            ->setSpeed((float) ($data['speed'] ?? 0))
            ->setSpeedKmh((float) ($data['speedKMH'] ?? 0))
            ->setLength((int) ($data['length'] ?? 0))
            ->setDelay((int) ($data['delay'] ?? -1))
            ->setLevel((int) ($data['level'] ?? 0))
            ->setPubMillis((int) ($data['pubMillis'] ?? 0))
            ->setTurnType((string) ($data['turnType'] ?? 'NONE'))
            ->setBlockingAlertUuid(
                ($data['blockingAlertUuid'] ?? '') !== ''
                    ? (string) $data['blockingAlertUuid']
                    : null,
            )
            ->setSegments(
                is_array($data['segments'] ?? null)
                    ? $data['segments']
                    : [],
            )
            ->setStreet($street !== '' ? $street : null)
            ->setCity($city !== '' ? $city : null)
            ->setCountry((string) ($data['country'] ?? 'BR'))
            ->setRoadType((int) ($data['roadType'] ?? 0))
            ->setEndNode($endNode !== '' ? $endNode : null)
            ->setIsActive(true)
            ->setLastSeenAt($now);

        if ($jam->getCollectedAt() === null) {
            $jam->setCollectedAt($now);
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeAlertData(array $data): array
    {
        $normalized = $data;

        $normalized['uuid'] = $data['uuid']
            ?? $data['id']
            ?? $data['alert_id']
            ?? '';

        $normalized['type'] = $data['type']
            ?? $data['alert_type']
            ?? 'HAZARD';

        $normalized['subtype'] = $data['subtype']
            ?? $data['sub_type']
            ?? '';

        $normalized['pubMillis'] = $data['pubMillis']
            ?? $data['pub_millis']
            ?? $data['timestamp']
            ?? 0;

        $normalized['reportByMunicipalityUser'] =
            $data['reportByMunicipalityUser']
            ?? $data['report_by_municipality_user']
            ?? false;

        $normalized['reportRating'] = $data['reportRating']
            ?? $data['report_rating']
            ?? 0;

        $normalized['confidence'] = $data['confidence'] ?? 0;
        $normalized['reliability'] = $data['reliability'] ?? 0;

        $normalized['location'] = $this->normalizeLocation($data);

        $normalized['street'] = $data['street']
            ?? $data['street_name']
            ?? '';

        $normalized['city'] = $data['city'] ?? '';
        $normalized['country'] = $data['country'] ?? 'BR';

        $normalized['roadType'] = $data['roadType']
            ?? $data['road_type']
            ?? 0;

        $normalized['reportDescription'] =
            $data['reportDescription']
            ?? $data['description']
            ?? '';

        $normalized['nThumbsUp'] = $data['nThumbsUp']
            ?? $data['thumbs_up']
            ?? 0;

        $normalized['magvar'] = $data['magvar'] ?? 0;

        return $normalized;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{x: float, y: float}
     */
    private function normalizeLocation(array $data): array
    {
        $location = $data['location'] ?? null;

        if (is_array($location)) {
            if (isset($location['x'], $location['y'])) {
                return [
                    'x' => (float) $location['x'],
                    'y' => (float) $location['y'],
                ];
            }

            if (isset($location['lon'], $location['lat'])) {
                return [
                    'x' => (float) $location['lon'],
                    'y' => (float) $location['lat'],
                ];
            }

            if (isset(
                $location['longitude'],
                $location['latitude'],
            )) {
                return [
                    'x' => (float) $location['longitude'],
                    'y' => (float) $location['latitude'],
                ];
            }
        }

        if (isset($data['longitude'], $data['latitude'])) {
            return [
                'x' => (float) $data['longitude'],
                'y' => (float) $data['latitude'],
            ];
        }

        if (isset($data['lon'], $data['lat'])) {
            return [
                'x' => (float) $data['lon'],
                'y' => (float) $data['lat'],
            ];
        }

        return [
            'x' => 0.0,
            'y' => 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeJamData(array $data): array
    {
        $normalized = $data;

        $normalized['uuid'] = $data['uuid']
            ?? $data['id']
            ?? $data['jam_id']
            ?? '';

        $normalized['id'] = $data['id']
            ?? $data['jam_id']
            ?? 0;

        $line = $data['line']
            ?? $data['polyline']
            ?? $data['path']
            ?? [];

        $normalized['line'] = $this->normalizeLine($line);

        $normalized['speed'] = $data['speed'] ?? 0;

        $normalized['speedKMH'] = $data['speedKMH']
            ?? $data['speed_kmh']
            ?? 0;

        $normalized['length'] = $data['length'] ?? 0;
        $normalized['delay'] = $data['delay'] ?? -1;
        $normalized['level'] = $data['level']
            ?? $data['severity']
            ?? 0;

        $normalized['segments'] = is_array(
            $data['segments'] ?? null,
        )
            ? $data['segments']
            : [];

        $normalized['pubMillis'] = $data['pubMillis']
            ?? $data['pub_millis']
            ?? $data['timestamp']
            ?? 0;

        $normalized['turnType'] = $data['turnType']
            ?? $data['turn_type']
            ?? 'NONE';

        $normalized['blockingAlertUuid'] =
            $data['blockingAlertUuid']
            ?? $data['blocking_alert_uuid']
            ?? '';

        $normalized['street'] = $data['street']
            ?? $data['street_name']
            ?? '';

        $normalized['city'] = $data['city'] ?? '';
        $normalized['country'] = $data['country'] ?? 'BR';

        $normalized['roadType'] = $data['roadType']
            ?? $data['road_type']
            ?? 0;

        $normalized['endNode'] = $data['endNode']
            ?? $data['end_node']
            ?? '';

        return $normalized;
    }

    private function normalizeLine(mixed $line): array
    {
        if (is_string($line)) {
            $coordinates = preg_split(
                '/\s+/',
                trim($line),
            ) ?: [];

            $coordinates = array_map(
                'floatval',
                $coordinates,
            );

            $result = [];

            for (
                $index = 0;
                $index < count($coordinates);
                $index += 2
            ) {
                if (!isset($coordinates[$index + 1])) {
                    continue;
                }

                $result[] = [
                    'x' => $coordinates[$index + 1],
                    'y' => $coordinates[$index],
                ];
            }

            return $result;
        }

        if (!is_array($line)) {
            return [];
        }

        $result = [];

        foreach ($line as $point) {
            if (!is_array($point)) {
                continue;
            }

            if (isset($point[0], $point[1])) {
                $result[] = [
                    'x' => (float) $point[1],
                    'y' => (float) $point[0],
                ];

                continue;
            }

            if (isset($point['x'], $point['y'])) {
                $result[] = [
                    'x' => (float) $point['x'],
                    'y' => (float) $point['y'],
                ];

                continue;
            }

            if (isset($point['lon'], $point['lat'])) {
                $result[] = [
                    'x' => (float) $point['lon'],
                    'y' => (float) $point['lat'],
                ];

                continue;
            }

            if (isset(
                $point['longitude'],
                $point['latitude'],
            )) {
                $result[] = [
                    'x' => (float) $point['longitude'],
                    'y' => (float) $point['latitude'],
                ];
            }
        }

        return $result;
    }

    private function shouldFetchPartner(Partner $partner): bool
    {
        $lastFetchAt = $partner->getLastFetchAt();

        if ($lastFetchAt === null) {
            return true;
        }

        $frequency = max(
            1,
            $partner->getFetchFrequency() ?? 5,
        );

        $unit = mb_strtolower(
            (string) (
                $partner->getFetchFrequencyUnit()
                ?? 'minutes'
            ),
        );

        $interval = match ($unit) {
            'second',
            'seconds',
            'segundo',
            'segundos' => $frequency,

            'hour',
            'hours',
            'hora',
            'horas' => $frequency * 3600,

            default => $frequency * 60,
        };

        return (
            time() - $lastFetchAt->getTimestamp()
        ) >= $interval;
    }

    private function getFrequencyLabel(?Partner $partner): string
    {
        if ($partner === null) {
            return 'Não definida';
        }

        $frequency = $partner->getFetchFrequency() ?? 5;

        $unit = mb_strtolower(
            (string) (
                $partner->getFetchFrequencyUnit()
                ?? 'minutes'
            ),
        );

        return match ($unit) {
            'second',
            'seconds',
            'segundo',
            'segundos' => sprintf(
                '%d segundo(s)',
                $frequency,
            ),

            'hour',
            'hours',
            'hora',
            'horas' => sprintf(
                '%d hora(s)',
                $frequency,
            ),

            default => sprintf(
                '%d minuto(s)',
                $frequency,
            ),
        };
    }
}
