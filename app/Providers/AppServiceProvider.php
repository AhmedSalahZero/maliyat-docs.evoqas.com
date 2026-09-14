<?php

namespace App\Providers;

use App\Listeners\RecordSuccessfulLogin;
use App\Services\UserLoginFrequencyService;
use App\Support\PasswordRules;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UserLoginFrequencyService::class);
    }

    public function boot(): void
    {
        Event::listen(Login::class, RecordSuccessfulLogin::class);

        Vite::prefetch(concurrency: 3);

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => PasswordRules::defaults());
    }
}