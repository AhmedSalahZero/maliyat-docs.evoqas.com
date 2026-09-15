@extends('emails.layout')

@section('content')
@php $lang = $locale ?? app()->getLocale(); @endphp

<h1>{{ __('emails.trial_ending.heading', [], $lang) }}</h1>

<p>{{ __('emails.trial_ending.greeting', ['name' => $user->name], $lang) }}</p>

<p>{{ __('emails.trial_ending.intro', ['company' => $company->name, 'days' => $daysLeft], $lang) }}</p>

<div class="code-box">
    <span class="code">{{ $endsOn }}</span>
</div>

<p class="muted" style="text-align: center;">
    {{ __('emails.trial_ending.ends_on_label', [], $lang) }}
</p>

<p>{{ __('emails.trial_ending.what_happens', [], $lang) }}</p>

<p>{{ __('emails.trial_ending.how_to_renew', [], $lang) }}</p>

<p>{{ __('emails.trial_ending.closing', [], $lang) }}</p>
@endsection
