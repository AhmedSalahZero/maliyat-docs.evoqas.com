<?php

namespace App\Support\Reports;

use Illuminate\Support\Facades\Log;
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
//  PDF ENGINE — mPDF (audit finding M3).
//  The previous engine (dompdf) cannot join Arabic letters into
//  their connected forms, and does not lay text out right-to-left,
//  so every Arabic PDF came out as disconnected letters in the wrong
//  order — unreadable. mPDF does both natively: it shapes Arabic
//  script and runs the bidirectional algorithm, so Arabic, English
//  and numbers can sit in the same line and read correctly. It ships
//  its own fonts with Arabic letters (DejaVu Sans / XB Riyaz), so no
//  font has to be installed or registered by hand.
//
//  Requires composer package mpdf/mpdf — see docs/EXPORT_SETUP.md.
//  Until that is installed on a server, this falls back to dompdf
//  (if present) so English exports keep working, and logs a warning
//  that Arabic PDFs need mPDF.
// ══════════════════════════════════════════════════════════════════
class PdfReportExporter
{
    public static function stream(array $doc, string $filename): Response
    {
        $html = View::make('reports.pdf', ['doc' => $doc])->render();

        $output = class_exists(\Mpdf\Mpdf::class)
            ? self::renderWithMpdf($html, (bool) ($doc['rtl'] ?? false))
            : self::renderWithDompdf($html);

        return response($output, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    /**
     * The PDF as a string, rendered by mPDF with Arabic shaping and
     * right-to-left layout switched on.
     */
    public static function renderWithMpdf(string $html, bool $rtl): string
    {
        $tempDir = storage_path('framework/cache/mpdf');

        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $mpdf = new \Mpdf\Mpdf([
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'orientation'       => 'P',
            'margin_left'       => 10,
            'margin_right'      => 10,
            'margin_top'        => 9,
            'margin_bottom'     => 12,
            'default_font'      => 'dejavusans',
            'tempDir'           => $tempDir,
            // Pick a font that has the letters for each language, and
            // shape Arabic into its joined forms.
            'autoScriptToLang'  => true,
            'autoLangToFont'    => true,
            'useSubstitutions'  => true,
        ]);

        $mpdf->SetDirectionality($rtl ? 'rtl' : 'ltr');
        // Report files never load anything from the internet.
        $mpdf->showImageErrors = false;
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Fallback only — used while mpdf/mpdf is not yet installed.
     * English is fine; Arabic will not join correctly.
     */
    private static function renderWithDompdf(string $html): string
    {
        Log::warning('PDF export: mpdf/mpdf is not installed, falling back to dompdf. Arabic PDFs will not display correctly. See docs/EXPORT_SETUP.md.');

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }
}
