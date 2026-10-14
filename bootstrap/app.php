<?php

use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\TokenAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $m) {
        $m->alias(['token' => TokenAuth::class, 'permission' => RequirePermission::class]);
    })
    ->withExceptions(function (Exceptions $e) {
        $e->shouldRenderJsonWhen(fn () => true);
    })->create();
