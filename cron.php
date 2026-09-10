<?php

declare(strict_types=1);

/**
 * cron.php — Dispatcher de coleta para hospedagem compartilhada (Hostinger)
 */

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
    'waze_collect_all' => [
        'cmd'     => ['waze:collect-feed'],
        'timeout' => 90,
        'desc'    => 'Coleta completa Waze: alerts, jams E routes (EVENTS + TVT)',
    ],
    'cemaden' => [
        'cmd'     => ['cemaden:collect'],
        'timeout' => 50,
        'desc'    => 'Dados pluviometricos CEMADEN',
    ],
    'cemaden_hydro' => [
        'cmd'     => ['cemaden:collect-hydro'],
        'timeout' => 60,
        'desc'    => 'Niveis de rios CEMADEN',
    ],
    'notify' => [
        'cmd'     => ['notifications:dispatch'],
        'timeout' => 40,
        'desc'    => 'Notificaoes de alertas criticos',
    ],
    'report' => [
        'cmd'     => ['waze:report:daily'],
        'timeout' => 90,
        'desc'    => 'Relatorio diario por e-mail',
    ],
];

$job = $argv[1] ?? null;

if ($job === null) {
    fwrite(STDERR, "Uso: php cron.php <job>\n\nJobs disponiveis:\n");
    foreach ($jobs as $name => $def) {
        fwrite(STDERR, sprintf("  %-18s %s\n", $name, $def['desc']));
    }
    fwrite(STDERR, "  all               Roda todos os jobs\n");
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
        fwrite(STDERR, "[{$name}] Nao foi possivel abrir o lock: {$lockPath}\n");
        return 1;
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        cronWriteStatus($statusFile, $name, [
            'status'    => 'skipped_running',
            'timestamp' => date('c'),
            'message'   => 'Execuao anterior em andamento — pulado.',
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
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($consoleCmd, $descriptorSpec, $pipes, $projectDir);

    if (!is_resource($process)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        cronAppendLog($logFile, sprintf("[%s] ERRO: falha ao iniciar processo '%s'\n", date('c'), $name));
        cronWriteStatus($statusFile, $name, ['status' => 'error', 'timestamp' => date('c'), 'message' => 'Falha no proc_open']);
        return 1;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $timeout = $def['timeout'];
    $killed = false;

    while (true) {
        $status = proc_get_status($process);
        $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (!$status['running']) break;
        if ((microtime(true) - $startedAt) > $timeout) {
            proc_terminate($process, 15);
            usleep(500_000);
            if (proc_get_status($process)['running']) proc_terminate($process, 9);
            $killed = true;
            break;
        }
        usleep(200_000);
    }

    $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = $killed ? 124 : proc_close($process);
    $durationSec = round(microtime(true) - $startedAt, 2);

    cronAppendLog($logFile, sprintf("[%s] job=%s exit=%d duration=%ss%s\n%s\n", date('c'), $name, $exitCode, $durationSec, $killed ? ' KILLED' : '', trim($output)));
    cronWriteStatus($statusFile, $name, ['status' => $killed ? 'timeout' : ($exitCode === 0 ? 'ok' : 'error'), 'exit_code' => $exitCode, 'duration_s' => $durationSec, 'timestamp' => date('c')]);

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    return $killed ? 1 : ($exitCode === 0 ? 0 : 1);
}

function cronAppendLog(string $path, string $line): void { file_put_contents($path, $line, FILE_APPEND | LOCK_EX); }
function cronRotateLogIfNeeded(string $path, int $maxBytes): void { if (is_file($path) && filesize($path) > $maxBytes) { $rotated = $path . '.1'; if (is_file($rotated)) unlink($rotated); rename($path, $rotated); } }
function cronWriteStatus(string $statusFile, string $job, array $data): void {
    $fp = fopen($statusFile, 'c+');
    if ($fp === false) return;
    if (flock($fp, LOCK_EX)) {
        $contents = stream_get_contents($fp);
        $all = json_decode($contents ?: '', true);
        if (!is_array($all)) $all = [];
        $all[$job] = $data;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}
