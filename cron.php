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
 *      (ex.: usando wget) em vez de rodar um binário diretamente.
 *
 * Jobs disponíveis: waze_feed, waze_routes, waze_tvt, cemaden,
 * cemaden_hydro, notify, notify_high_risk, report, all (debug).
 *
 * Detecção do binário PHP: por padrão usa a constante PHP_BINARY (o
 * mesmo interpretador que já está executando este script — sempre
 * correto, tanto em Linux quanto em Windows, sem precisar hardcodar
 * caminho nenhum). Pode ser sobrescrito com a variável de ambiente
 * CRON_PHP_BINARY, ou com um arquivo opcional `cron.local.php` na
 * mesma pasta (não versionado — ideal para overrides só do seu ambiente
 * local), que se existir é incluído e pode redefinir $phpBinary.
 */

// -----------------------------------------------------------------------------
// Configuração
// -----------------------------------------------------------------------------

$projectDir = __DIR__;
$logDir     = $projectDir . '/var/log';
$lockDir    = $projectDir . '/var/cron-locks';
$statusFile = $logDir . '/cron_status.json';

// Defesa extra: STDIN/STDOUT/STDERR só são pré-definidas pelo SAPI CLI.
// Se este script for chamado por engano sob outro SAPI (ex.: CGI, como
// pode acontecer se CRON_PHP_BINARY apontar pro binário errado no
// Windows/XAMPP), essas constantes não existem e o script quebra antes
// de conseguir logar o motivo. Definimos manualmente para nunca falhar
// silenciosamente por isso — mas o binário correto (CLI) ainda deve ser
// configurado; ver CRON_PHP_BINARY no .env.
if (!defined('STDIN')) {
    define('STDIN', fopen('php://stdin', 'r'));
}
if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'w'));
}
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

// Ordem de resolução do binário PHP: env var explícita > PHP_BINARY
// (o interpretador que já está rodando este script) > fallback 'php'
// (assume que está no PATH).
$phpBinary = getenv('CRON_PHP_BINARY');
if ($phpBinary === false || $phpBinary === '') {
    $phpBinary = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
}

// Override opcional só deste ambiente (crie um cron.local.php ao lado
// deste arquivo, NÃO versionado, com algo como:
//   <?php $phpBinary = 'C:\\php\\php.exe';
// útil se PHP_BINARY não bater com o binário certo no seu ambiente).
$localOverride = $projectDir . '/cron.local.php';
if (is_file($localOverride)) {
    require $localOverride;
}

/** Tamanho máximo (bytes) de cada log antes de rotacionar. */
const CRON_MAX_LOG_BYTES = 2 * 1024 * 1024; // 2MB

/**
 * Mapa de jobs → comando Symfony + timeout (segundos). Mantenha esta
 * lista sincronizada com CronController::ALLOWED_JOBS.
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
            "[%s] ERRO: não foi possível iniciar o processo para o job '%s' (binário: %s).\n",
            date('c'),
            $name,
            $phpBinary,
        ));

        cronWriteStatus($statusFile, $name, [
            'status'    => 'error',
            'timestamp' => date('c'),
            'message'   => 'Falha ao iniciar proc_open() com o binário: ' . $phpBinary,
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
