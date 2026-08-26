<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CemadenHydroData;
use App\Entity\CemadenStation;
use App\Entity\Partner;
use App\Repository\CemadenStationRepository;
use App\Repository\PartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Coleta níveis de rios das estações hidrológicas CEMADEN.
 *
 * IMPORTANTE (mudança): --partner deixou de ser obrigatório. Antes, rodar
 * este comando via cron exigia saber o ID de cada parceiro e chamar o
 * comando uma vez por parceiro. Agora, se --partner for omitido, o
 * comando itera automaticamente todos os parceiros ativos — é o que o
 * job "cemaden_hydro" do cron.php espera (uma única chamada cobre todo
 * mundo, igual aos demais comandos de coleta).
 */
#[AsCommand(
    name: 'cemaden:collect-hydro',
    description: 'Coleta níveis de rios das estações hidrológicas CEMADEN e persiste na tabela cemaden_hydro_data.',
)]
class CemadenCollectHydroCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CemadenStationRepository $stationRepo,
        private readonly PartnerRepository      $partnerRepo,
        private readonly HttpClientInterface    $httpClient,
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
                'ID do parceiro (omitir = todos os parceiros ativos)',
            )
            ->addOption(
                'station',
                's',
                InputOption::VALUE_OPTIONAL,
                'ID da estação hidrológica (omitir = todas as ativas do(s) parceiro(s) selecionado(s))',
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
        $io        = new SymfonyStyle($input, $output);
        $dryRun    = (bool) $input->getOption('dry-run');
        $partnerId = $input->getOption('partner');
        $stationId = $input->getOption('station');

        $io->title('CEMADEN Collect Hydro' . ($dryRun ? ' [DRY-RUN]' : ''));

        if ($partnerId !== null) {
            $partner = $this->partnerRepo->find((int) $partnerId);
            if (!$partner) {
                $io->error('Parceiro com ID ' . $partnerId . ' não encontrado.');
                return Command::FAILURE;
            }
            $partners = [$partner];
        } else {
            $partners = $this->partnerRepo->findActivePartners();
            if (empty($partners)) {
                $io->warning('Nenhum parceiro ativo encontrado.');
                return Command::SUCCESS;
            }
            $io->text(sprintf('Nenhum --partner informado — processando todos os %d parceiro(s) ativo(s).', count($partners)));
        }

        $totalInserted = 0;
        $totalErrors   = 0;

        foreach ($partners as $partner) {
            $totalInserted += $this->processPartner($partner, $stationId, $io, $dryRun, $totalErrors);
        }

        $io->newLine();
        if ($totalErrors > 0) {
            $io->warning(sprintf(
                'Concluído com erros — Total inserido: %d registro(s)%s | Erros: %d.',
                $totalInserted,
                $dryRun ? ' (dry-run, nada salvo)' : '',
                $totalErrors,
            ));
        } else {
            $io->success(sprintf(
                'Concluído. Total inserido: %d registro(s)%s.',
                $totalInserted,
                $dryRun ? ' (dry-run, nada salvo)' : '',
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Processa todas as estações hidrológicas ativas de um parceiro
     * (ou apenas uma, se --station for informado). Retorna o total
     * de registros inseridos para este parceiro. Incrementa
     * $totalErrors por referência para o resumo final.
     */
    private function processPartner(
        Partner $partner,
        int|string|null $stationId,
        SymfonyStyle $io,
        bool $dryRun,
        int &$totalErrors,
    ): int {
        $io->section(sprintf('Parceiro: %s (ID: %d)', $partner->getName(), $partner->getId()));

        $stations = $stationId
            ? [$this->stationRepo->findActiveHydrologicalByIdAndPartner((int) $stationId, $partner)]
            : $this->stationRepo->findActiveHydrologicalByPartner($partner);

        $stations = array_filter($stations);

        if (empty($stations)) {
            $io->warning('Nenhuma estação hidrológica ativa com URL configurada para este parceiro.');
            return 0;
        }

        $io->text(sprintf('Estações encontradas: %d', count($stations)));
        $inserted = 0;

        foreach ($stations as $station) {
            $io->writeln(sprintf(
                '  [%s] %s — %s/%s',
                $station->getPartner()->getSlug(),
                $station->getNome(),
                $station->getMunicipio(),
                $station->getUf(),
            ));

            try {
                $stationInserted = $this->processStation($station, $io, $dryRun);
                $io->text("    ✓ {$stationInserted} novo(s) registro(s) inserido(s).");
                $inserted += $stationInserted;
            } catch (\Throwable $e) {
                $totalErrors++;
                $io->error(sprintf('    Erro: %s', $e->getMessage()));
            }
        }

        return $inserted;
    }

    private function sanitizeUrl(string $url): string
    {
        return trim(preg_replace('/[\r\n\t]+/', '', $url));
    }

    private function processStation(CemadenStation $station, SymfonyStyle $io, bool $dryRun): int
    {
        $url = $this->sanitizeUrl($station->getHydroUrl());

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $body = $response->getContent();
        $rows = json_decode($body, true);

        if (!is_array($rows) || empty($rows)) {
            $io->text('    Nenhum dado retornado pela API.');
            return 0;
        }

        $partner  = $station->getPartner();
        $inserted = 0;

        foreach ($rows as $raw) {
            $measuredAt = $raw['datahora'] ?? null;
            if (!$measuredAt) {
                continue;
            }

            // Evita duplicatas
            $existing = $this->em->getRepository(CemadenHydroData::class)->findOneBy([
                'stationCode' => $station->getCodEstacao(),
                'measuredAt'  => new \DateTimeImmutable($measuredAt),
            ]);
            if ($existing) {
                continue;
            }

            // --- CÁLCULO CORRETO DO NÍVEL ---
            // offset = distância do fundo do rio até o sensor
            // valor  = distância da lâmina d'água até o sensor
            // nível  = offset - valor (metros de água acima do fundo)
            $offset     = isset($raw['offset']) ? (float) $raw['offset'] : null;
            $valor      = isset($raw['valor'])  ? (float) $raw['valor']  : null;
            $waterLevel = ($offset !== null && $valor !== null) ? round($offset - $valor, 3) : null;

            // Extrai cotas
            $cotaAtencao = isset($raw['cota_atencao']) ? (float) $raw['cota_atencao'] : null;
            $cotaAlerta  = isset($raw['cota_alerta'])  ? (float) $raw['cota_alerta']  : null;
            $cotaTransb  = isset($raw['cota_transbordamento']) ? (float) $raw['cota_transbordamento'] : null;

            // Determina alerta com base no nível calculado
            $alertLevel = $this->determineAlertLevel($waterLevel, $cotaAtencao, $cotaAlerta, $cotaTransb);

            // Cria entidade
            $hydro = new CemadenHydroData();
            $hydro->setStationCode($station->getCodEstacao());
            $hydro->setStationName($station->getNome());
            $hydro->setMunicipality($station->getMunicipio());
            $hydro->setState($station->getUf());
            $hydro->setWaterLevel($waterLevel);      // nível real em metros
            $hydro->setOffsetValue($offset);         // guarda offset para referência
            $hydro->setQualificacao($raw['qualificacao'] ?? null);
            $hydro->setCotaAtencao($cotaAtencao);
            $hydro->setCotaAlerta($cotaAlerta);
            $hydro->setCotaTransbordamento($cotaTransb);
            $hydro->setAlertLevel($alertLevel);
            $hydro->setPartner($partner);
            $hydro->setMeasuredAt(new \DateTimeImmutable($measuredAt));

            if ($io->isVerbose()) {
                $io->text(sprintf(
                    '    %s | offset=%.3f | valor=%.3f | nível=%.3f | alerta=%s',
                    $measuredAt,
                    $offset ?? 0,
                    $valor ?? 0,
                    $waterLevel ?? 0,
                    $alertLevel ?? 'normal',
                ));
            }

            if (!$dryRun) {
                $this->em->persist($hydro);
                $this->em->flush();
            }

            $inserted++;
        }

        return $inserted;
    }

    private function determineAlertLevel(?float $level, ?float $cotaAtencao, ?float $cotaAlerta, ?float $cotaTransb): ?string
    {
        if ($level === null) {
            return 'normal';
        }

        if ($cotaTransb !== null && $level >= $cotaTransb) {
            return 'transbordamento';
        }
        if ($cotaAlerta !== null && $level >= $cotaAlerta) {
            return 'alerta';
        }
        if ($cotaAtencao !== null && $level >= $cotaAtencao) {
            return 'atencao';
        }

        return 'normal';
    }
}
