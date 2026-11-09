<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('news-analysis', function (Request $request) {
            $response = fn (Request $request, array $headers) => response()->view('welcome', [
                'error' => 'Se alcanzó el límite de análisis. Esperá un momento y volvé a intentarlo.',
            ], 429, $headers);

            return [
                Limit::perMinute(3)->by('ip:'.$request->ip())->response($response),
                Limit::perMinute(12)->by('global:minute')->response($response),
                Limit::perDay(100)->by('global:day')->response($response),
            ];
        });
    }
}
