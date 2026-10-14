<?php

declare(strict_types=1);

use App\Kernel;

ini_set('session.use_strict_mode', '1');

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
