<?php

namespace App\Listeners;

use App\Services\UserLoginFrequencyService;
use Illuminate\Auth\Events\Login;

class RecordSuccessfulLogin
{
    public function __construct(
        private readonly UserLoginFrequencyService $loginFrequency,
    ) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof \App\Models\User) {
            return;
        }

        $source = $event->guard === 'web' ? 'web' : (string) $event->guard;

        $this->loginFrequency->recordExplicitLogin($event->user, $source);
    }
}
