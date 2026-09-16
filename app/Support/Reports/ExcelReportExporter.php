<?php

namespace App\Support\Reports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ExcelReportExporter
//  Location: app/Support/Reports/ExcelReportExporter.php
//
//  Turns the generic "report document" array (see ReportDocument
//  doc comment below) into a colored, formatted .xlsx download.
//  Every report — Ledger, P&L, both statements, Inventory, Cash
//  Flow — goes through this ONE builder so a change to the look of
//  exported spreadsheets (a new brand colour, a wider column) only
//  has to be made once.
//
//  Report document shape (plain arrays, no classes — this stays
//  easy to read for whoever maintains it after us):
//
//  [
//      'title'    => 'Profit & Loss',
//      'subtitle' => '1 Sep 2026 – 30 Sep 2026',           // optional
//      'meta'     => [['label' => 'Company', 'value' => 'Acme'], ...],
//      'stats'    => [['label' => 'Net profit', 'value' => 'EGP 4,200.00', 'tone' => 'income'], ...],
//      'sections' => [
//          [
//              'heading' => 'Expenses by category',         // optional
//              'columns' => [['label' => 'Category', 'align' => 'start'], ['label' => 'Amount', 'align' => 'end']],
//              'rows'    => [['Rent', 'EGP 1,000.00'], ...], // plain strings, already formatted
//              'totals'  => ['Total', 'EGP 3,400.00'],       // optional footer row
//          ],
//      ],
//      'rtl' => false,
//  ]
// ══════════════════════════════════════════════════════════════════
class ExcelReportExporter
{
    private const BRAND_BLUE   = '2D6CDF';
    private const BRAND_DARK   = '1E4FB0';
    private const SOFT_BLUE    = 'E8F0FE';
    private const ZEBRA        = 'F4F7FC';
    private const TEXT_DARK    = '1B2233';
    private const BORDER_COLOR = 'E4E9F2';

    public static function stream(array $doc, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($doc['title'] ?? 'Report', 0, 31));

        if (! empty($doc['rtl'])) {
            $sheet->setRightToLeft(true);
        }

        $row = 1;

        // Work out how many columns the widest section needs, so the
        // title/meta/stat rows can merge across the true width
        // instead of guessing a fixed number.
        $colCount = 1;
        foreach ($doc['sections'] ?? [] as $section) {
            $colCount = max($colCount, count($section['columns'] ?? []));
        }
        $colCount  = max($colCount, 2);
        $lastColLetter = self::columnLetter($colCount);

