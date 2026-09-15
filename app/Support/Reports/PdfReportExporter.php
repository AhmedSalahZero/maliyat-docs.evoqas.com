<?php

namespace App\Support\Reports;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — PdfReportExporter
//  Location: app/Support/Reports/PdfReportExporter.php
//
//  Renders the same "report document" array ExcelReportExporter
//  consumes (see that class's doc comment for the shape) through
//  resources/views/reports/pdf.blade.php and streams it back as a
//  downloadable, colored PDF.
//
//  Uses the dompdf/dompdf library directly rather than the
//  barryvdh/laravel-dompdf package's Facade. That Facade's class
//  name has actually changed across major versions of that package
//  (Barryvdh\DomPDF\Facade vs Barryvdh\DomPDF\Facade\Pdf), so
//  depending on it directly is a common source of a "Class ... not
//  found" error if the installed version doesn't match. dompdf/dompdf
//  itself — the actual PDF engine — is a stable, predictable
//  dependency either package pulls in, so this talks to it directly.
//
//  Requires composer package dompdf/dompdf — see
//  docs/EXPORT_SETUP.md. If barryvdh/laravel-dompdf is already
//  installed, dompdf/dompdf is already present too (it's that
//  package's own dependency), so no further action is needed.
// ══════════════════════════════════════════════════════════════════
class PdfReportExporter
{
    public static function stream(array $doc, string $filename): Response
    {
        $html = View::make('reports.pdf', ['doc' => $doc])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $output = $dompdf->output();

        return response($output, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }
}
