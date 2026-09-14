@extends('emails.layout')

@section('content')
@php $lang = $locale ?? app()->getLocale(); @endphp

<h1>{{ __('emails.verify_code.heading', [], $lang) }}</h1>

<p>{{ __('emails.verify_code.greeting', ['name' => $user->name], $lang) }}</p>

<p>{{ __('emails.verify_code.intro', [], $lang) }}</p>

<div class="code-box">
    <span class="code">{{ $code }}</span>
</div>

<p class="muted" style="text-align: center;">
    {{ __('emails.verify_code.expire', ['count' => $expiresMinutes], $lang) }}
</p>

<p>{{ __('emails.verify_code.instruction', [], $lang) }}</p>

<p class="muted">{{ __('emails.verify_code.ignore', [], $lang) }}</p>

<p>{{ __('emails.verify_code.closing', [], $lang) }}</p>
@endsection
