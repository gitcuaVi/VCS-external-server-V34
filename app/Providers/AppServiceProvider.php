<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->configureApiRateLimits();
    }

    /** Per-IP request limits for the machine APIs (see config freshdesk.api_rate_limits). */
    private function configureApiRateLimits(): void
    {
        foreach (['webhooks', 'tickets', 'admin'] as $name) {
            RateLimiter::for("api-{$name}", static function (Request $request) use ($name): Limit {
                $perMinute = max(1, (int) config("freshdesk.api_rate_limits.{$name}", 60));

                return Limit::perMinute($perMinute)->by("api-{$name}:".$request->ip());
            });
        }
    }
}
