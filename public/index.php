<?php

// ⚠ BLINDAGEM: toda a stack HTTP roda em UTC, independente do php.ini do servidor.
// Sem isso, o Hostinger (php.ini com date.timezone=Europe/Berlin) faria com que
// toda requisição HTTP gravasse/lesse datas 2h à frente do UTC real.
date_default_timezone_set('UTC');

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
