<?php

declare(strict_types=1);

/**
 * Dispatcher de coleta para hospedagem compartilhada e desenvolvimento local.
 */

$projectDir = __DIR__;
$logDir = $projectDir . '/var/log';
$lockDir = $projectDir . '/var/cron-locks';
$statusFile = $logDir . '/cron_status.json';
$isWindows = PHP_OS_FAMILY === 'Windows';

$jobs = [
    'waze_feed' => ['cmd' => ['app:waze:collect-feed'], 'timeout' => 50, 'desc' => 'Alertas e congestionamentos Waze'],
    'waze_routes' => ['cmd' => ['app:waze:collect-routes'], 'timeout' => 50, 'desc' => 'Tempos de rota e irregularidades Waze'],
    'waze_tvt' => ['cmd' => ['app:waze:collect-tvt'], 'timeout' => 50, 'desc' => 'Snapshots de rotas TVT'],
    'cemaden' => ['cmd' => ['cemaden:collect'], 'timeout' => 50, 'desc' => 'Dados pluviométricos CEMADEN'],
    'cemaden_hydro' => ['cmd' => ['cemaden:collect-hydro'], 'timeout' => 60, 'desc' => 'Níveis de rios CEMADEN'],
    'notify' => ['cmd' => ['notifications:dispatch'], 'timeout' => 40, 'desc' => 'Notificações'],
    'notify_high_risk' => ['cmd' => ['waze:notify:high-risk'], 'timeout' => 40, 'desc' => 'Notificações legadas de alto risco'],
    'report' => ['cmd' => ['waze:report:daily'], 'timeout' => 90, 'desc' => 'Relatório diário'],
];

$job = $argv[1] ?? null;

if ($job === null) {
    fwrite(STDERR, "Uso: php cron.php <job>\n\nJobs disponíveis:\n");
    foreach ($jobs as $name => $def) {
        fwrite(STDERR, sprintf("  %-18s %s\n", $name, $def['desc']));
    }
    fwrite(STDERR, "  all               Roda todos os jobs em sequência\n");
    exit(1);
}

