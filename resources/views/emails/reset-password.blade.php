@extends('emails.layout')

@section('content')
@php $lang = $locale ?? app()->getLocale(); @endphp

<h1>{{ __('emails.reset_password.heading', [], $lang) }}</h1>

<p>{{ __('emails.reset_password.greeting', ['name' => $user->name], $lang) }}</p>

<p>{{ __('emails.reset_password.intro', [], $lang) }}</p>

<div class="btn-wrap">
    <a href="{{ $url }}" class="btn">{{ __('emails.reset_password.button', [], $lang) }}</a>
</div>

<p class="muted">{{ __('emails.reset_password.expire', ['count' => $expireMinutes], $lang) }}</p>

<p class="muted">{{ __('emails.reset_password.fallback', [], $lang) }}</p>
<p class="fallback-link"><a href="{{ $url }}">{{ $url }}</a></p>

<p class="muted">{{ __('emails.reset_password.ignore', [], $lang) }}</p>

<p>{{ __('emails.reset_password.closing', [], $lang) }}</p>
@endsection
