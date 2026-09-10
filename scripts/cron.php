<?php

declare(strict_types=1);

/**
 * Script para execução via cron
 * 
 * Uso no crontab:
 * */5 * * * * cd /path/to/wazeBR-symfony && /usr/bin/php scripts/cron.php >> var/log/cron.log 2>&1
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Carrega variaveis de ambiente
(new Dotenv())->loadEnv(dirname(__DIR__) . '/.env');

// Inicializa o kernel do Symfony
$kernel = new \App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

$container = $kernel->getContainer();
$application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$application->setAutoExit(false);

// Configura timezone
date_default_timezone_set('America/Sao_Paulo');

// Log de inicio
$startTime = new DateTime();
echo sprintf(
    "[%s] === INICIANDO COLETA WAZE ===\n",
    $startTime->format('Y-m-d H:i:s')
);

// Executa comando de coleta de feeds (EVENTS + TVT)
echo "\n[1/2] Coletando feeds (alerts, jams, routes)...\n";
try {
    $input = new ArrayInput(['command' => 'waze:collect-feed']);
    $output = new BufferedOutput();
    $exitCode = $application->run($input, $output);
    
    if ($exitCode === 0) {
        echo "[OK] Coleta de feeds concluida com sucesso\n";
    } else {
        echo "[ERRO] Coleta de feeds falhou com codigo " . $exitCode . "\n";
    }
    
    echo $output->fetch() . "\n";
} catch (\Throwable $e) {
    echo "[ERRO CRITICO] " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

// Log de conclusao
$endTime = new DateTime();
$duration = $startTime->diff($endTime);
echo sprintf(
    "\n[%s] === COLETA CONCLUIDA (duracao: %s) ===\n",
    $endTime->format('Y-m-d H:i:s'),
    $duration->format('%i min %s seg')
);

$kernel->shutdown();
exit(0);
