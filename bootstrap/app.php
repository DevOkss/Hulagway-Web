<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        // Trust the labsync Docker nginx reverse proxy (and localhost) so Laravel
        // honours X-Forwarded-Proto (https) / X-Forwarded-For and generates correct
        // absolute URLs + client IPs. The Docker network uses 172.18.0.0/16.
        $middleware->trustProxies(at: ['172.18.0.0/16', '127.0.0.1']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
