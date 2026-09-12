<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CemadenHidroObservation;
use App\Entity\CemadenHidroStationLink;
use App\Repository\CemadenHidroStationLinkRepository;
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
 * Coleta observações hidrológicas (nível de rios) do CEMADEN para todas as
 * CemadenHidroStationLinks ativas.
 *
 * Fluxo:
 *   1. Seleciona stations hidro ativas cujo lastFetchedAt < (agora − minFetchInterval).
 *   2. Para cada station, monta a URL via getRequestUrl() respeitando recordsToFetch.
 *   3. Para cada item retornado, persiste uma CemadenHidroObservation (append-only).
 *   4. Unique constraint (station_link_id, observed_at) garante idempotência.
 *   5. Atualiza lastFetchedAt da station após coleta bem-sucedida.
 *   6. Sincroniza metadados opcionais (stationCode, stationName, city, state)
 *      a partir do primeiro item da resposta se ainda não preenchidos.
 *
 * Uso:
 *   php bin/console app:fetch:cemaden:hidro
 *   php bin/console app:fetch:cemaden:hidro --partner=42
 *   php bin/console app:fetch:cemaden:hidro --min-interval=60    # minutos
 *   php bin/console app:fetch:cemaden:hidro --force
 *   php bin/console app:fetch:cemaden:hidro --dry-run
 *
 * Agendamento sugerido: a cada 60 minutos.
 */
#[AsCommand(
    name: 'app:fetch:cemaden:hidro',
    description: 'Coleta observações hidrológicas CEMADEN (nível de rios) para stations ativas.',
)]
class FetchCemadenHidroCommand extends Command
{
    /**
     * Intervalo mínimo padrão entre coletas (em minutos).
     */
    private const DEFAULT_MIN_INTERVAL_MINUTES = 55;

    public function __construct(
        private readonly CemadenHidroStationLinkRepository $stationLinkRepository,
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

        $io->title('Coleta CEMADEN — Hidrológica (Nível de Rios)');

        if ($dryRun) {
            $io->warning('Modo DRY-RUN — nenhum dado será persistido.');
        }

        // ── 1. Selecionar stations elegíveis ─────────────────────────────────
        if ($force) {
            $stations = $this->stationLinkRepository->findAllActive();
            $io->info('--force ativo: coletando todas as stations hidro ativas.');
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
            $io->info('Nenhuma station hidro elegível para coleta no momento.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Processando %d station(s) hidrológica(s).', count($stations)));

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
                $rawItems = $this->fetchJson($url);

                if (empty($rawItems)) {
                    $io->writeln(sprintf('%s resposta vazia — nenhuma observação gerada.', $label));
                    continue;
                }

                // ── 2a. Sincronizar metadados da station a partir da resposta ─
                $this->syncStationMetadata($station, $rawItems[0], $dryRun);

                $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                // ── 2b. Persistir cada item ──────────────────────────────────
                foreach ($rawItems as $item) {
                    // Mapeamento CEMADEN: codigo, estacao, cidade, uf, valor, offset, datahora
                    $observedAt = $this->parseDateTime($item['datahora'] ?? null);

                    if ($observedAt === null) {
                        $this->logger->warning('{loc}: item sem datahora válida — ignorado.', [
                            'loc' => $label,
                            'item' => $item,
                        ]);
                        ++$skipped;
                        continue;
                    }

                    // Idempotência: unique(station_link_id, observed_at)
                    $existing = $this->em
                        ->getRepository(CemadenHidroObservation::class)
                        ->findOneBy([
                            'cemadenHidroStationLink' => $station,
                            'observedAt' => $observedAt,
                        ]);

                    if ($existing !== null) {
                        ++$skipped;
                        continue;
                    }

                    $entity = new CemadenHidroObservation();
                    $entity->setPartner($station->getPartner());
                    $entity->setCemadenHidroStationLink($station);
                    $entity->setStationCode($item['codigo'] ?? null);
                    $entity->setStationName($item['estacao'] ?? null);
                    $entity->setCity($item['cidade'] ?? null);
                    $entity->setState($item['uf'] ?? null);
                    $entity->setRiverLevel(isset($item['valor']) && $item['valor'] !== '' ? (float) $item['valor'] : null);
                    $entity->setOffset(isset($item['offset']) && $item['offset'] !== '' ? (float) $item['offset'] : null);
                    $entity->setObservedAt($observedAt);
                    $entity->setSourcePayload($item);

                    if (!$dryRun) {
                        $this->em->persist($entity);
                    }

                    ++$inserted;
                }

                // ── 2c. Flush + atualizar lastFetchedAt ──────────────────────
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
     * Sincroniza metadados opcionais da station (stationCode, stationName, city, state)
     * a partir do primeiro item da resposta, caso ainda não estejam preenchidos.
     *
     * @param array<string, mixed> $firstItem
     */
    private function syncStationMetadata(
        CemadenHidroStationLink $station,
        array $firstItem,
        bool $dryRun,
    ): void {
        $changed = false;

        if ($station->getStationCode() === null && isset($firstItem['codigo'])) {
            $station->setStationCode((string) $firstItem['codigo']);
            $changed = true;
        }

        if ($station->getStationName() === null && isset($firstItem['estacao'])) {
            $station->setStationName((string) $firstItem['estacao']);
            $changed = true;
        }

        if ($station->getCity() === null && isset($firstItem['cidade'])) {
            $station->setCity((string) $firstItem['cidade']);
            $changed = true;
        }

        if ($station->getState() === null && isset($firstItem['uf'])) {
            $station->setState((string) $firstItem['uf']);
            $changed = true;
        }

        // O flush da station acontece junto ao flush das observations
        // (no mesmo ciclo do EntityManager), então não é necessário
        // um flush separado aqui — a flag $changed serve apenas de documentação.
    }

    /**
     * Faz parse de string "datahora" retornada pelo CEMADEN.
     * Formatos esperados: "2025-01-15 16:00:00" ou "2025-01-15T16:00:00".
     */
    private function parseDateTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt !== false) {
                return $dt;
            }
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Realiza a requisição HTTP e retorna o JSON decodificado como lista.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchJson(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 15]);
        $data = $response->toArray();

        // A API pode retornar o array diretamente ou dentro de uma chave "data"
        if (isset($data['data']) && is_array($data['data'])) {
            return array_values($data['data']);
        }

        return array_values($data);
    }

    private function stationLabel(CemadenHidroStationLink $station): string
    {
        return sprintf(
            '[Partner %d | HidroStation %d — %s]',
            $station->getPartner()->getId(),
            $station->getId(),
            $station->getStationName() ?? $station->getCemadenTransactionId(),
        );
    }
}
