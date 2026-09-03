<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Front controller sits behind a TLS-terminating reverse proxy (Docker nginx).
        // Force absolute asset/route/Ziggy URLs to https so the browser does not
        // block them as mixed content (white screen).
        URL::forceScheme('https');
    }
}
