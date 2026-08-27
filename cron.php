<?php

declare(strict_types=1);

use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\HttpFoundation\Request;

// Define project root explicitly
$projectRoot = dirname(__DIR__);

require $projectRoot.'/config/bootstrap.php';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
    Debug::enable();
}

if (!defined('STDIN')) {
    define('STDIN', fopen('php://stdin', 'r'));
}

if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'w'));
}

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}

if ($app = require $projectRoot.'/config/app.php') {
    $request = Request::createFromGlobals();
    $app->handle($request)->send();
    exit(0);
}

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);

// Add global error handler to catch all errors
set_error_handler(function($severity, $message, $file, $line) {
    fwrite(STDERR, "\n[ERROR] $message in $file on line $line\n");
    fwrite(STDERR, "Stack trace:\n" . debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) . "\n");
    return false; // Let PHP also handle it
});

set_exception_handler(function($exception) use ($kernel) {
    fwrite(STDERR, "\n[EXCEPTION] " . get_class($exception) . ": " . $exception->getMessage() . "\n");
    fwrite(STDERR, "File: " . $exception->getFile() . ":" . $exception->getLine() . "\n");
    fwrite(STDERR, "Stack trace:\n" . $exception->getTraceAsString() . "\n");
    
    // Also log to Symfony logger if available
    try {
        $container = $kernel->getContainer();
        if ($container->has('logger')) {
            $logger = $container->get('logger');
            $logger->critical('Cron error: ' . $exception->getMessage(), [
                'exception' => $exception,
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ]);
        }
    } catch (Throwable $e) {
        // Container not available yet
    }
});

fwrite(STDOUT, "\n=== CRON START ===\n");
fwrite(STDOUT, "Time: " . date('Y-m-d H:i:s') . "\n");
fwrite(STDOUT, "Job: " . ($argv[1] ?? 'none') . "\n");
fwrite(STDOUT, "Project Root: $projectRoot\n");
fwrite(STDOUT, "PHP Version: " . PHP_VERSION . "\n");
fwrite(STDOUT, "Memory Limit: " . ini_get('memory_limit') . "\n");
fwrite(STDOUT, "==================\n\n");

try {
    $kernel->boot();
    $application = new Application($kernel);
    $application->setAutoExit(false);

    $input = new ArrayInput($argv ?? ['console']);
    $output = new ConsoleOutput(STDOUT, ConsoleOutput::VERBOSITY_NORMAL);

    fwrite(STDOUT, "[INFO] Running command: " . implode(' ', $argv) . "\n");

    $exitCode = $application->run($input, $output);

    fwrite(STDOUT, "\n[INFO] Command finished with exit code: $exitCode\n");

    if ($exitCode !== 0) {
        fwrite(STDERR, "[ERROR] Command failed with exit code: $exitCode\n");
    }

    exit($exitCode);
} catch (Throwable $e) {
    fwrite(STDERR, "\n[FATAL ERROR] " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    fwrite(STDERR, "Stack trace:\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    fwrite(STDOUT, "\n=== CRON END ===\n");
    
    if (defined('STDIN') && is_resource(STDIN)) {
        fclose(STDIN);
    }
    if (defined('STDOUT') && is_resource(STDOUT)) {
        fclose(STDOUT);
    }
    if (defined('STDERR') && is_resource(STDERR)) {
        fclose(STDERR);
    }
}
