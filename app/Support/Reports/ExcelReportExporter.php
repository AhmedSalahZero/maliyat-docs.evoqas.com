<?php

namespace App\Support\Reports;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
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
//
//  A row is normally the plain array shown above. A report that
//  needs section headings and inline running totals inside ONE
//  table (currently only the P&L) can instead pass a row as
//  ['cells' => [...], 'emphasis' => 'heading'|'total'|'result',
//  'indent' => true] — every other report's plain rows are
//  completely unaffected by this.
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
                self::writeCell($sheet, "{$colLetter}".($statsRow + 1), $stat['value'], true);
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
                // A row is normally a plain array of cell values (every
                // existing report — Ledger, Trial Balance, statements,
                // etc.). It can ALSO be ['cells' => [...], 'emphasis' =>
                // 'heading'|'total'|'result', 'indent' => true] for a
                // report — currently only the P&L — that needs section
                // headings and inline totals inside ONE table rather
                // than the separate-section layout every other report
                // uses. Detecting the associative shape here keeps
                // every other caller's plain rows completely unchanged.
                $isStyledRow = is_array($dataRow) && array_key_exists('cells', $dataRow);
                $cells       = $isStyledRow ? $dataRow['cells'] : $dataRow;
                $emphasis    = $isStyledRow ? ($dataRow['emphasis'] ?? null) : null;
                $indent      = $isStyledRow && ! empty($dataRow['indent']);

                foreach ($columns as $i => $column) {
                    $colLetter = self::columnLetter($i + 1);
                    self::writeCell($sheet, "{$colLetter}{$row}", $cells[$i] ?? '', ($column['align'] ?? 'start') === 'end');
                    $align = $sheet->getStyle("{$colLetter}{$row}")->getAlignment();
                    $align->setHorizontal(
                        ($column['align'] ?? 'start') === 'end' ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT
                    );
                    if ($indent && $i === 0) {
                        $align->setIndent(1);
                    }
                }

                if ($emphasis === 'heading') {
                    $sheet->getStyle("A{$row}:".self::columnLetter(count($columns))."{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => self::TEXT_DARK]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::ZEBRA]],
                    ]);
                } elseif ($emphasis === 'total') {
                    $sheet->getStyle("A{$row}:".self::columnLetter(count($columns))."{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => self::TEXT_DARK]],
                        'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BORDER_COLOR]]],
                    ]);
                } elseif ($emphasis === 'result') {
                    $sheet->getStyle("A{$row}:".self::columnLetter(count($columns))."{$row}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => self::BRAND_DARK]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SOFT_BLUE]],
                        'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::BRAND_BLUE]]],
                    ]);
                } elseif ($rowIndex % 2 === 1) {
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
                    self::writeCell($sheet, "{$colLetter}{$row}", $section['totals'][$i] ?? '', ($column['align'] ?? 'start') === 'end');
                    if (($column['align'] ?? 'start') === 'end') {
                        $sheet->getStyle("{$colLetter}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    }
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

    /**
     * Write one cell. In a figures column (right-aligned: amounts,
     * quantities, percentages) a value that reads as a number is
     * written as a REAL number with a display format, not as text
     * (audit finding M4) — so SUM(), sorting and formulas work in
     * Excel without the accountant converting every column.
     *
     * The report builder hands over display strings such as
     * "EGP 1,250.00", "-300.00", "12.5" or "34.2%". Those are
     * recognised here and turned back into their value, and the cell
     * is formatted to look exactly as before (currency, thousands
     * separator, 2 decimals). Anything else — "—", a label, a name —
     * is written as text, still guarded against formula injection.
     */
    private static function writeCell(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $coordinate, mixed $value, bool $figuresColumn): void
    {
        if (is_int($value) || is_float($value)) {
            $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC);
            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('#,##0.00');

            return;
        }

        if ($figuresColumn && is_string($value) && ($parsed = self::parseFigure($value)) !== null) {
            [$number, $format] = $parsed;
            $sheet->setCellValueExplicit($coordinate, $number, DataType::TYPE_NUMERIC);
            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($format);

            return;
        }

        $sheet->setCellValue($coordinate, self::safeCell($value));
    }

    /**
     * "EGP 1,250.00" → [1250.0, '"EGP "#,##0.00;"EGP "-#,##0.00']
     * "-300.5"       → [-300.5, '#,##0.00']  (2 decimals kept as shown)
     * "12"           → [12.0,   '#,##0']
     * "34.2%"        → [0.342,  '0.0%']
     * anything else  → null (stays text)
     *
     * @return array{0: float, 1: string}|null
     */
    public static function parseFigure(string $value): ?array
    {
        $value = trim($value);

        // An optional sign may come before the currency too — the Cash
        // Flow report writes "+EGP 500.00" / "-EGP 200.00".
        if (! preg_match('/^([+-]?)(?:(\p{L}[\p{L}.$]{0,5}|[$€£¥])\s?)?(-?)(\d{1,3}(?:,\d{3})*|\d+)(?:\.(\d+))?(%)?$/u', $value, $m)) {
            return null;
        }

        [$all, $leadSign, $currency, $innerSign, $whole] = $m;
        // Brackets matter: PHP's `xor` binds more loosely than `=`, so
        // without them the minus sign was silently dropped.
        $negative = (($leadSign === '-') xor ($innerSign === '-'));
        $sign     = $negative ? '-' : '';
        $decimals = $m[5] ?? '';
        $percent  = ($m[6] ?? '') === '%';

        $number = (float) (str_replace(',', '', $whole).($decimals !== '' ? '.'.$decimals : ''));
        if ($sign === '-') {
            $number = -$number;
        }

        if ($percent) {
            $places = max(strlen($decimals), 0);

            return [$number / 100, '0'.($places ? '.'.str_repeat('0', $places) : '').'%'];
        }

        $pattern = $decimals !== '' ? '#,##0.'.str_repeat('0', strlen($decimals)) : '#,##0';

        if ($currency !== '') {
            $prefix = '"'.str_replace('"', '', $currency).' "';

            return [$number, $prefix.$pattern.';'.$prefix.'-'.$pattern];
        }

        return [$number, $pattern];
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
