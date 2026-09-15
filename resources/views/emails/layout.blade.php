@php
    /*
    | Every style in these emails is INLINE. That is a correctness
    | requirement, not a preference — see App\Support\EmailStyles for
    | what happens when it is not, and why the symptom looks like one
    | misplaced line rather than a missing stylesheet.
    |
    | $s is shared into every emails.* view by AppServiceProvider
    | rather than built here, because Blade renders a @section BEFORE
    | the layout that wraps it: a variable declared in this file would
    | not be visible to the content templates, and two copies of a
    | design drift apart.
    |
    | Printed with {!! !!} rather than {{ }}: these are hardcoded CSS
    | constants from EmailStyles, never user data, and escaping them
    | turns the quotes in a font stack into &#039; inside the CSS.
    */
    $lang = $locale ?? app()->getLocale();

    /*
    | Built from mail.asset_url, NOT url(). An <img> in an email is
    | fetched by the recipient's mail client from wherever they are,
    | so the host has to be reachable from the public internet —
    | which a development APP_URL (maliyat-docs.test) is not. Getting
    | that wrong shows a broken image in every email the product
    | sends, and nothing reports it, because the mail itself goes out
    | fine.
    */
    $logoUrl = rtrim((string) config('mail.asset_url'), '/').'/images/logo-icon-light.png';
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{!! $s['dir'] !!}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $subject ?? config('app.name') }}</title>

    {{-- A bonus for clients that keep it, never the only copy.
         Everything here is already applied inline; this block exists
         for the one thing an inline style cannot express — the phone
         breakpoint. If a client strips it the email still looks
         right, which is the whole point. --}}
    <style>
        @media only screen and (max-width: 600px) {
            .m-body   { padding: 24px 20px !important; }
            .m-header { padding: 24px 20px !important; }
            .m-code   { font-size: 26px !important; letter-spacing: 0.2em !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; width:100% !important; background-color:#F4F6F9; -webkit-text-size-adjust:100%;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="{!! $s['wrapper'] !!}">
    <tr>
        <td align="center" style="padding:0;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="{!! $s['container'] !!}">
                <tr>
                    <td class="m-header" style="{!! $s['header'] !!}">
                        {{-- alt is empty and the name is real text
                             below: a blocked image then leaves a
                             clean header rather than a broken-image
                             icon sitting next to the brand name. --}}
                        <img src="{{ $logoUrl }}" alt="" width="52" height="52" style="{!! $s['logo'] !!}">
                        <p style="{!! $s['brand'] !!}">{{ $lang === 'ar' ? 'ماليات دوكس' : 'Maliyat Docs' }}</p>
                    </td>
                </tr>
                <tr><td style="{!! $s['accent'] !!}">&nbsp;</td></tr>
                <tr>
                    <td class="m-body" style="{!! $s['body'] !!}">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="{!! $s['footer'] !!}">
                        <p style="{!! $s['footerP'] !!}">© {{ date('Y') }} {{ config('app.name') }} · {{ __('emails.footer_tagline', [], $lang) }}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
