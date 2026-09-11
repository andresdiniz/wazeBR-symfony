<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

$projectDir = __DIR__;

// Symfony loads .env, .env.local and the environment-specific files.
if (class_exists(Dotenv::class)) {
    (new Dotenv())->bootEnv($projectDir . '/.env');
}

$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
$debugValue = $_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? '0';
$debug = filter_var($debugValue, FILTER_VALIDATE_BOOL);

$kernel = new Kernel($environment, $debug);
$application = new Application($kernel);
$application->setAutoExit(true);

exit($application->run());
