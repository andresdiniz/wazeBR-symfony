<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\PartnerRepository;
use App\Service\PartnerFeedService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:partner-feed:update-json',
    description: 'Lê os eventos ativos e atualiza o JSON CIFS de cada parceiro.',
)]
final class UpdatePartnerFeedJsonCommand extends Command
{
    public function __construct(
        private readonly PartnerRepository $partnerRepository,
        private readonly PartnerFeedService $feedService,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'partner',
            'p',
            InputOption::VALUE_OPTIONAL,
            'ID do parceiro. Sem esta opção, processa todos os parceiros ativos.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $partnerId = $input->getOption('partner');

        $partners = $partnerId !== null
            ? $this->partnerRepository->findBy(['id' => (int) $partnerId, 'isActive' => true])
            : $this->partnerRepository->findBy(['isActive' => true], ['id' => 'ASC']);

        if ($partners === []) {
            $io->warning('Nenhum parceiro ativo foi encontrado.');
            return Command::SUCCESS;
        }

        $feedsDir = $this->kernel->getProjectDir() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'feeds';

        $io->writeln('Diretório do projeto: ' . $this->kernel->getProjectDir());
        $io->writeln('Diretório dos feeds: ' . $feedsDir);

        if (!is_dir($feedsDir)) {
            if (!mkdir($feedsDir, 0775, true) && !is_dir($feedsDir)) {
                $io->error(sprintf('Não foi possível criar o diretório: %s', $feedsDir));
                return Command::FAILURE;
            }
            $io->writeln('Diretório criado: ' . $feedsDir);
        }

        if (!is_writable($feedsDir)) {
            $io->error(sprintf('O diretório não tem permissão de escrita: %s', $feedsDir));
            return Command::FAILURE;
        }

        $updated = 0;
        $errors = 0;

        foreach ($partners as $partner) {
            try {
                $feed = $this->feedService->buildFeed($partner);

                $json = json_encode(
                    $feed,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
                );

                $filename = $feedsDir . DIRECTORY_SEPARATOR . $partner->getCode() . '.json';

                $bytes = file_put_contents($filename, $json, LOCK_EX);

                if ($bytes === false) {
                    throw new \RuntimeException('Falha ao gravar o arquivo JSON.');
                }

                $io->writeln(sprintf(
                    'Parceiro %s (%s): %d incidente(s). Arquivo: %s (%d bytes)',
                    $partner->getId(),
                    $partner->getName(),
                    count($feed['incidents'] ?? []),
                    $filename,
                    $bytes
                ));

                $updated++;
            } catch (\Throwable $exception) {
                $io->error(sprintf(
                    'Falha no parceiro %s: %s',
                    $partner->getId(),
                    $exception->getMessage(),
                ));
                $errors++;
            }
        }

        $io->newLine();
        $io->success(sprintf('%d parceiro(s) processado(s).', $updated));

        if ($errors > 0) {
            $io->warning(sprintf('%d erro(s) ocorrido(s).', $errors));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}