<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

return static function (Application $app): void {
    $app->instance('installed-runtime.scenario', 'cache-enabled');
    $app->make(Repository::class)->set('capell-html-cache.enabled', true);
    $app->make(Repository::class)->set('capell-html-cache.invalidation.mode', 'scheduled');
};
