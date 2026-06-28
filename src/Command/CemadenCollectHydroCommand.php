<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coleta níveis de rios para estações hidrológicas do CEMADEN.
 *
 * Lógica de nível:
 *   offset  = distância do fundo do rio até o sensor (em metros)
 *   valor   = leitura do sensor (distância da lâmina d'água ao sensor)
 *   nível_rio = offset - valor   (quanto de água acima do fundo)
 *
 *   Se offset = null  => sensor offline / sem calibração => nível = null
 *   Se valor  = null  => leitura ausente                 => nível = null
 *
 * Salva na tabela: cemaden_hydro_readings
 *   id | station_id | measured_at | sensor_value | offset_value | river_level | is_offline | created_at
 */
#[AsCommand(
    name: 'cemaden:collect-hydro',
    description: 'Coleta níveis de rios das estações hidrológicas CEMADEN.',
)]
class CemadenCollectHydroCommand extends Command
{
    public function __construct(
        private readonly Connection          $db,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'station',
                's',
                InputOption::VALUE_OPTIONAL,
                'ID da estação hidrológica (omitir = todas as ativas)',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Mostra os dados sem salvar no banco',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $idFilter = $input->getOption('station');

        $io->title('CEMADEN Collect Hydro' . ($dryRun ? ' [DRY-RUN]' : ''));

        // Busca estações hidrológicas ativas com hydro_url preenchida
        $where  = "station_type = 'hydrological' AND is_active = 1 AND hydro_url IS NOT NULL AND hydro_url != ''";
        $params = [];

        if ($idFilter !== null) {
            $where   .= ' AND id = ?';
            $params[] = (int) $idFilter;
        }

        $stations = $this->db->fetchAllAssociative(
            "SELECT id, cod_estacao, nome, municipio, uf, partner_slug, hydro_url
             FROM cemaden_stations
             WHERE {$where}
             ORDER BY partner_slug, nome",
            $params,
        );

        if (empty($stations)) {
            $io->warning('Nenhuma estação hidrológica ativa com URL configurada encontrada.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Estações encontradas: %d', count($stations)));
        $totalInserted = 0;

        foreach ($stations as $station) {
            $io->section(sprintf(
                '[%s] %s — %s/%s',
                $station['partner_slug'],
                $station['nome'],
                $station['municipio'],
                $station['uf'],
            ));

            try {
                $inserted = $this->processStation($station, $io, $dryRun);
                $io->text("  ✓ {$inserted} novo(s) registro(s) inserido(s).");
                $totalInserted += $inserted;
            } catch (\Throwable $e) {
                $io->error(sprintf('  Erro: %s', $e->getMessage()));
            }
        }

        $io->success(sprintf(
            'Concluído. Total inserido: %d registro(s)%s.',
            $totalInserted,
            $dryRun ? ' (dry-run, nada salvo)' : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * Busca a URL da estação, processa cada leitura e insere novas na tabela.
     * Retorna o número de registros novos inseridos.
     */
    private function processStation(array $station, SymfonyStyle $io, bool $dryRun): int
    {
        $response = $this->httpClient->request('GET', $station['hydro_url'], [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $body = $response->getContent();
        $rows = json_decode($body, true);

        if (!is_array($rows) || empty($rows)) {
            $io->text('  Nenhum dado retornado pela API.');
            return 0;
        }

        $stationId = (int) $station['id'];
        $inserted  = 0;

        foreach ($rows as $raw) {
            // datahora: "2026-06-27 01:00:00"
            $measuredAt = $raw['datahora'] ?? null;
            if (!$measuredAt) continue;

            // Evita duplicata por (station_id + measured_at)
            $exists = $this->db->fetchOne(
                'SELECT 1 FROM cemaden_hydro_readings WHERE station_id = ? AND measured_at = ?',
                [$stationId, $measuredAt],
            );
            if ($exists) continue;

            // Calcula nível do rio
            // offset = distância fundo-sensor; valor = distância lâmina-sensor
            // nível = offset - valor (metros de água acima do fundo)
            $rawOffset = $raw['offset'];             // pode ser null => sensor offline
            $rawValor  = $raw['valor'];              // string numérica ou null

            $isOffline  = ($rawOffset === null);     // true se sensor não calibrado
            $offset     = $isOffline ? null : (float) $rawOffset;
            $valor      = ($rawValor !== null) ? (float) $rawValor : null;
            $riverLevel = (!$isOffline && $valor !== null) ? round($offset - $valor, 3) : null;

            if ($io->isVerbose()) {
                $io->text(sprintf(
                    '  %s | sensor=%.3f | offset=%s | nível=%s%s',
                    $measuredAt,
                    $valor ?? 0,
                    $offset !== null ? number_format($offset, 3) : 'null',
                    $riverLevel !== null ? number_format($riverLevel, 3) . ' m' : 'null',
                    $isOffline ? ' [OFFLINE]' : '',
                ));
            }

            if (!$dryRun) {
                $this->db->insert('cemaden_hydro_readings', [
                    'station_id'   => $stationId,
                    'measured_at'  => $measuredAt,
                    'sensor_value' => $valor,
                    'offset_value' => $offset,
                    'river_level'  => $riverLevel,
                    'is_offline'   => $isOffline ? 1 : 0,
                    'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            }

            $inserted++;
        }

        return $inserted;
    }
}
