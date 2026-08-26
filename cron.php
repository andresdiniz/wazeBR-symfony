<?php

declare(strict_types=1);

/**
 * cron.php — Dispatcher de coleta para hospedagem compartilhada (Hostinger PHP 8.5)
 * =============================================================================
 *
 * SUBSTITUI o antigo cron.php (que só disparava messenger:consume). Nesta
 * hospedagem NÃO há Supervisor rodando, então o Symfony Scheduler
 * (WazeFeedSchedule / scheduler_waze_feed) nunca é consumido e as mensagens
 * nunca saem da fila. Por isso, aqui os comandos de coleta são chamados
 * DIRETAMENTE via `bin/console`, um por job, com sua própria frequência —
 * exatamente como em doc/cron-reference.md, mas agora com lock, timeout,
 * log rotativo e status.json para observabilidade.
 *
 * USO
 * ---
 * Configure MÚLTIPLAS tarefas no Agendador de Tarefas da Hostinger
 * (uma entrada por job, cada uma com sua frequência), chamando este
 * arquivo via CLI (nunca via HTTP):
 *
 *   /usr/local/bin/php8.5 /home/uXXXXXXXXX/domains/SEUDOMINIO/public_html/trafik/cron.php <job>
 *
 * Jobs disponíveis e frequência sugerida (ver expressão cron completa
 * no final deste bloco, fora do comentário, para copiar sem risco de
 * o "asterisco-barra" fechar este comentário antes da hora):
 *
 *   waze_feed          A cada 5 minutos  — Alertas e jams Waze (PartnerHub)
 *   waze_routes        A cada 5 minutos  — Tempos de rota e irregularidades
 *   waze_tvt           A cada 5 minutos  — Snapshots de rotas TVT
 *   cemaden            A cada 15 minutos — Pluviométrico CEMADEN
 *   cemaden_hydro      A cada 30 minutos — Nível de rios (todos os parceiros)
 *   notify             A cada 10 minutos — Notificações (alertas críticos + CEMADEN)
 *   notify_high_risk   A cada 10 minutos — Notificações legadas de alto risco
 *   report             Diário às 06:00   — Relatório diário por e-mail
 *
 * Uso manual/debug (roda tudo em sequência, não recomendado em produção):
 *
 *   php cron.php all
 *
 * GARANTIAS
 * ---------
 * - Lock por job via flock(): se a execução anterior do MESMO job ainda
 *   estiver rodando, esta chamada é pulada (sem erro, sem duplicar coleta).
 * - Timeout por job: se o processo passar do limite configurado, é
 *   finalizado (SIGTERM e, se necessário, SIGKILL) para nunca travar
 *   o cron seguinte.
 * - Log próprio por job em var/log/cron_<job>.log, com rotação simples
 *   por tamanho (mantém 1 arquivo anterior).
 * - var/log/cron_status.json é atualizado a cada execução com o
 *   resultado (ok / error / timeout / skipped_running), consumido pelo
 *   endpoint /cron/run para health-check externo.
 * - proc_open() com array de argumentos (sem passar por shell), evitando
 *   os riscos de injeção do antigo exec($cmd) com escapeshellarg manual.
 */

// A expressão cron completa de cada linha (com "asterisco barra N") fica
// em doc/cron-reference.md — não é reproduzida aqui dentro do docblock
// acima porque a sequência de fechamento de comentário do PHP coincide
// com a sintaxe "*/N" do cron e quebraria o parser.

// -----------------------------------------------------------------------------
// Configuração
// -----------------------------------------------------------------------------

$phpBinary  = getenv('CRON_PHP_BINARY') ?: '/usr/local/bin/php8.5';
$projectDir = __DIR__;
$logDir     = $projectDir . '/var/log';
$lockDir    = $projectDir . '/var/cron-locks';
$statusFile = $logDir . '/cron_status.json';

/** Tamanho máximo (bytes) de cada log antes de rotacionar. */
const CRON_MAX_LOG_BYTES = 2 * 1024 * 1024; // 2MB

