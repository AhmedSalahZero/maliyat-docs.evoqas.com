{{--
    Maliyat Docs — reports/pdf.blade.php
    Location: resources/views/reports/pdf.blade.php

    ONE template renders every report's PDF (Ledger, P&L, both
    statements, Inventory, Cash Flow) — each is just a different
    "$doc" array built by ReportExportController from the exact
    same ReportDataService the on-screen page reads, and shaped by
    PdfReportExporter (see its doc comment for the array shape:
    title / subtitle / meta / stats / sections).

    Rendered by dompdf (barryvdh/laravel-dompdf). If your printed
    Arabic reports look wrong (garbled/missing characters), it's
    almost certainly a missing font — see the note at the bottom of
    this file and docs/EXPORT_SETUP.md.
--}}
<!DOCTYPE html>
<html dir="{{ $doc['rtl'] ?? false ? 'rtl' : 'ltr' }}" lang="{{ $doc['rtl'] ?? false ? 'ar' : 'en' }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 26px 28px 34px; }

        body {
            font-family: {{ ($doc['rtl'] ?? false) ? "'Cairo', 'DejaVu Sans', sans-serif" : "'DejaVu Sans', Arial, sans-serif" }};
            color: #1B2233;
            font-size: 11px;
            direction: {{ ($doc['rtl'] ?? false) ? 'rtl' : 'ltr' }};
        }

        .titlebar {
            background: #2D6CDF;
            color: #ffffff;
            padding: 14px 18px;
            border-radius: 6px;
            margin-bottom: 4px;
        }
        .titlebar h1 { margin: 0; font-size: 20px; }
        .titlebar .subtitle { margin-top: 4px; font-size: 11px; opacity: 0.92; }

        .meta { margin: 12px 0 6px; width: 100%; }
        .meta td { padding: 2px 0; font-size: 10.5px; }
        .meta td.label { color: #6B7686; width: 130px; }
        .meta td.value { font-weight: bold; color: #1B2233; }

        .stats { width: 100%; margin: 14px 0 10px; border-collapse: separate; border-spacing: 6px 0; }
        .stats td {
            background: #EEF2FA;
            border-radius: 6px;
            padding: 10px 12px;
            width: 33%;
        }
        .stats .stat-label { display: block; font-size: 9px; color: #6B7686; margin-bottom: 4px; }
        .stats .stat-value { display: block; font-size: 15px; font-weight: bold; color: #1B2233; }
        .stats .tone-income .stat-value  { color: #0E7C39; }
        .stats .tone-expense .stat-value { color: #B91C2C; }
        .stats .tone-primary .stat-value { color: #1E4FB0; }
        .stats .tone-income  { background: #E3F7EA; }
        .stats .tone-expense { background: #FDE8E9; }
        .stats .tone-primary { background: #E8F0FE; }

        .section-heading {
            font-size: 13px;
            font-weight: bold;
            color: #1E4FB0;
            margin: 16px 0 6px;
            border-bottom: 2px solid #2D6CDF;
            padding-bottom: 3px;
        }

        table.report-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.report-table thead th {
            background: #2D6CDF;
            color: #ffffff;
            font-size: 10px;
            text-align: {{ ($doc['rtl'] ?? false) ? 'right' : 'left' }};
            padding: 6px 8px;
        }
        table.report-table thead th.align-end { text-align: {{ ($doc['rtl'] ?? false) ? 'left' : 'right' }}; }
        table.report-table tbody td {
            font-size: 10px;
            padding: 5px 8px;
            border-bottom: 1px solid #E4E9F2;
        }
        table.report-table tbody td.align-end { text-align: {{ ($doc['rtl'] ?? false) ? 'left' : 'right' }}; }
        table.report-table tbody tr.zebra td { background: #F4F7FC; }
        table.report-table tfoot td {
            font-size: 10.5px;
            font-weight: bold;
            padding: 6px 8px;
            background: #E8F0FE;
            color: #1E4FB0;
            border-top: 2px solid #2D6CDF;
        }
        table.report-table tfoot td.align-end { text-align: {{ ($doc['rtl'] ?? false) ? 'left' : 'right' }}; }

        .footer-note { margin-top: 18px; font-size: 8.5px; color: #8993A6; text-align: center; }
    </style>
</head>
<body>
    <div class="titlebar">
        <h1>{{ $doc['title'] ?? 'Report' }}</h1>
        @if (!empty($doc['subtitle']))
            <div class="subtitle">{{ $doc['subtitle'] }}</div>
        @endif
    </div>

    @if (!empty($doc['meta']))
        <table class="meta">
            @foreach ($doc['meta'] as $meta)
                <tr>
                    <td class="label">{{ $meta['label'] }}</td>
                    <td class="value">{{ $meta['value'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (!empty($doc['stats']))
        <table class="stats">
            <tr>
                @foreach ($doc['stats'] as $stat)
                    <td class="tone-{{ $stat['tone'] ?? 'neutral' }}">
                        <span class="stat-label">{{ $stat['label'] }}</span>
                        <span class="stat-value">{{ $stat['value'] }}</span>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @foreach ($doc['sections'] ?? [] as $section)
        @if (!empty($section['heading']))
            <div class="section-heading">{{ $section['heading'] }}</div>
        @endif

        <table class="report-table">
            <thead>
                <tr>
                    @foreach ($section['columns'] as $column)
                        <th class="{{ ($column['align'] ?? 'start') === 'end' ? 'align-end' : '' }}">{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($section['rows'] ?? [] as $i => $row)
                    <tr @if ($i % 2 === 1) class="zebra" @endif>
                        @foreach ($section['columns'] as $c => $column)
                            <td class="{{ ($column['align'] ?? 'start') === 'end' ? 'align-end' : '' }}">{{ $row[$c] ?? '' }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($section['columns']) }}" style="text-align:center; color:#8993A6; padding:14px;">
                        {{ $doc['empty_label'] ?? 'No data for this period.' }}
                    </td></tr>
                @endforelse
            </tbody>
            @if (!empty($section['totals']))
                <tfoot>
                    <tr>
                        @foreach ($section['columns'] as $c => $column)
                            <td class="{{ ($column['align'] ?? 'start') === 'end' ? 'align-end' : '' }}">{{ $section['totals'][$c] ?? '' }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    @endforeach

    <div class="footer-note">{{ $doc['footer'] ?? 'MaliyatDocs' }}</div>
</body>
</html>