if (!isset($jobs[$job]) && $job !== 'all') {
    fwrite(STDERR, "Job desconhecido: {$job}\n");
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

$phpBinary = cronResolvePhpBinary($isWindows);
if ($phpBinary === null) {
    $message = 'Binário PHP não encontrado. Configure CRON_PHP_BINARY com o caminho completo do php.exe.';
    fwrite(STDERR, $message . PHP_EOL);
    cronAppendLog($logDir . '/cron_dispatcher.log', '[' . date('c') . '] ERROR: ' . $message . PHP_EOL);
    exit(1);
}

if ($job === 'all') {
    $overallExit = 0;
    foreach (array_keys($jobs) as $name) {
        $overallExit |= cronRunJob($name, $jobs[$name], $phpBinary, $projectDir, $logDir, $lockDir, $statusFile, $isWindows);
    }
    exit($overallExit === 0 ? 0 : 1);
}

exit(cronRunJob($job, $jobs[$job], $phpBinary, $projectDir, $logDir, $lockDir, $statusFile, $isWindows));

function cronResolvePhpBinary(bool $isWindows): ?string
{
    $configured = $_ENV['CRON_PHP_BINARY'] ?? $_SERVER['CRON_PHP_BINARY'] ?? getenv('CRON_PHP_BINARY');
    $configured = is_string($configured) ? trim($configured, " \t\n\r\0\x0B\"") : '';

    $candidates = [];
    if ($configured !== '') {
        $candidates[] = $configured;
    }
    if ($isWindows) {
        $candidates[] = PHP_BINARY;
    } else {
        $candidates[] = '/usr/local/bin/php8.5';
        $candidates[] = PHP_BINARY;
    }

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || $candidate === '') {
            continue;
        }
        $candidate = trim($candidate, " \t\n\r\0\x0B\"");
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function cronRunJob(
    string $name,
    array $def,
    string $phpBinary,
    string $projectDir,
    string $logDir,
    string $lockDir,
    string $statusFile,
    bool $isWindows,
): int {
    $lockPath = $lockDir . '/' . $name . '.lock';
    $lockHandle = fopen($lockPath, 'c');
    if ($lockHandle === false) {
        fwrite(STDERR, "[{$name}] Não foi possível abrir o lock: {$lockPath}\n");
        return 1;
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        cronWriteStatus($statusFile, $name, [
            'status' => 'skipped_running',
            'timestamp' => date('c'),
            'message' => 'Execução anterior ainda em andamento.',
        ]);
        fclose($lockHandle);
        return 0;
    }

    $logFile = $logDir . '/cron_' . $name . '.log';
    $startedAt = microtime(true);
    $console = $projectDir . '/bin/console';
    $arguments = array_merge([$phpBinary, $console], $def['cmd'], ['--env=prod', '--no-interaction']);

    if (!is_file($console)) {
        $message = "Arquivo bin/console não encontrado: {$console}";
        cronAppendLog($logFile, '[' . date('c') . '] ERROR: ' . $message . PHP_EOL);
        cronWriteStatus($statusFile, $name, ['status' => 'error', 'timestamp' => date('c'), 'message' => $message]);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        return 1;
    }

    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($isWindows ? cronBuildWindowsCommand($arguments) : $arguments, $descriptorSpec, $pipes, $projectDir);

    if (!is_resource($process)) {
        $message = 'Falha ao iniciar proc_open(). Binário: ' . $phpBinary . ' | Comando: ' . cronFormatCommand($arguments);
        cronAppendLog($logFile, '[' . date('c') . '] ERROR: ' . $message . PHP_EOL);
        cronWriteStatus($statusFile, $name, ['status' => 'error', 'timestamp' => date('c'), 'message' => $message]);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        return 1;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $timedOut = false;

    while (true) {
        $processStatus = proc_get_status($process);
        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);
        if (!$processStatus['running']) {
            break;
        }
        if ((microtime(true) - $startedAt) > $def['timeout']) {
            proc_terminate($process, 15);
            usleep(500000);
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            $timedOut = true;
            break;
        }
        usleep(200000);
    }

    $output .= (string) stream_get_contents($pipes[1]);
    $output .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = $timedOut ? 124 : proc_close($process);
    $duration = round(microtime(true) - $startedAt, 2);
    cronAppendLog($logFile, sprintf("[%s] job=%s exit=%d duration=%ss%s\n%s\n", date('c'), $name, $exitCode, $duration, $timedOut ? ' KILLED_TIMEOUT' : '', trim($output) ?: '(sem saída)'));
    cronWriteStatus($statusFile, $name, ['status' => $timedOut ? 'timeout' : ($exitCode === 0 ? 'ok' : 'error'), 'exit_code' => $exitCode, 'duration_s' => $duration, 'timestamp' => date('c'), 'timed_out' => $timedOut]);

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    return $timedOut || $exitCode !== 0 ? 1 : 0;
}

function cronBuildWindowsCommand(array $arguments): string
{
    return implode(' ', array_map(static fn(string $value): string => escapeshellarg($value), $arguments));
}

function cronFormatCommand(array $arguments): string
{
    return implode(' ', array_map(static fn(string $value): string => '"' . $value . '"', $arguments));
}

function cronAppendLog(string $path, string $line): void
{
    file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function cronWriteStatus(string $statusFile, string $job, array $data): void
{
    $fp = fopen($statusFile, 'c+');
    if ($fp === false) {
        return;
    }
    if (flock($fp, LOCK_EX)) {
        rewind($fp);
        $contents = stream_get_contents($fp);
        $all = json_decode($contents ?: '', true);
        if (!is_array($all)) {
            $all = [];
        }
        $all[$job] = $data;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}
