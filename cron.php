<?php

/**
 * cron.php — Fallback de coleta para hospedagem compartilhada (Hostinger PHP 8.5)
 *
 * USO: Configure o Agendador de Tarefas da Hostinger para chamar este arquivo
 * diretamente via CLI (não via HTTP) a cada 5 minutos:
 *
 *   /usr/local/bin/php8.5 /home/uXXXXXXXXX/domains/seudominio.com.br/public_html/trafik/cron.php
 *
 * QUANDO USAR:
 *   - Se o Supervisor estiver configurado e rodando: NÃO use este arquivo.
 *     Os workers já estão vivos 24/7 pelo Supervisor.
 *   - Se a Hostinger não permitir Supervisor (hospedagem compartilhada):
 *     Use este arquivo como fallback de consume pontual.
 *
 * FUNCIONAMENTO:
 *   Dispara consume dos transportes async_waze e async_cemaden com
 *   --time-limit=55 (seguro para cron de 1 minuto) e --limit=5 por transport.
 *   Cada transport roda em processo separado em background (&) para paralelismo.
 */

$php     = '/usr/local/bin/php8.5';
$project = __DIR__;
$logDir  = $project . '/var/log';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$transports = [
    'async_waze'    => $logDir . '/cron_worker_waze.log',
    'async_cemaden' => $logDir . '/cron_worker_cemaden.log',
];

foreach ($transports as $transport => $log) {
    $cmd = sprintf(
        '%s %s/bin/console messenger:consume %s --time-limit=55 --limit=5 --env=prod >> %s 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($project),
        escapeshellarg($transport),
        escapeshellarg($log)
    );
    exec($cmd);
}

echo 'dispatched: ' . implode(', ', array_keys($transports)) . PHP_EOL;
