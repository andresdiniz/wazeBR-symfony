<?php

$php = '/usr/bin/php8.2';
$project = __DIR__;
$log = $project . '/var/log/cron_scheduler.log';

$command = sprintf(
    '%s %s/bin/console messenger:consume scheduler_main --time-limit=55 --limit=10 --env=prod >> %s 2>&1',
    escapeshellarg($php),
    escapeshellarg($project),
    escapeshellarg($log)
);

exec($command, $output, $returnCode);
echo $returnCode;
