<?php

namespace App\Providers;

use App\Listeners\RecordSuccessfulLogin;
use App\Services\UserLoginFrequencyService;
use App\Support\EmailStyles;
use App\Support\PasswordRules;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
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

        // Every email view gets the inline style map as $s.
        //
        // Shared rather than defined in the layout because Blade
        // renders a @section BEFORE the layout that wraps it, so a
        // variable declared in the layout is not visible to the
        // content template — they would each need their own copy,
        // and two copies of a design drift. See EmailStyles for why
        // the styles have to be inline at all.
        View::composer('emails.*', function ($view) {
            $locale = $view->getData()['locale'] ?? app()->getLocale();

            $view->with('s', EmailStyles::for($locale));
        });
    }
}