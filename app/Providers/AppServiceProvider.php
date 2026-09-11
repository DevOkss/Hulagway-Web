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
        // DEPLOYMENT: Production sits behind TLS-terminating Docker nginx proxy.
        // Force https so absolute asset/route/Ziggy URLs don't get blocked as mixed content.
        // Original deployment config (kept for easy re-deploy — uncomment if you want unconditional force):
        // URL::forceScheme('https');

        // Local dev runs plain http (8001/5174) — only force https in production.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
