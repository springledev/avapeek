<?php

namespace App\Providers;

use App\Contracts\InviteDeliveryServiceInterface;
use App\Contracts\RegistrationServiceInterface;
use App\Services\DefaultRegistrationService;
use App\Services\InviteDeliveryService;
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
        $this->app->singleton(
            InviteDeliveryServiceInterface::class,
            InviteDeliveryService::class,
        );

        $this->app->singleton(
            RegistrationServiceInterface::class,
            DefaultRegistrationService::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)->by('login:'.$request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(3)->by('register:'.$request->ip()));

        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(10)->by('mfa:'.$request->ip()));

        RateLimiter::for('password-confirm', fn (Request $request) => Limit::perMinute(10)->by('password-confirm:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('upload', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('upload-intent', fn (Request $request) => Limit::perMinute(60)->by('upload-intent:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('invite', fn (Request $request) => Limit::perHour(10)->by($request->user()?->id ?: $request->ip()));
    }
}
