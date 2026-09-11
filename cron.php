<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$console = __DIR__ . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'console';

if (!is_file($console)) {
    fwrite(STDERR, sprintf("Symfony console not found: %s%s", $console, PHP_EOL));
    exit(1);
}

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($console);

$arguments = array_slice($_SERVER['argv'] ?? [], 1);
if ($arguments === []) {
    $arguments = ['list'];
}

foreach ($arguments as $argument) {
    $command .= ' ' . escapeshellarg((string) $argument);
}

passthru($command, $exitCode);
exit($exitCode);
