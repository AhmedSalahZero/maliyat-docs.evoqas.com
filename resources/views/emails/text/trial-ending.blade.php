{{-- Plain-text twin — see text/verify-email-code.blade.php for why. --}}
@php $lang = $locale ?? app()->getLocale(); @endphp
{{ __('emails.trial_ending.heading', [], $lang) }}

{{ __('emails.trial_ending.greeting', ['name' => $user->name], $lang) }}

{{ __('emails.trial_ending.intro', ['company' => $company->name, 'days' => $daysLeft], $lang) }}

{{ __('emails.trial_ending.ends_on_label', [], $lang) }} {{ $endsOn }}

{{ __('emails.trial_ending.what_happens', [], $lang) }}

{{ __('emails.trial_ending.how_to_renew', [], $lang) }}

{{ __('emails.trial_ending.closing', [], $lang) }}

--
{{ config('app.name') }} · {{ __('emails.footer_tagline', [], $lang) }}
