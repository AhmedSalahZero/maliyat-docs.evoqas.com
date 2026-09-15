{{--
    Plain-text twin of the HTML verification email.

    Not decoration: a message with no text/plain part is a documented
    spam signal, and filters weigh HTML-only mail more suspiciously
    than a proper multipart message. It is also what a screen reader,
    a smartwatch and a plain-text mail client actually read.

    Every fact here matches the HTML version — same code, same
    expiry, same instruction — because a text part that disagrees
    with the HTML part is worse than none at all.
--}}
@php $lang = $locale ?? app()->getLocale(); @endphp
{{ __('emails.verify_code.heading', [], $lang) }}

{{ __('emails.verify_code.greeting', ['name' => $user->name], $lang) }}

{{ __('emails.verify_code.intro', [], $lang) }}

    {{ $code }}

{{ __('emails.verify_code.expire', ['count' => $expiresMinutes], $lang) }}

{{ __('emails.verify_code.instruction', [], $lang) }}

{{ __('emails.verify_code.ignore', [], $lang) }}

{{ __('emails.verify_code.closing', [], $lang) }}

--
{{ config('app.name') }} · {{ __('emails.footer_tagline', [], $lang) }}
