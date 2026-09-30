<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Partner;
use App\Entity\User;
use App\Repository\UserRepository;
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

#[AsCommand(
    name: 'waze:briefing:daily',
    description: 'Envia o briefing diário aos usuários ativos dos parceiros.',
)]
final class WazeDailyBriefingCommand extends Command
{
    private const FROM_EMAIL = 'briefing@trafikhub.com.br';
    private const FROM_NAME = 'TrafikHub · Waze Brasil';

    public function __construct(
        private readonly BriefingDataService $dataService,
        private readonly AiNarrativeService $aiService,
        private readonly BriefingSchedulerService $scheduler,
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly Twig $twig,
        private readonly LoggerInterface $logger,
        private readonly string $dashboardBaseUrl = 'https://trafikhub.waze.com.br',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('partner-id', null, InputOption::VALUE_REQUIRED, 'Processa somente o ID do parceiro')
            ->addOption('cidade', null, InputOption::VALUE_REQUIRED, 'Filtra pela cidade cadastrada no parceiro')
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'Data de referência (Y-m-d, horário de São Paulo)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Mostra destinatários e assunto; não envia nem chama IA')
            ->addOption('no-ai', null, InputOption::VALUE_NONE, 'Desativa a narrativa IA');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $noAi = (bool) $input->getOption('no-ai');
        $dataRef = $input->getOption('data');
        $cidadeFiltro = $input->getOption('cidade');
        $partnerIdFiltro = $input->getOption('partner-id');

        if (
            $partnerIdFiltro !== null
            && (!ctype_digit((string) $partnerIdFiltro) || (int) $partnerIdFiltro < 1)
        ) {
            $io->error('--partner-id deve ser um inteiro positivo.');

            return Command::INVALID;
        }

        $io->title('Briefing Diário de Mobilidade — TrafikHub');

        if ($dryRun) {
            $io->note('Dry-run: nenhum e-mail será enviado e a IA não será chamada.');
        }

        $parceiros = $this->scheduler->getParceirosPorDia();

        if ($partnerIdFiltro !== null) {
            $parceiros = array_filter(
                $parceiros,
                static fn (array $item): bool =>
                    $item['partner']->getId() === (int) $partnerIdFiltro,
            );
        }

        if ($cidadeFiltro !== null) {
            $parceiros = array_filter(
                $parceiros,
                static fn (array $item): bool =>
                    mb_strtolower($item['cidade'])
                    === mb_strtolower(trim((string) $cidadeFiltro)),
            );
        }

        if ($parceiros === []) {
            $io->error('Nenhum parceiro ativo encontrado para o filtro informado.');

            return Command::FAILURE;
        }

        $io->text(sprintf('Parceiros a processar: %d', count($parceiros)));

        $erros = 0;
        $enviados = 0;
        $simulados = 0;
        $ignorados = 0;

        foreach ($parceiros as $item) {
            /** @var Partner $partner */
            $partner = $item['partner'];
            $cidade = $item['cidade'];
            $partnerId = $partner->getId();

            try {
                $io->section(sprintf(
                    'Parceiro %d — %s (%s)',
                    $partnerId,
                    $item['nome'],
                    $cidade,
                ));

                $emails = $this->getActiveEmails($partner);

                if ($emails === []) {
                    $ignorados++;
                    $io->warning('Nenhum usuário ativo com e-mail válido; não enviado.');
                    $this->logger->warning('waze.briefing.no_recipients', [
                        'partner_id' => $partnerId,
                    ]);

                    continue;
                }

                $dados = $this->dataService->getForPartner(
                    $partner,
                    is_string($dataRef) ? $dataRef : null,
                );

                if (
                    (int) ($dados['jams']['total'] ?? 0) === 0
                    && (int) ($dados['alertas']['total'] ?? 0) === 0
                ) {
                    $ignorados++;
                    $io->warning('Sem dados no período; não enviado.');

                    continue;
                }

                $narrativa = null;
                if (!$dryRun && !$noAi && $item['use_ai']) {
                    $narrativa = $this->aiService->generate($dados);

                    if ($narrativa === null) {
                        $io->warning('IA indisponível; envio sem narrativa.');
                    }
                }

                $parceiroTemplate = [
                    'cidade' => $cidade,
                    'nome' => $item['nome'],
                    'email' => $emails,
                    'partner_id' => $partnerId,
                ];

                $html = $this->twig->render(
                    'email/daily_briefing.html.twig',
                    [
                        'dados' => $dados,
                        'parceiro' => $parceiroTemplate,
                        'narrativa' => $narrativa,
                        'is_semanal' => false,
                        'dashboard_url' => rtrim($this->dashboardBaseUrl, '/')
                            . '/dashboard?cidade=' . rawurlencode($cidade),
                    ],
                );

                $dataFormatada = (new \DateTimeImmutable($dados['data_ref']))
                    ->format('d/m/Y');

                $assunto = sprintf(
                    '[TrafikHub] Briefing %s — %s — %s',
                    $dataFormatada,
                    $cidade,
                    $narrativa !== null ? '✦ com análise IA' : 'dados do dia',
                );

                if ($dryRun) {
                    $simulados++;
                    $io->text('[DRY-RUN] Destinatários: ' . implode(', ', $emails));
                    $io->text('Assunto: ' . $assunto);
                    $this->logger->info('waze.briefing.dry_run', [
                        'partner_id' => $partnerId,
                        'data_ref' => $dados['data_ref'],
                        'recipient_count' => count($emails),
                    ]);

                    continue;
                }

                $email = (new Email())
                    ->from(new Address(self::FROM_EMAIL, self::FROM_NAME))
                    ->subject($assunto)
                    ->html($html);

                foreach ($emails as $destinatario) {
                    $email->addTo($destinatario);
                }

                $this->mailer->send($email);
                $enviados++;

                $io->text('Envio solicitado para: ' . implode(', ', $emails));
                $this->logger->info('waze.briefing.sent', [
                    'partner_id' => $partnerId,
                    'data_ref' => $dados['data_ref'],
                    'recipient_count' => count($emails),
                    'ai_used' => $narrativa !== null,
                ]);
            } catch (\Throwable $e) {
                $erros++;
                $io->error(sprintf(
                    'Falha no parceiro %s: %s',
                    (string) $partnerId,
                    $e->getMessage(),
                ));
                $this->logger->error('waze.briefing.error', [
                    'partner_id' => $partnerId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $io->newLine();
        $io->text(sprintf(
            'Concluído: %d envio(s) solicitado(s), %d simulado(s), %d ignorado(s), %d erro(s).',
            $enviados,
            $simulados,
            $ignorados,
            $erros,
        ));

        return $erros === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function getActiveEmails(Partner $partner): array
    {
        $users = $this->userRepository->findBy([
            'partner' => $partner,
            'isActive' => true,
        ]);

        $emails = [];

        foreach ($users as $user) {
            if (!$user instanceof User || !$user->isActive()) {
                continue;
            }

            $email = trim((string) $user->getEmail());

            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[mb_strtolower($email)] = $email;
            }
        }

        return array_values($emails);
    }
}
