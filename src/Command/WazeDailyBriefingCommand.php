<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AiNarrativeService;
use App\Service\BriefingDataService;
use App\Service\BriefingSchedulerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment as Twig;

/**
 * Envia o briefing diário de mobilidade urbana para todos os parceiros ativos.
 *
 * Uso:
 *   php bin/console waze:briefing:daily
 *   php bin/console waze:briefing:daily --cidade="Conselheiro Lafaiete"  # testa um parceiro
 *   php bin/console waze:briefing:daily --dry-run                         # sem enviar e-mails
 *   php bin/console waze:briefing:daily --data=2026-09-27                 # data específica
 *   php bin/console waze:briefing:daily --no-ai                           # força sem IA
 *
 * Crontab (UTC — 06h BRT = 09h UTC):
 *   0 9 * * 1-5 /usr/bin/php /var/www/html/bin/console waze:briefing:daily >> /var/log/briefing.log 2>&1
 */
#[AsCommand(
    name: 'waze:briefing:daily',
    description: 'Envia o briefing diário de mobilidade urbana para os parceiros Waze.',
)]
final class WazeDailyBriefingCommand extends Command
{
    private const FROM_EMAIL = 'briefing@trafikhub.com.br';
    private const FROM_NAME  = 'TrafikHub · Waze Brasil';

    public function __construct(
        private readonly BriefingDataService     $dataService,
        private readonly AiNarrativeService      $aiService,
        private readonly BriefingSchedulerService $scheduler,
        private readonly MailerInterface          $mailer,
        private readonly Twig                     $twig,
        private readonly LoggerInterface          $logger,
        private readonly string                   $dashboardBaseUrl = 'https://trafikhub.waze.com.br',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('cidade',  null, InputOption::VALUE_REQUIRED, 'Processa somente esta cidade')
            ->addOption('data',    null, InputOption::VALUE_REQUIRED, 'Data de referência (Y-m-d, BRT)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Não envia e-mails, apenas imprime')
            ->addOption('no-ai',   null, InputOption::VALUE_NONE,     'Desativa a geração de narrativa IA');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $noAi   = $input->getOption('no-ai');
        $dataRef = $input->getOption('data');
        $cidadeFiltro = $input->getOption('cidade');

        $io->title('Briefing Diário de Mobilidade — TrafikHub');

        if ($dryRun) {
            $io->note('Modo dry-run: nenhum e-mail será enviado.');
        }

        $parceiros = $this->scheduler->getParceirosPorDia();

        // Aplica filtro por cidade se passado via option
        if ($cidadeFiltro !== null) {
            $parceiros = array_filter(
                $parceiros,
                fn(array $p) => mb_strtolower($p['cidade']) === mb_strtolower($cidadeFiltro)
            );

            if (empty($parceiros)) {
                $io->error("Nenhum parceiro ativo encontrado para cidade: {$cidadeFiltro}");
                return Command::FAILURE;
            }
        }

        // Filtra somente parceiros ativos
        $parceiros = array_filter($parceiros, fn(array $p) => $p['ativo'] ?? true);

        $io->text(sprintf('Parceiros a processar: %d', count($parceiros)));

        $erros   = 0;
        $enviados = 0;

        foreach ($parceiros as $parceiro) {
            $cidade = $parceiro['cidade'];

            try {
                $io->section("📍 {$cidade}");

                // 1. Agrega dados do banco
                $io->text('  → Coletando dados...');
                $dados = $this->dataService->getForCity($cidade, $dataRef);

                if ((int) ($dados['jams']['total'] ?? 0) === 0 && (int) ($dados['alertas']['total'] ?? 0) === 0) {
                    $io->warning("  Sem dados para {$cidade} em {$dados['data_ref']} — briefing não enviado.");
                    continue;
                }

                // 2. Gera narrativa de IA (se habilitado para hoje e não desativado por option)
                $narrativa = null;
                $useAi     = ($parceiro['use_ai'] ?? true) && !$noAi;

                if ($useAi) {
                    $io->text('  → Gerando narrativa IA (Gemini)...');
                    $promptExtra = $parceiro['prompt_extra'] ?? null;
                    $narrativa   = $this->aiService->generate($dados, null);

                    if ($narrativa === null) {
                        $io->warning('  IA indisponível — e-mail vai sem narrativa.');
                    } else {
                        $io->text('  ✔ Narrativa gerada.');
                    }
                } else {
                    $io->text('  → IA desativada para este parceiro/dia.');
                }

                // 3. Renderiza template Twig
                $html = $this->twig->render('email/daily_briefing.html.twig', [
                    'dados'         => $dados,
                    'parceiro'      => $parceiro,
                    'narrativa'     => $narrativa,
                    'is_semanal'    => false,
                    'dashboard_url' => $this->dashboardBaseUrl . '/dashboard?cidade=' . urlencode($cidade),
                ]);

                // 4. Monta e envia o e-mail
                $dataFormatada = (new \DateTimeImmutable($dados['data_ref']))->format('d/m/Y');
                $assunto = sprintf(
                    '[TrafikHub] Briefing %s — %s — %s',
                    $dataFormatada,
                    $cidade,
                    $narrativa ? '✦ com análise IA' : 'dados do dia'
                );

                $email = (new Email())
                    ->from(new Address(self::FROM_EMAIL, self::FROM_NAME))
                    ->subject($assunto)
                    ->html($html);

                foreach ((array) $parceiro['email'] as $dest) {
                    $email->addTo($dest);
                }

                if ($dryRun) {
                    $io->text("  [DRY-RUN] E-mail montado para: " . implode(', ', (array) $parceiro['email']));
                    $io->text("  Assunto: {$assunto}");
                } else {
                    $this->mailer->send($email);
                    $io->text('  ✔ E-mail enviado para: ' . implode(', ', (array) $parceiro['email']));
                }

                $this->logger->info('waze.briefing.sent', [
                    'cidade'       => $cidade,
                    'data_ref'     => $dados['data_ref'],
                    'total_jams'   => $dados['jams']['total'],
                    'total_alertas'=> $dados['alertas']['total'],
                    'ai_used'      => $useAi && $narrativa !== null,
                    'dry_run'      => $dryRun,
                ]);

                $enviados++;

            } catch (\Throwable $e) {
                $erros++;
                $io->error("  Falha em {$cidade}: " . $e->getMessage());
                $this->logger->error('waze.briefing.error', [
                    'cidade' => $cidade,
                    'error'  => $e->getMessage(),
                    'trace'  => $e->getTraceAsString(),
                ]);
                // Continua para o próximo parceiro — não deixa um erro derrubar todos
            }
        }

        // ── Resumo ──────────────────────────────────────────────────────────
        $io->newLine();
        $io->success(sprintf(
            'Concluído: %d enviado(s), %d erro(s).',
            $enviados,
            $erros,
        ));

        return $erros === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
