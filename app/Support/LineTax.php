<?php

namespace App\Support;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — LineTax
//  Location: app/Support/LineTax.php
//
//  The ONE place that works out VAT and Withholding Tax for the
//  product lines of a Sale or an Inventory Purchase. The controllers,
//  the form-request checks and the tests all call this, so the screen,
//  the validation and the saved invoice can never disagree.
//
//  The rules (confirmed with the business owner):
//
//    line net          = qty x unit price                (rounded to 2)
//    line VAT          = line net x VAT %      / 100     (rounded to 2)
//    line withholding  = line net x Withholding % / 100  (rounded to 2)
//                        — calculated on the amount BEFORE VAT.
//
//    invoice subtotal       = sum of the line nets
//    invoice VAT            = sum of the line VATs
//    invoice withholding    = sum of the line withholdings
//    invoice NET PAYABLE    = subtotal + VAT - withholding
//
//  Every line is rounded on its own and the invoice is the sum of the
//  rounded lines, so the lines on screen always add up to the invoice
//  to the cent.
//
//  A line that carries no VAT % of its own (an old draft, an old API
//  call) falls back to the invoice-level VAT % — that is how invoices
//  worked before VAT became per product line.
// ══════════════════════════════════════════════════════════════════
final class LineTax
{
    /**
     * @param  array<int, mixed>  $lines  each: qty, unit_price, and optionally vat_rate, withholding_rate
     * @param  float  $fallbackVatRate  used for lines with no VAT % of their own
     * @return array{
     *     lines: list<array{line_total: float, vat_rate: float, vat_amount: float, withholding_rate: float, withholding_amount: float}>,
     *     subtotal: float, vat_amount: float, withholding_amount: float, total: float, effective_vat_rate: float
     * }
     */
    public static function compute(array $lines, float $fallbackVatRate = 0.0): array
    {
        $out = [];
        $subtotal = 0.0;
        $vat = 0.0;
        $withholding = 0.0;

        foreach (array_values($lines) as $line) {
            $line = is_array($line) ? $line : [];

            $net = round((float) ($line['qty'] ?? 0) * (float) ($line['unit_price'] ?? 0), 2);

            $vatRate = self::rate($line['vat_rate'] ?? null, $fallbackVatRate);
            $whRate  = self::rate($line['withholding_rate'] ?? null, 0.0);

            $lineVat = round($net * $vatRate / 100, 2);
            $lineWh  = round($net * $whRate / 100, 2);

            $out[] = [
                'line_total'         => $net,
                'vat_rate'           => $vatRate,
                'vat_amount'         => $lineVat,
                'withholding_rate'   => $whRate,
                'withholding_amount' => $lineWh,
            ];

            $subtotal    += $net;
            $vat         += $lineVat;
            $withholding += $lineWh;
        }

        $subtotal    = round($subtotal, 2);
        $vat         = round($vat, 2);
        $withholding = round($withholding, 2);

        return [
            'lines'              => $out,
            'subtotal'           => $subtotal,
            'vat_amount'         => $vat,
            'withholding_amount' => $withholding,
            'total'              => round($subtotal + $vat - $withholding, 2),
            // Only for the invoice header's old single "VAT %" column,
            // which now just records the overall effective rate.
            'effective_vat_rate' => $subtotal > 0 ? round($vat / $subtotal * 100, 2) : 0.0,
        ];
    }

    private static function rate(mixed $value, float $fallback): float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return $fallback;
        }

        return (float) $value;
    }
}
