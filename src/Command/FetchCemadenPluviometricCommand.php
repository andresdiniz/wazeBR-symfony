<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CemadenPluviometricObservation;
use App\Entity\CemadenStationLink;
use App\Repository\CemadenStationLinkRepository;
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
 * Coleta observações PLUVIOMÉTRICAS do CEMADEN (somente mm de chuva).
 *
 * IMPORTANTE — separação de responsabilidades:
 *   - Este comando cuida APENAS de pluviômetros automáticos
 *     (endpoint: MapaInterativoWS/resources/horario/{id}/{hours}).
 *     → Grava em `cemaden_pluviometric_observation` (só chuva, sem nível de rio).
 *
 *   - O comando `app:fetch:cemaden:hidro` cuida de estações hidrológicas
 *     (endpoint: MedidaResource + AcumuladoResource, `est=`/`sen=`).
 *     → Grava em `cemaden_hidro_observation` (nível + chuva do rio).
 *
 * Os dois NÃO se cruzam: cada um lê tabelas diferentes e grava em tabelas diferentes.
 */
#[AsCommand(
    name: 'app:fetch:cemaden:pluviometric',
    description: 'Coleta observações pluviométricas CEMADEN (mm de chuva) para stations ativas.',
)]
class FetchCemadenPluviometricCommand extends Command
{
    private const DEFAULT_MIN_INTERVAL_MINUTES = 10;

    private const TZ_SP  = 'America/Sao_Paulo';
    private const TZ_UTC = 'UTC';

    public function __construct(
        private readonly CemadenStationLinkRepository $stationLinkRepository,
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
            ->addOption('min-interval', null, InputOption::VALUE_REQUIRED, sprintf('Intervalo mínimo entre coletas em minutos (padrão: %d)', self::DEFAULT_MIN_INTERVAL_MINUTES), self::DEFAULT_MIN_INTERVAL_MINUTES)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignora lastFetchedAt e força coleta de todas as stations ativas')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem persistir no banco');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->resetStaleConnection();
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $partnerId = $input->getOption('partner');
        $minIntervalMinutes = (int) $input->getOption('min-interval');