        // ── Title banner ────────────────────────────────────────
        $sheet->mergeCells("A{$row}:{$lastColLetter}{$row}");
        $sheet->setCellValue("A{$row}", self::safeCell($doc['title'] ?? 'Report'));
        $sheet->getStyle("A{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::BRAND_BLUE]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(30);
        $row++;

        if (! empty($doc['subtitle'])) {
            $sheet->mergeCells("A{$row}:{$lastColLetter}{$row}");
            $sheet->setCellValue("A{$row}", self::safeCell($doc['subtitle']));
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['italic' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::BRAND_DARK]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $row++;
        }

        $row++; // spacer

        // ── Meta lines (company, currency, generated at, filters) ─
        foreach ($doc['meta'] ?? [] as $meta) {
            $sheet->setCellValue("A{$row}", self::safeCell($meta['label'].':'));
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => self::TEXT_DARK]],
            ]);
            $sheet->setCellValue("B{$row}", self::safeCell($meta['value']));
            $row++;
        }

        if (! empty($doc['meta'])) {
            $row++; // spacer
        }

        // ── Stat boxes (rendered as a small colored summary strip) ─
        if (! empty($doc['stats'])) {
            $col = 1;
            $statsRow = $row;
            foreach ($doc['stats'] as $stat) {
                $colLetter = self::columnLetter($col);
                $labelColor = match ($stat['tone'] ?? 'neutral') {
                    'income' => '0E7C39', 'expense' => 'B91C2C', 'primary' => self::BRAND_DARK, default => self::TEXT_DARK,
                };
                $fill = match ($stat['tone'] ?? 'neutral') {
                    'income' => 'E3F7EA', 'expense' => 'FDE8E9', 'primary' => self::SOFT_BLUE, default => 'EEF2FA',
                };
                $sheet->setCellValue("{$colLetter}{$statsRow}", self::safeCell($stat['label']));
                $sheet->setCellValue("{$colLetter}".($statsRow + 1), self::safeCell($stat['value']));
                $sheet->getStyle("{$colLetter}{$statsRow}")->applyFromArray([
                    'font' => ['size' => 9, 'color' => ['rgb' => self::TEXT_DARK]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
                ]);
                $sheet->getStyle("{$colLetter}".($statsRow + 1))->applyFromArray([
                    'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => $labelColor]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
                ]);
                $col++;
            }
            $row = $statsRow + 3;
        }

        // ── Sections (each with its own header row + rows + totals) ─
        foreach ($doc['sections'] ?? [] as $section) {
            if (! empty($section['heading'])) {
                $sheet->setCellValue("A{$row}", self::safeCell($section['heading']));
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => self::BRAND_DARK]],
                ]);
                $row++;
            }

            $columns  = $section['columns'] ?? [];
            $headerRow = $row;

            foreach ($columns as $i => $column) {
                $colLetter = self::columnLetter($i + 1);
                $sheet->setCellValue("{$colLetter}{$headerRow}", self::safeCell($column['label']));
            }
            $sheet->getStyle("A{$headerRow}:".self::columnLetter(count($columns))."{$headerRow}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::BRAND_BLUE]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($headerRow)->setRowHeight(22);
            $row++;

            $dataStartRow = $row;
            foreach ($section['rows'] ?? [] as $rowIndex => $dataRow) {
                foreach ($columns as $i => $column) {
                    $colLetter = self::columnLetter($i + 1);
                    $sheet->setCellValue("{$colLetter}{$row}", self::safeCell($dataRow[$i] ?? ''));
                    $sheet->getStyle("{$colLetter}{$row}")->getAlignment()->setHorizontal(
                        ($column['align'] ?? 'start') === 'end' ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT
                    );
                }
                if ($rowIndex % 2 === 1) {
                    $sheet->getStyle("A{$row}:".self::columnLetter(count($columns))."{$row}")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::ZEBRA]],
                    ]);
                }
                $row++;
            }
            $dataEndRow = $row - 1;

            if ($dataEndRow >= $dataStartRow) {
                $sheet->getStyle("A{$dataStartRow}:".self::columnLetter(count($columns))."{$dataEndRow}")->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
                ]);
            }

            if (! empty($section['totals'])) {
                foreach ($columns as $i => $column) {
                    $colLetter = self::columnLetter($i + 1);
                    $sheet->setCellValue("{$colLetter}{$row}", self::safeCell($section['totals'][$i] ?? ''));
                }
                $sheet->getStyle("A{$row}:".self::columnLetter(count($columns))."{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => self::BRAND_DARK]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT_BLUE]],
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::BRAND_BLUE]]],
                ]);
                $row++;
            }

            $row++; // spacer between sections
        }

        // ── Column widths — generous enough for money figures ────
        foreach (range(1, $colCount) as $i) {
            $sheet->getColumnDimension(self::columnLetter($i))->setWidth($i === 1 ? 28 : 20);
        }

        $sheet->getStyle("A1:{$lastColLetter}{$row}")->getFont()->setName('Arial');

        $writer = new Xlsx($spreadsheet);

        $response = new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'.xlsx"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }

    private static function columnLetter(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

    /**
     * Guard against spreadsheet "formula injection" (a.k.a. CSV/Excel
     * injection — see e.g. OWASP's CSV Injection page).
     *
     * Every value written into a data cell ultimately traces back,
     * at some point in the call chain, to something a user typed —
     * a customer name, a vendor name, a category — none of which is
     * restricted from starting with a spreadsheet-formula character.
     * Excel (and most spreadsheet software) treats a cell starting
     * with =, +, -, or @ as a formula to evaluate when the file is
     * opened, not as plain text — so an unescaped customer named
     * e.g. `=HYPERLINK("https://evil.example","Click")` would render
     * as a live, misleading link the moment someone opens the
     * exported statement in Excel.
     *
     * Prefixing such a value with a leading apostrophe is the
     * standard fix: spreadsheet software treats a leading apostrophe
     * as "the rest of this is literal text", so the value still
     * displays exactly as typed but is never evaluated as a formula.
     * Ordinary text (the overwhelming majority of what passes
     * through here) is returned completely unchanged.
     */
    private static function safeCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return str_starts_with($value, '=')
            || str_starts_with($value, '+')
            || str_starts_with($value, '-')
            || str_starts_with($value, '@')
            ? "'".$value
            : $value;
    }
}
