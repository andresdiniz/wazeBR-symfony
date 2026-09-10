<?php

declare(strict_types=1);

/**
 * cron.php — Dispatcher de coleta para hospedagem compartilhada (Hostinger)
 * =============================================================================
 *
 * Chama os comandos de coleta DIRETAMENTE via `bin/console`, um job por
 * vez, com lock (nunca roda o mesmo job em paralelo), timeout (mata o
 * processo se travar), log rotativo e um status.json para observabilidade.
 *
 * Pode ser chamado de duas formas:
 *
 *   1. CLI direto (Agendador de Tarefas da Hostinger ou Windows local):
 *        php cron.php <job>
 *
 *   2. Via HTTP, através de /cron/trigger/{job} (ver CronController) —
 *      usado quando o cron da Hostinger só permite configurar uma URL
 *      (ex.: usando wget) em vez de rodar um binario diretamente.
 *
 * Jobs disponiveis: waze_feed, waze_routes, waze_tvt, waze_collect_all,
 * cemaden, cemaden_hydro, notify, notify_high_risk, report, all (debug).
 *
 * Detecao do binario PHP: por padrao usa a constante PHP_BINARY (o
 * mesmo interpretador que ja esta executando este script — sempre
 * correto, tanto em Linux quanto em Windows, sem precisar hardcodar
 * caminho nenhum). Pode ser sobrescrito com a variavel de ambiente
 * CRON_PHP_BINARY, ou com um arquivo opcional `cron.local.php` na
 * mesma pasta (nao versionado — ideal para overrides so do seu ambiente
 * local), que se existir e incluido e pode redefinir $phpBinary.
 */

// -----------------------------------------------------------------------------
// Configuraao
// -----------------------------------------------------------------------------

$projectDir = __DIR__;
$logDir     = $projectDir . '/var/log';
$lockDir    = $projectDir . '/var/cron-locks';
$statusFile = $logDir . '/cron_status.json';

if (!defined('STDIN')) {
    define('STDIN', fopen('php://stdin', 'r'));
}
if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'w'));
}
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

$phpBinary = getenv('CRON_PHP_BINARY');
if ($phpBinary === false || $phpBinary === '') {
    $phpBinary = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
}

$localOverride = $projectDir . '/cron.local.php';
if (is_file($localOverride)) {
    require $localOverride;
}

const CRON_MAX_LOG_BYTES = 2 * 1024 * 1024;

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
        'cmd'     => ['waze:collect-tvt'],
        'timeout' => 50,
        'desc'    => 'Snapshots de rotas do feed TVT',
    ],
    'waze_collect_all' => [
        'cmd'     => ['waze:collect-feed'],
        'timeout' => 90,
        'desc'    => 'Coleta completa Waze: alerts, jams E routes (EVENTS + TVT)',
    ],
    'cemaden' => [
        'cmd'     => ['cemaden:collect'],
        'timeout' => 50,
        'desc'    => 'Dados pluviometricos CEMADEN (todos os parceiros)',
    ],
    'cemaden_hydro' => [
        'cmd'     => ['cemaden:collect-hydro'],
        'timeout' => 60,
        'desc'    => 'Niveis de rios (hidrologico) CEMADEN — todos os parceiros ativos',
    ],
    'notify' => [
        'cmd'     => ['notifications:dispatch'],
        'timeout' => 40,
        'desc'    => 'Notificaoes de alertas criticos e CEMADEN por parceiro',
    ],
    'notify_high_risk' => [
        'cmd'     => ['waze:notify:high-risk'],
        'timeout' => 40,
        'desc'    => 'Notificaoes legadas de alto risco (single-tenant)',
    ],
    'report' => [
        'cmd'     => ['waze:report:daily'],
        'timeout' => 90,
        'desc'    => 'Relatorio diario por e-mail',
    ],
];

// -----------------------------------------------------------------------------
// Entrada
// -----------------------------------------------------------------------------

$job = $argv[1] ?? null;

if ($job === null) {
    fwrite(STDERR, "Uso: php cron.php <job>\n\nJobs disponiveis:\n");
    foreach ($jobs as $name => $def) {
        fwrite(STDERR, sprintf("  %-18s %s\n", $name, $def['desc']));
    }
    fwrite(STDERR, "  all               Roda todos os jobs em sequencia (uso manual/debug)\n");
    exit(1);
}

if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
    fwrite(STDERR, "Nao foi possivel criar o diretorio de log: {$logDir}\n");
    exit(1);
}

if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "Nao foi possivel criar o diretorio de locks: {$lockDir}\n");
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
    fwrite(STDERR, "Jobs validos: " . implode(', ', array_keys($jobs)) . ", all\n");
    exit(1);
}

exit(cronRunJob($job, $jobs[$job], $phpBinary, $projectDir, $logDir, $lockDir, $statusFile));

// =============================================================================
// Funoes
// =============================================================================

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
        fwrite(STDERR, "[{$name}] Nao foi possivel abrir o arquivo de lock: {$lockPath}\n");
        return 1;
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        cronWriteStatus($statusFile, $name, [
            'status'    => 'skipped_running',
            'timestamp' => date('c'),
            'message'   => 'Execuao anterior ainda em andamento — pulado para nao sobrepor.',
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
            "[%s] ERRO: nao foi possivel iniciar o processo para o job '%s' (binario: %s).\n",
            date('c'),
            $name,
            $phpBinary,
        ));

        cronWriteStatus($statusFile, $name, [
            'status'    => 'error',
            'timestamp' => date('c'),
            'message'   => 'Falha ao iniciar proc_open() com o binario: ' . $phpBinary,
        ]);

        return 1;
    }

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
            proc_terminate($process, 15);
            usleep(500_000);

            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }

            $killed = true;
            break;
        }

        usleep(200_000);
    }

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
        trim($output) !== '' ? trim($output) : '(sem saida)',
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

function cronAppendLog(string $path, string $line): void
{
    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

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
