<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Comando temporario de debug — inspeciona a estrutura bruta do JSON
 * retornado por qualquer URL do Waze sem tentar salvar nada.
 *
 * Uso:
 *   php bin/console app:waze:debug-feed <url>
 *   php bin/console app:waze:debug-feed <url> --depth=3
 */
#[AsCommand(
    name: 'app:waze:debug-feed',
    description: '[DEBUG] Mostra a estrutura bruta do JSON retornado por um feed Waze',
)]
class WazeDebugFeedCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'URL completa do feed Waze')
            ->addOption('depth', 'd', InputOption::VALUE_OPTIONAL, 'Profundidade maxima para exibir (padrao 2)', 2)
            ->addOption('raw', 'r', InputOption::VALUE_NONE, 'Exibir JSON bruto completo em vez do resumo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $url   = $input->getArgument('url');
        $depth = (int) $input->getOption('depth');
        $raw   = $input->getOption('raw');

        $io->title('Waze Feed — Inspeção de Estrutura JSON');
        $io->text("URL: <info>{$url}</info>");
        $io->newLine();

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $statusCode = $response->getStatusCode();
        $io->text("HTTP Status: <comment>{$statusCode}</comment>");

        $content = $response->getContent();
        $io->text('Tamanho da resposta: <comment>' . number_format(strlen($content)) . ' bytes</comment>');
        $io->newLine();

        if ($raw) {
            $io->section('JSON Bruto (primeiros 8000 chars)');
            $io->text(substr($content, 0, 8000));
            return Command::SUCCESS;
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        // --- Resumo das chaves de primeiro nivel ---
        $io->section('Chaves de primeiro nivel');
        $rows = [];
        foreach ($data as $key => $value) {
            $type  = gettype($value);
            $count = is_array($value) ? count($value) : '-';
            $preview = $this->preview($value);
            $rows[] = [$key, $type, $count, $preview];
        }
        $io->table(['Chave', 'Tipo', 'Count', 'Preview'], $rows);

        // --- Primeiro item de cada array encontrado ---
        $io->section('Primeiro item de cada array encontrado');
        foreach ($data as $key => $value) {
            if (is_array($value) && count($value) > 0 && isset($value[0])) {
                $io->text("<info>[{$key}][0]</info> — " . count($value) . ' itens');
                $io->text(json_encode($value[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $io->newLine();
            }
        }

        // --- Verifica chaves esperadas pelo servico ---
        $io->section('Verificação de chaves esperadas pelo WazeTrafficFeedService');
        $expected = ['jams', 'startTimeMillis', 'endTimeMillis'];
        foreach ($expected as $key) {
            $exists = array_key_exists($key, $data);
            $icon   = $exists ? '<info>✓</info>' : '<error>✗</error>';
            $info   = $exists ? ('tipo: ' . gettype($data[$key]) . (is_array($data[$key]) ? ', count: ' . count($data[$key]) : '')) : 'AUSENTE';
            $io->text("{$icon} {$key}: {$info}");
        }

        $io->newLine();
        $io->section('Verificação de chaves esperadas pelo WazeFeedService (alertas)');
        $expectedAlerts = ['alerts', 'startTimeMillis', 'endTimeMillis'];
        foreach ($expectedAlerts as $key) {
            $exists = array_key_exists($key, $data);
            $icon   = $exists ? '<info>✓</info>' : '<error>✗</error>';
            $info   = $exists ? ('tipo: ' . gettype($data[$key]) . (is_array($data[$key]) ? ', count: ' . count($data[$key]) : '')) : 'AUSENTE';
            $io->text("{$icon} {$key}: {$info}");
        }

        return Command::SUCCESS;
    }

    private function preview(mixed $value): string
    {
        if (is_array($value)) {
            return '[array com ' . count($value) . ' itens]';
        }
        if (is_string($value)) {
            return mb_substr($value, 0, 60);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }
}
