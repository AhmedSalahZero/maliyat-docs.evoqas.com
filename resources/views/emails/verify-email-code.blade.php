@extends('emails.layout')

@section('content')
@php $lang = $locale ?? app()->getLocale(); @endphp

<h1 style="{!! $s['h1'] !!}">{{ __('emails.verify_code.heading', [], $lang) }}</h1>

<p style="{!! $s['p'] !!}">{{ __('emails.verify_code.greeting', ['name' => $user->name], $lang) }}</p>

<p style="{!! $s['p'] !!}">{{ __('emails.verify_code.intro', [], $lang) }}</p>

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="{!! $s['codeBox'] !!}">
            {{-- The code reads left-to-right in both languages: it is
                 a number, and Arabic does not reverse digits. --}}
            <span class="m-code" dir="ltr" style="{!! $s['code'] !!}">{{ $code }}</span>
        </td>
    </tr>
</table>

{{-- Centred under the code box it belongs to, not floating on its
     own — this was the one line carrying an inline style back when
     everything else relied on a <style> block the mail client threw
     away, which is why it looked like the odd one out. --}}
<p style="{!! $s['caption'] !!}">
    {{ __('emails.verify_code.expire', ['count' => $expiresMinutes], $lang) }}
</p>

<p style="{!! $s['p'] !!}">{{ __('emails.verify_code.instruction', [], $lang) }}</p>

<p style="{!! $s['muted'] !!}">{{ __('emails.verify_code.ignore', [], $lang) }}</p>

<p style="{!! $s['p'] !!}">{{ __('emails.verify_code.closing', [], $lang) }}</p>
@endsection
