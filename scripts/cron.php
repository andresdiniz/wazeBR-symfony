<?php

declare(strict_types=1);

// Script para execucao via cron
// Linux: */5 * * * * php /path/scripts/cron.php >> var/log/cron.log 2>&1
// Windows: Task Scheduler a cada 5 minutos

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

(new Dotenv())->loadEnv(dirname(__DIR__) . '/.env');

$kernel = new \App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

$application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$application->setAutoExit(false);

date_default_timezone_set('America/Sao_Paulo');

$startTime = new DateTime();
echo sprintf("[%s] === INICIANDO COLETA WAZE ===\n", $startTime->format('Y-m-d H:i:s'));

echo "\nColetando feeds (alerts, jams, routes)...\n";
try {
    $input = new ArrayInput(['command' => 'waze:collect-feed']);
    $output = new BufferedOutput();
    $exitCode = $application->run($input, $output);
    
    echo ($exitCode === 0) ? "[OK] Coleta concluida\n" : "[ERRO] Codigo " . $exitCode . "\n";
    echo $output->fetch() . "\n";
} catch (\Throwable $e) {
    echo "[ERRO CRITICO] " . $e->getMessage() . "\n";
}

$endTime = new DateTime();
$duration = $startTime->diff($endTime);
echo sprintf("\n[%s] === COLETA CONCLUIDA (%s) ===\n", $endTime->format('Y-m-d H:i:s'), $duration->format('%i min %s seg'));

$kernel->shutdown();
exit(0);
