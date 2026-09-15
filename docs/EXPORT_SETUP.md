# Setting up Excel / PDF export

The "Export Excel" and "Export PDF" buttons on every report need two
PHP packages that generate those files. They are not part of this zip
(the zip only ever contains the files Claude wrote or changed — not
your whole project's dependencies), so they need to be installed once
on the server / by whoever deploys the app.

## The one command to run

From the project's root folder (where `composer.json` lives), run:

```
composer require dompdf/dompdf phpoffice/phpspreadsheet
```

**Update:** an earlier version of this note said to install
`barryvdh/laravel-dompdf` instead of `dompdf/dompdf`. That package's
own "Facade" class has changed name across its versions
(`Barryvdh\DomPDF\Facade` vs `Barryvdh\DomPDF\Facade\Pdf`), which is
exactly what caused the *"Class ... Facade\Pdf not found"* error — the
code was written against a version-specific class name. The PDF
exporter now talks to `dompdf/dompdf` (the actual PDF engine) directly
instead, so that whole problem goes away. If `barryvdh/laravel-dompdf`
is already installed from before, that's fine too — it depends on
`dompdf/dompdf` itself, so nothing conflicts — but it's no longer
required on its own.

- **`phpoffice/phpspreadsheet`** — builds the colored `.xlsx` files
  for "Export Excel".
- **`dompdf/dompdf`** — turns the same report data into a colored,
  downloadable PDF for "Export PDF".

Neither package needs a config file published or any `.env` changes.

If you (or whoever manages hosting) is not comfortable running a
composer command, this is a normal, safe, one-line request to send to
a developer or your hosting support — it does not touch your database
or any existing data.

## Arabic PDF text

dompdf does not ship a font with Arabic letters built in by default.
If a company's language is set to Arabic and the exported PDF shows
boxes or missing letters instead of Arabic text:

1. Download the **Cairo** font (the same font already used in the
   app) from Google Fonts: https://fonts.google.com/specimen/Cairo
2. Register it with dompdf's font loader — see dompdf's own
   documentation: https://github.com/dompdf/dompdf/wiki/Fonts
   (search "Loading Custom Fonts" — it's a short script you run once).
3. In `app/Support/Reports/PdfReportExporter.php`, change
   `$options->set('defaultFont', 'DejaVu Sans');` to
   `$options->set('defaultFont', 'Cairo');` once the font is
   registered.

English-language PDFs and Excel files (both languages) work
immediately with no extra setup — this is only needed for Arabic PDF
exports specifically.

## What's involved, in case anything needs adjusting later

- `app/Services/Reports/ReportDataService.php` — all report numbers,
  in one place, used by both the on-screen pages and the exported
  files.
- `app/Support/Reports/ExcelReportExporter.php` — builds the colored
  `.xlsx` files (uses `phpoffice/phpspreadsheet`).
- `app/Support/Reports/PdfReportExporter.php` — builds the colored
  PDFs via `resources/views/reports/pdf.blade.php` (uses
  `dompdf/dompdf` directly).
- `app/Http/Controllers/App/ReportExportController.php` — the six
  export endpoints, one per report, at
  `/app/reports/{report}/export/{excel|pdf}`.
