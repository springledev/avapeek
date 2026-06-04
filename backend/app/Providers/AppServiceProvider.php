<?php

namespace App\Providers;

use App\Contracts\InviteDeliveryServiceInterface;
use App\Services\InviteDeliveryService;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
