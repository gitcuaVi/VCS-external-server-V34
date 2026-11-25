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
        // Sau reverse proxy HTTPS, request tới app là http. Nếu proxy không gửi
        // X-Forwarded-Proto thì link/asset sinh ra là http:// và bị trình duyệt
        // chặn (Mixed Content). APP_URL là https thì luôn sinh link https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
