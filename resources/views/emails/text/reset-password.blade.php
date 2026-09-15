{{-- Plain-text twin — see text/verify-email-code.blade.php for why. --}}
@php $lang = $locale ?? app()->getLocale(); @endphp
{{ __('emails.reset_password.heading', [], $lang) }}

{{ __('emails.reset_password.greeting', ['name' => $user->name], $lang) }}

{{ __('emails.reset_password.intro', [], $lang) }}

{{ $url }}

{{ __('emails.reset_password.expire', ['count' => $expireMinutes], $lang) }}

{{ __('emails.reset_password.ignore', [], $lang) }}

{{ __('emails.reset_password.closing', [], $lang) }}

--
{{ config('app.name') }} · {{ __('emails.footer_tagline', [], $lang) }}
