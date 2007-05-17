<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/screenshot-fixtures/html-cache/maintenance', static function (): never {
    abort_unless(app()->environment(['local', 'testing']), 403);
    abort(503);
});
