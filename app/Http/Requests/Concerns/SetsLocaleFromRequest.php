<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

trait SetsLocaleFromRequest
{
    protected function applyRequestLocale(?string $locale = null): void
    {
        $locale ??= $this->input('locale');

        if (! in_array($locale, ['en', 'ar'], true)) {
            return;
        }

        App::setLocale($locale);
        Session::put('locale', $locale);
    }
}