/**
 * Mapa de jobs → comando Symfony + timeout (segundos).
 * O timeout deve sempre ficar abaixo do intervalo do próprio job
 * (ex.: job a cada 5 min → timeout bem menor que 300s) para nunca
 * haver duas execuções se sobrepondo mesmo com o lock.
 */
$jobs = [
    'waze_feed' => [
        'cmd'     => ['app:waze:collect-feed'],
        'timeout' => 50,
        'desc'    => 'Alertas e congestionamentos Waze (feed PartnerHub)',
    ],
    'waze_routes' => [
        'cmd'     => ['app:waze:collect-routes'],
        'timeout' => 50,
        'desc'    => 'Tempos de rota e irregularidades Waze',
    ],
    'waze_tvt' => [
        'cmd'     => ['app:waze:collect-tvt'],
        'timeout' => 50,
        'desc'    => 'Snapshots de rotas do feed TVT',
    ],
    'cemaden' => [
        'cmd'     => ['cemaden:collect'],
        'timeout' => 50,
        'desc'    => 'Dados pluviométricos CEMADEN (todos os parceiros)',
    ],
    'cemaden_hydro' => [
        'cmd'     => ['cemaden:collect-hydro'],
        'timeout' => 60,
        'desc'    => 'Níveis de rios (hidrológico) CEMADEN — todos os parceiros ativos',
    ],
    'notify' => [
        'cmd'     => ['notifications:dispatch'],
        'timeout' => 40,
        'desc'    => 'Notificações de alertas críticos e CEMADEN por parceiro',
    ],
    'notify_high_risk' => [
        'cmd'     => ['waze:notify:high-risk'],
        'timeout' => 40,
        'desc'    => 'Notificações legadas de alto risco (single-tenant)',
    ],
    'report' => [
        'cmd'     => ['waze:report:daily'],
        'timeout' => 90,
        'desc'    => 'Relatório diário por e-mail',
    ],
];

// -----------------------------------------------------------------------------
// Entrada
// -----------------------------------------------------------------------------

$job = $argv[1] ?? null;

if ($job === null) {
    fwrite(STDERR, "Uso: php cron.php <job>\n\nJobs disponíveis:\n");
    foreach ($jobs as $name => $def) {
        fwrite(STDERR, sprintf("  %-18s %s\n", $name, $def['desc']));
    }
    fwrite(STDERR, "  all               Roda todos os jobs em sequência (uso manual/debug)\n");
    exit(1);
}

if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
    fwrite(STDERR, "Não foi possível criar o diretório de log: {$logDir}\n");
    exit(1);
}

if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "Não foi possível criar o diretório de locks: {$lockDir}\n");
    exit(1);
}

if ($job === 'all') {
    $overallExit = 0;
    foreach (array_keys($jobs) as $name) {
        $result       = cronRunJob($name, $jobs[$name], $phpBinary, $projectDir, $logDir, $lockDir, $statusFile);
        $overallExit |= $result;
    }
    exit($overallExit === 0 ? 0 : 1);
}

if (!isset($jobs[$job])) {
    fwrite(STDERR, "Job desconhecido: {$job}\n");
    fwrite(STDERR, "Jobs válidos: " . implode(', ', array_keys($jobs)) . ", all\n");
    exit(1);
}

exit(cronRunJob($job, $jobs[$job], $phpBinary, $projectDir, $logDir, $lockDir, $statusFile));

// =============================================================================
// Funções
// =============================================================================

/**
 * Executa um único job: adquire lock, roda bin/console com timeout,
 * grava log e atualiza o status.json. Retorna 0 em sucesso, 1 caso contrário.
 */
