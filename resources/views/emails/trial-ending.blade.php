@extends('emails.layout')

@section('content')
@php $lang = $locale ?? app()->getLocale(); @endphp

<h1 style="{!! $s['h1'] !!}">{{ __('emails.trial_ending.heading', [], $lang) }}</h1>

<p style="{!! $s['p'] !!}">{{ __('emails.trial_ending.greeting', ['name' => $user->name], $lang) }}</p>

<p style="{!! $s['p'] !!}">{{ __('emails.trial_ending.intro', ['company' => $company->name, 'days' => $daysLeft], $lang) }}</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="{!! $s['codeBox'] !!}">
            {{-- A date, so it reads left-to-right in both languages. --}}
            <span class="m-code" dir="ltr" style="{!! $s['date'] !!}">{{ $endsOn }}</span>
        </td>
    </tr>
</table>

{{-- The caption for the date above it, so it is centred with the box
     rather than aligned to the body text. --}}
<p style="{!! $s['caption'] !!}">
    {{ __('emails.trial_ending.ends_on_label', [], $lang) }}
</p>

<p style="{!! $s['p'] !!}">{{ __('emails.trial_ending.what_happens', [], $lang) }}</p>

<p style="{!! $s['p'] !!}">{{ __('emails.trial_ending.how_to_renew', [], $lang) }}</p>

<p style="{!! $s['p'] !!}">{{ __('emails.trial_ending.closing', [], $lang) }}</p>
@endsection
