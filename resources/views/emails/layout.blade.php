@php
    $dir = ($locale ?? app()->getLocale()) === 'ar' ? 'rtl' : 'ltr';
    $lang = $locale ?? app()->getLocale();
    $logoUrl = url('/images/logo-dark.png');
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $subject ?? config('app.name') }}</title>
    <style>
        body, table, td, p { margin: 0; padding: 0; }
        body {
            width: 100% !important;
            background-color: #F4F6F9;
            font-family: {{ $dir === 'rtl' ? "'Cairo', 'Segoe UI', Tahoma, sans-serif" : "'Inter', 'Segoe UI', Helvetica, Arial, sans-serif" }};
            font-size: 15px;
            line-height: 1.6;
            color: #0F2044;
            -webkit-text-size-adjust: 100%;
        }
        .wrapper { width: 100%; background-color: #F4F6F9; padding: 32px 16px; }
        .container {
            max-width: 560px;
            margin: 0 auto;
            background: #FFFFFF;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid #DDE2EB;
            box-shadow: 0 4px 24px rgba(15, 32, 68, 0.08);
        }
        .header {
            background: #0F2044;
            padding: 28px 32px;
            text-align: center;
        }
        .header img { height: 48px; width: auto; max-width: 200px; }
        .accent-bar { height: 4px; background: #1D9E75; }
        .body { padding: 32px; }
        .body h1 {
            font-size: 20px;
            font-weight: 700;
            color: #0F2044;
            margin: 0 0 12px;
            letter-spacing: -0.02em;
        }
        .body p { margin: 0 0 16px; color: #4A5568; }
        .btn-wrap { text-align: center; margin: 28px 0; }
        .btn {
            display: inline-block;
            background: #1D9E75;
            color: #FFFFFF !important;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            padding: 14px 32px;
            border-radius: 10px;
            box-shadow: 0 4px 14px rgba(29, 158, 117, 0.35);
        }
        .code-box {
            text-align: center;
            margin: 24px 0;
            padding: 20px;
            background: #E1F5EE;
            border: 2px dashed #1D9E75;
            border-radius: 12px;
        }
        .code-box .code {
            font-size: 32px;
            font-weight: 800;
            letter-spacing: 0.35em;
            color: #0F2044;
            font-family: 'Courier New', Courier, monospace;
        }
        .muted { font-size: 13px; color: #8A96A8; }
        .fallback-link { word-break: break-all; color: #1D9E75; font-size: 13px; }
        .footer {
            padding: 20px 32px 28px;
            text-align: center;
            border-top: 1px solid #EEF1F6;
            background: #FAFBFC;
        }
        .footer p { font-size: 12px; color: #8A96A8; margin: 0; }
        @media only screen and (max-width: 600px) {
            .body { padding: 24px 20px; }
            .header { padding: 24px 20px; }
            .code-box .code { font-size: 26px; letter-spacing: 0.2em; }
        }
    </style>
</head>
<body>
<table class="wrapper" role="presentation" width="100%" cellspacing="0" cellpadding="0">
    <tr>
        <td align="center">
            <table class="container" role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                    <td class="header">
                        <img src="{{ $logoUrl }}" alt="{{ config('app.name') }}" width="160">
                    </td>
                </tr>
                <tr><td class="accent-bar"></td></tr>
                <tr>
                    <td class="body">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td class="footer">
                        <p>© {{ date('Y') }} {{ config('app.name') }} · {{ __('emails.footer_tagline', [], $lang) }}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
