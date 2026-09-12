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
 * Coleta observações pluviométricas do CEMADEN para todas as CemadenStationLinks ativas.
 *
 * Fluxo:
 *   1. Seleciona stations ativas cujo lastFetchedAt < (agora − minFetchInterval).
 *   2. Para cada station, monta a URL via getRequestUrl() respeitando hoursToFetch.
 *   3. Normaliza os arrays datas/horarios/acumulados da resposta.
 *   4. Persiste apenas observações ainda não existentes (idempotência por unique constraint).
 *   5. Atualiza lastFetchedAt na station.
 *
 * Uso:
 *   php bin/console app:fetch:cemaden:pluviometric
 *   php bin/console app:fetch:cemaden:pluviometric --partner=42
 *   php bin/console app:fetch:cemaden:pluviometric --min-interval=60    # minutos
 *   php bin/console app:fetch:cemaden:pluviometric --force              # ignora lastFetchedAt
 *   php bin/console app:fetch:cemaden:pluviometric --dry-run
 *
 * Agendamento sugerido: a cada 60 minutos (dados CEMADEN são horários).
 */
#[AsCommand(
    name: 'app:fetch:cemaden:pluviometric',
    description: 'Coleta observações pluviométricas CEMADEN para stations ativas.',
)]
class FetchCemadenPluviometricCommand extends Command
{
    /**
     * Intervalo mínimo padrão entre coletas (em minutos).
     * Pode ser sobrescrito via --min-interval.
     */
    private const DEFAULT_MIN_INTERVAL_MINUTES = 55;

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
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $partnerId = $input->getOption('partner');
        $minIntervalMinutes = (int) $input->getOption('min-interval');

        $io->title('Coleta CEMADEN — Pluviométrica');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN — nenhum dado será persistido.');
        }

        // ── 1. Selecionar stations elegíveis ─────────────────────────────────
        if ($force) {
            $stations = $this->stationLinkRepository->findAllActive();
            $io->info('--force ativo: coletando todas as stations ativas (ignorando lastFetchedAt).');
        } else {
            $threshold = new \DateTimeImmutable(sprintf('-%d minutes', $minIntervalMinutes));
            $stations = $this->stationLinkRepository->findDueForCollection($threshold);
            $io->info(sprintf(
                'Buscando stations com lastFetchedAt anterior a %s (intervalo: %d min).',
                $threshold->format('Y-m-d H:i:s'),
                $minIntervalMinutes,
            ));
        }

        if ($partnerId !== null) {
            $stations = array_filter(
                $stations,
                static fn ($s) => (string) $s->getPartner()->getId() === (string) $partnerId,
            );
            $stations = array_values($stations);
        }

        if (empty($stations)) {
            $io->info('Nenhuma station elegível para coleta no momento.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Processando %d station(s).', count($stations)));

        // ── 2. Iterar e coletar ──────────────────────────────────────────────
        $inserted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($stations as $station) {
            $label = $this->stationLabel($station);

            try {
                $url = $station->getRequestUrl();
                $rawData = $this->fetchJson($url);

                // ── 2a. Normalizar estrutura CEMADEN ─────────────────────────
                // A resposta pode ser um array de itens com campos:
                // "referencia" (data), "horario" e "acumulado".
                $observations = $this->normalizePayload($rawData, $station);

                $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                foreach ($observations as $obs) {
                    /** @var \DateTimeImmutable $observedAt */
                    $observedAt = $obs['observedAt'];
                    $accumulated = $obs['accumulated'];
                    $hourSlot = $obs['hourSlot'];
                    $refDate = $obs['referenceDate'];

                    // Idempotência: unique(station_link_id, observed_at)
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
                    $entity->setReferenceDate($refDate);
                    $entity->setHourSlot($hourSlot);
                    $entity->setAccumulatedRainfall($accumulated);
                    $entity->setObservedAt($observedAt);
                    $entity->setSourcePayload($rawData);

                    if (!$dryRun) {
                        $this->em->persist($entity);
                    }

                    ++$inserted;
                }

                // ── 2b. Atualizar lastFetchedAt da station ───────────────────
                if (!$dryRun) {
                    $station->setLastFetchedAt($nowUtc);
                    $this->em->flush();
                }

                $io->writeln(sprintf(
                    '%s ✓ inseridos: %d, ignorados (já existentes): %d',
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
            $inserted,
            $skipped,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Normaliza o payload bruto do CEMADEN em uma lista de registros estruturados.
     *
     * A API retorna arrays paralelos de datas, horários e acumulados, ou uma lista
     * de objetos com esses campos. Este método suporta ambos os formatos.
     *
     * @param array<string, mixed>  $rawData
     * @return list<array{observedAt: \DateTimeImmutable, referenceDate: \DateTimeImmutable, hourSlot: int, accumulated: ?float}>
     */
    private function normalizePayload(array $rawData, CemadenStationLink $station): array
    {
        $results = [];

        // Formato 1 — lista de objetos: [{referencia, horario, acumulado}, ...]
        if (isset($rawData[0]) && is_array($rawData[0])) {
            foreach ($rawData as $item) {
                $results[] = $this->buildRecord($item);
            }
            return $results;
        }

        // Formato 2 — arrays paralelos: {datas: [...], horarios: [...], acumulados: [...]}
        $datas = $rawData['datas'] ?? [];
        $horarios = $rawData['horarios'] ?? [];
        $acumulados = $rawData['acumulados'] ?? [];

        foreach ($datas as $idx => $data) {
            $results[] = $this->buildRecord([
                'referencia' => $data,
                'horario' => $horarios[$idx] ?? null,
                'acumulado' => $acumulados[$idx] ?? null,
            ]);
        }

        return $results;
    }

    /**
     * Monta um registro normalizado a partir de um item bruto.
     *
     * @param array<string, mixed> $item
     * @return array{observedAt: \DateTimeImmutable, referenceDate: \DateTimeImmutable, hourSlot: int, accumulated: ?float}
     */
    private function buildRecord(array $item): array
    {
        // "referencia" pode ser "2025-01-15" ou "15/01/2025"
        $rawDate = (string) ($item['referencia'] ?? $item['data'] ?? '');
        $refDate = \DateTimeImmutable::createFromFormat('Y-m-d', $rawDate)
            ?: \DateTimeImmutable::createFromFormat('d/m/Y', $rawDate)
            ?: new \DateTimeImmutable($rawDate);
        $refDate = \DateTimeImmutable::createFromInterface($refDate)->setTime(0, 0);

        // "horario" pode ser "16h" ou "16" ou "16:00"
        $rawHour = (string) ($item['horario'] ?? '0');
        $hourSlot = (int) preg_replace('/\D/', '', $rawHour);

        $observedAt = $refDate->setTime($hourSlot, 0);

        $rawAccumulated = $item['acumulado'] ?? null;
        $accumulated = ($rawAccumulated !== null && $rawAccumulated !== '') ? (float) $rawAccumulated : null;

        return [
            'observedAt' => $observedAt,
            'referenceDate' => $refDate,
            'hourSlot' => $hourSlot,
            'accumulated' => $accumulated,
        ];
    }

    /**
     * Realiza a requisição HTTP e retorna o JSON decodificado.
     *
     * @return array<string, mixed>
     */
    private function fetchJson(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 15]);
        return $response->toArray();
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
}
