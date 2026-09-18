<?php

namespace App\Providers;

use App\Mail\Transport\Microsoft365Transport;
use App\Services\Graph\GraphTokenService;
use App\Support\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
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

        Event::listen(Login::class, function (Login $event): void {
            AuditLogger::record(
                action: 'auth.login',
                description: "{$event->user->name} logged in",
                userId: $event->user->id,
            );
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user === null) {
                return;
            }

            AuditLogger::record(
                action: 'auth.logout',
                description: "{$event->user->name} logged out",
                userId: $event->user->id,
            );
        });

        Event::listen(Failed::class, function (Failed $event): void {
            $email = $event->credentials['email'] ?? null;

            AuditLogger::record(
                action: 'auth.failed',
                description: $event->user
                    ? "{$event->user->name} failed to log in"
                    : 'Failed login attempt for '.($email ?? 'unknown user'),
                userId: $event->user?->id,
                context: ['email' => $email],
            );
        });
    }
}