function cronRunJob(
    string $name,
    array $def,
    string $phpBinary,
    string $projectDir,
    string $logDir,
    string $lockDir,
    string $statusFile,
): int {
    $lockPath   = $lockDir . '/' . $name . '.lock';
    $lockHandle = fopen($lockPath, 'c');

    if ($lockHandle === false) {
        fwrite(STDERR, "[{$name}] Não foi possível abrir o arquivo de lock: {$lockPath}\n");
        return 1;
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        // Execução anterior deste job ainda em andamento — não é erro, apenas pula.
        cronWriteStatus($statusFile, $name, [
            'status'    => 'skipped_running',
            'timestamp' => date('c'),
            'message'   => 'Execução anterior ainda em andamento — pulado para não sobrepor.',
        ]);
        fclose($lockHandle);
        return 0;
    }

    $logFile = $logDir . '/cron_' . $name . '.log';
    cronRotateLogIfNeeded($logFile, CRON_MAX_LOG_BYTES);

    $consoleCmd = array_merge(
        [$phpBinary, $projectDir . '/bin/console'],
        $def['cmd'],
        ['--env=prod', '--no-interaction'],
    );

    $startedAt = microtime(true);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($consoleCmd, $descriptorSpec, $pipes, $projectDir);

    if (!is_resource($process)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        cronAppendLog($logFile, sprintf(
            "[%s] ERRO: não foi possível iniciar o processo para o job '%s'.\n",
            date('c'),
            $name,
        ));

        cronWriteStatus($statusFile, $name, [
            'status'    => 'error',
            'timestamp' => date('c'),
            'message'   => 'Falha ao iniciar proc_open().',
        ]);

        return 1;
    }

    // Não usamos stdin.
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output  = '';
    $timeout = $def['timeout'];
    $killed  = false;

    while (true) {
        $status = proc_get_status($process);

        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);

        if (!$status['running']) {
            break;
        }

        if ((microtime(true) - $startedAt) > $timeout) {
            proc_terminate($process, 15); // SIGTERM
            usleep(500_000);

            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9); // SIGKILL
            }

            $killed = true;
            break;
        }

        usleep(200_000);
    }

    // Coleta qualquer resquício de saída após o loop.
    $output .= (string) stream_get_contents($pipes[1]);
    $output .= (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode    = $killed ? 124 : proc_close($process);
    $durationSec = round(microtime(true) - $startedAt, 2);

    cronAppendLog($logFile, sprintf(
        "[%s] job=%s exit=%d duration=%ss%s\n%s\n",
        date('c'),
        $name,
        $exitCode,
        $durationSec,
        $killed ? ' KILLED_TIMEOUT' : '',
        trim($output) !== '' ? trim($output) : '(sem saída)',
    ));

    cronWriteStatus($statusFile, $name, [
        'status'     => $killed ? 'timeout' : ($exitCode === 0 ? 'ok' : 'error'),
        'exit_code'  => $exitCode,
        'duration_s' => $durationSec,
        'timestamp'  => date('c'),
        'timed_out'  => $killed,
    ]);

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    return $killed ? 1 : ($exitCode === 0 ? 0 : 1);
}

/** Anexa uma linha ao log do job, com lock para evitar corrupção em escrita concorrente. */
function cronAppendLog(string $path, string $line): void
{
    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/** Rotaciona o log se ele passar do tamanho máximo, mantendo 1 arquivo anterior (.1). */
function cronRotateLogIfNeeded(string $path, int $maxBytes): void
{
    if (is_file($path) && filesize($path) > $maxBytes) {
        $rotated = $path . '.1';
        if (is_file($rotated)) {
            unlink($rotated);
        }
        rename($path, $rotated);
    }
}

/**
 * Atualiza a entrada de um job dentro de var/log/cron_status.json,
 * preservando o status dos demais jobs. Usa lock exclusivo no
 * arquivo para evitar leitura/escrita concorrente entre jobs
 * disparados quase ao mesmo tempo.
 */
function cronWriteStatus(string $statusFile, string $job, array $data): void
{
    $fp = fopen($statusFile, 'c+');
    if ($fp === false) {
        return;
    }

    if (flock($fp, LOCK_EX)) {
        $contents = stream_get_contents($fp);
        $all      = json_decode($contents !== false ? $contents : '', true);

        if (!is_array($all)) {
            $all = [];
        }

        $all[$job] = $data;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) json_encode(
            $all,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        fflush($fp);
        flock($fp, LOCK_UN);
    }

    fclose($fp);
}
