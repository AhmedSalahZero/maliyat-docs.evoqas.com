# Setting up Excel / PDF export

The "Export Excel" and "Export PDF" buttons on every report need two
PHP packages that generate those files. They are not part of this zip
(the zip only ever contains the files Claude wrote or changed — not
your whole project's dependencies), so they need to be installed once
on the server / by whoever deploys the app.

## The one command to run

From the project's root folder (where `composer.json` lives), run:

```
composer update mpdf/mpdf
```

(On a brand-new server, `composer install` installs everything,
including this.)

- **`phpoffice/phpspreadsheet`** — builds the colored `.xlsx` files
  for "Export Excel". Amounts, quantities and percentages are written
  as real numbers (formatted with the currency and 2 decimals), so
  totals and formulas work in Excel straight away.
- **`mpdf/mpdf`** — turns the same report data into a colored,
  downloadable PDF for "Export PDF".

Neither package needs a config file published or any `.env` changes.

If you (or whoever manages hosting) is not comfortable running a
composer command, this is a normal, safe, one-line request to send to
a developer or your hosting support — it does not touch your database
or any existing data.

## Arabic PDF text

Arabic PDFs now work with no extra setup. The PDF engine was changed
from dompdf to **mPDF** (audit finding M3): dompdf cannot join Arabic
letters or lay text out right-to-left, so Arabic reports came out as
separate letters in the wrong order. mPDF does both, and ships its own
fonts that contain Arabic letters — nothing to download or register.

Until `mpdf/mpdf` is installed on a server, the app keeps using dompdf
so English PDFs still work, and writes a warning to the log that
Arabic PDFs need mPDF. `dompdf/dompdf` can be removed from
`composer.json` once every server has mPDF.

mPDF writes small temporary files to `storage/framework/cache/mpdf`;
that folder is created automatically and only needs the same write
permission the rest of `storage/` already has.

## What's involved, in case anything needs adjusting later

- `app/Services/Reports/ReportDataService.php` — all report numbers,
  in one place, used by both the on-screen pages and the exported
  files.
- `app/Support/Reports/ExcelReportExporter.php` — builds the colored
  `.xlsx` files (uses `phpoffice/phpspreadsheet`).
- `app/Support/Reports/PdfReportExporter.php` — builds the colored
  PDFs via `resources/views/reports/pdf.blade.php` (uses `mpdf/mpdf`).
- `app/Http/Controllers/App/ReportExportController.php` — the
  export endpoints, one per report (including the Owner statement), at
  `/app/reports/{report}/export/{excel|pdf}`.