        $io->title('Coleta CEMADEN — Pluviométrica (mm de chuva)');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN — nenhum dado será persistido.');
        }

        // ── 1. Selecionar stations elegíveis ─────────────────────────────────
        if ($force) {
            $stations = $this->stationLinkRepository->findAllActive();
            $io->info('--force ativo: coletando todas as stations ativas (ignorando lastFetchedAt).');
        } else {
            $threshold = new \DateTimeImmutable(
                sprintf('-%d minutes', $minIntervalMinutes),
                new \DateTimeZone(self::TZ_UTC),
            );
            $stations = $this->stationLinkRepository->findDueForCollection($threshold);
            $io->info(sprintf(
                'Buscando stations com lastFetchedAt anterior a %s (intervalo: %d min).',
                $threshold->format('Y-m-d H:i:s'),
                $minIntervalMinutes,
            ));
        }

        if ($partnerId !== null) {
            $stations = array_values(array_filter(
                $stations,
                static fn ($s) => (string) $s->getPartner()->getId() === (string) $partnerId,
            ));
        }

        if (empty($stations)) {
            $io->info('Nenhuma station elegível para coleta no momento.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Processando %d station(s).', count($stations)));

        // ── 2. Iterar e coletar ──────────────────────────────────────────────
        $totalInserted = 0;
        $totalSkipped = 0;
        $errors = 0;

        foreach ($stations as $station) {
            $label = $this->stationLabel($station);
            $inserted = 0;
            $skipped = 0;

            try {
                $url = $station->getRequestUrl();
                $rawData = $this->fetchJson($url);

                if ($rawData === []) {
                    $io->writeln(sprintf('%s payload vazio — nada a persistir.', $label));
                    continue;
                }

                // Metadados da estação (só preenche campos vazios)
                if (!$dryRun) {
                    $this->syncStationMetadata($station, $rawData);
                }

                // Normaliza o payload em registros por hora
                $observations = $this->normalizePayload($rawData);

                if ($observations === []) {
                    $io->writeln(sprintf('%s sem observações válidas.', $label));
                    continue;
                }

                $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ_UTC));

                foreach ($observations as $obs) {
                    $observedAt = $obs['observedAt'];

                    // Idempotência: unique (station_link_id, observed_at)
                    $existing = $this->em
                        ->getRepository(CemadenPluviometricObservation::class)
                        ->findOneBy([
                            'cemadenStationLink' => $station,
                            'observedAt' => $observedAt,
                        ]);

                    if ($existing !== null) {
                        ++$skipped;
                        continue;
                    }

                    $entity = new CemadenPluviometricObservation();
                    $entity->setPartner($station->getPartner());
                    $entity->setCemadenStationLink($station);
                    $entity->setReferenceDate($obs['referenceDate']);
                    $entity->setHourSlot($obs['hourSlot']);
                    $entity->setAccumulatedRainfall(
                        $obs['accumulated'] !== null
                            ? number_format($obs['accumulated'], 3, '.', '')
                            : null,
                    );
                    $entity->setObservedAt($observedAt);
                    $entity->setSourcePayload($rawData);

                    if (!$dryRun) {
                        $this->em->persist($entity);
                    }

                    ++$inserted;
                }

                if (!$dryRun) {
                    $station->setLastFetchedAt($nowUtc);
                    $this->em->flush();
                }

                $totalInserted += $inserted;
                $totalSkipped += $skipped;

                $io->writeln(sprintf(
                    '%s ✓ inseridos: %d, ignorados: %d',
                    $label,
                    $inserted,
                    $skipped,
                ));
            } catch (\Throwable $e) {
                ++$errors;
                $this->logger->error('{loc}: erro ao coletar — {msg}', [
                    'loc' => $label,
                    'msg' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $io->error(sprintf('%s %s', $label, $e->getMessage()));
            }
        }

        $io->success(sprintf(
            'Concluído — Inseridos: %d | Ignorados: %d | Erros: %d',
            $totalInserted,
            $totalSkipped,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Sincroniza metadados da estação a partir do objeto "estacao" do payload.
     * Só preenche campos que estão vazios (não sobrescreve edições manuais).
     *
     * @param array<string,mixed> $rawData
     */
    private function syncStationMetadata(CemadenStationLink $station, array $rawData): void
    {
        $estacao = $rawData['estacao'] ?? null;
        if (!is_array($estacao)) {
            return;
        }

        $changed = false;

        if ($station->getStationName() === null && !empty($estacao['nome'])) {
            $station->setStationName((string) $estacao['nome']);
            $changed = true;
        }

        if ($station->getStationCode() === null && !empty($estacao['codEstacao'])) {
            $station->setStationCode((string) $estacao['codEstacao']);
            $changed = true;
        }

        if ($station->getLatitude() === null && isset($estacao['latitude'])) {
            $station->setLatitude((string) $estacao['latitude']);
            $changed = true;
        }

        if ($station->getLongitude() === null && isset($estacao['longitude'])) {
            $station->setLongitude((string) $estacao['longitude']);
            $changed = true;
        }

        if ($station->getStatus() === null && !empty($estacao['status'])) {
            $station->setStatus((string) $estacao['status']);
            $changed = true;
        }

        // Município
        $municipio = $estacao['idMunicipio'] ?? null;
        if (is_array($municipio)) {
            if ($station->getCity() === null && !empty($municipio['cidade'])) {
                $station->setCity((string) $municipio['cidade']);
                $changed = true;
            }
            if ($station->getState() === null && !empty($municipio['uf'])) {
                $station->setState((string) $municipio['uf']);
                $changed = true;
            }
            if ($station->getMunicipalityId() === null && isset($municipio['idMunicipio'])) {
                $station->setMunicipalityId((int) $municipio['idMunicipio']);
                $changed = true;
            }
            if ($station->getIbgeCode() === null && isset($municipio['codibge'])) {
                $station->setIbgeCode((string) $municipio['codibge']);
                $changed = true;
            }
        }

        // Rede
        $rede = $estacao['idRede'] ?? null;
        if (is_array($rede)) {
            if ($station->getNetworkId() === null && isset($rede['idRede'])) {
                $station->setNetworkId((int) $rede['idRede']);
                $changed = true;
            }
            if ($station->getNetworkName() === null && !empty($rede['nome'])) {
                $station->setNetworkName((string) $rede['nome']);
                $changed = true;
            }
            if ($station->getNetworkAcronym() === null && !empty($rede['sigla'])) {
                $station->setNetworkAcronym((string) $rede['sigla']);
                $changed = true;
            }
        }

        // Tipo (Pluviométrica)
        $tipo = $estacao['idTipoestacao'] ?? null;
        if (is_array($tipo) && $station->getStationType() === null && !empty($tipo['descricao'])) {
            $station->setStationType((string) $tipo['descricao']);
            $changed = true;
        }

        if ($changed) {
            $station->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone(self::TZ_UTC)));
        }
    }

    /**
     * Normaliza o payload real do CEMADEN:
     *
     * {
     *   "horarios": ["0h","1h",...,"23h"],
     *   "estacao":  {...},
     *   "datas":    ["13/09/2026", ...],
     *   "acumulados": [ [v0,v1,...,v23], ... ]
     * }
     *
     * `acumulados[i]` é um array de 24 valores (um por hora), correspondendo
     * ao dia `datas[i]`. O índice do array É a hora (0..23).
     *
     * CEMADEN envia datas em horário local de Brasília; convertemos para UTC
     * antes de persistir para manter o banco consistente com os outros dados.
     *
     * @param array<string,mixed> $rawData
     * @return list<array{observedAt: \DateTimeImmutable, referenceDate: \DateTimeImmutable, hourSlot: int, accumulated: ?float}>
     */
    private function normalizePayload(array $rawData): array
    {
        $datas      = $rawData['datas']      ?? [];
        $acumulados = $rawData['acumulados'] ?? [];

        if (!is_array($datas) || $datas === []) {
            return [];
        }
        if (!is_array($acumulados)) {
            return [];
        }

        $results = [];

        foreach ($datas as $dayIdx => $rawDate) {
            $refDateSp = $this->parseDateSp((string) $rawDate);
            if ($refDateSp === null) {
                continue;
            }

            // acumulados[$dayIdx] = [v0, v1, ..., v23]
            $dayValues = $acumulados[$dayIdx] ?? [];
            if (!is_array($dayValues) || $dayValues === []) {
                continue;
            }

            foreach ($dayValues as $hour => $rawValue) {
                $hour = (int) $hour;
                if ($hour < 0 || $hour > 23) {
                    continue;
                }

                // Hora local (SP) → instante absoluto → UTC
                $observedAtSp = $refDateSp->setTime($hour, 0);
                $observedAtUtc = $observedAtSp->setTimezone(
                    new \DateTimeZone(self::TZ_UTC),
                );

                // reference_date é DATE_IMMUTABLE — basta o dia.
                // Mantemos o dia ORIGINAL em SP (sem aplicar conversão) para
                // não "pular" para o dia seguinte quando a hora for tarde.
                $referenceDate = $refDateSp->setTime(0, 0);

                $accumulated = ($rawValue === null || $rawValue === '')
                    ? null
                    : (float) $rawValue;

                $results[] = [
                    'observedAt'    => $observedAtUtc,
                    'referenceDate' => $referenceDate,
                    'hourSlot'      => $hour,
                    'accumulated'   => $accumulated,
                ];
            }
        }

        return $results;
    }

    /**
     * Parse de data do CEMADEN ("13/09/2026", "2026-09-13", "13-09-2026").
     * Ancorado em America/Sao_Paulo. A conversão final para UTC é feita
     * em normalizePayload().
     */
    private function parseDateSp(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $tzSp = new \DateTimeZone(self::TZ_SP);

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw, $tzSp);
            if ($dt !== false) {
                return $dt->setTime(0, 0);
            }
        }

        try {
            return (new \DateTimeImmutable($raw, $tzSp))->setTime(0, 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Requisição HTTP + decodificação JSON.
     *
     * @return array<string,mixed>
     */
    private function fetchJson(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 15]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'CEMADEN respondeu HTTP %d para %s',
                $response->getStatusCode(),
                $url,
            ));
        }

        $data = $response->toArray(false);

        return is_array($data) ? $data : [];
    }

    private function stationLabel(CemadenStationLink $station): string
    {
        return sprintf(
            '[Partner %d | Station %d — %s]',
            $station->getPartner()->getId(),
            $station->getId(),
            $station->getStationName() ?? $station->getCemadenStationId(),
        );
    }

    private function resetStaleConnection(): void
    {
        $connection = $this->em->getConnection();

        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $connection->close();
        }
    }
}
