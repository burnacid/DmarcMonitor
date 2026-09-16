<?php

namespace App\Providers;

use App\Mail\Transport\Microsoft365Transport;
use App\Services\Graph\GraphTokenService;
use Illuminate\Support\Facades\Mail;
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
        Mail::extend('microsoft365', fn () => new Microsoft365Transport(app(GraphTokenService::class)));
    }
}
