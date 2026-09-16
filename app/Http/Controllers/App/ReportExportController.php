<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Vendor;
use App\Services\Reports\ReportDataService;
use App\Support\Reports\ExcelReportExporter;
use App\Support\Reports\PdfReportExporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — ReportExportController
//  Location: app/Http/Controllers/App/ReportExportController.php
//
//  One method per report, each doing the same three things:
//    1. Ask ReportDataService for the numbers (the exact same call
//       ReportController makes for the on-screen page).
//    2. Shape those numbers into the generic "report document"
//       array (title/subtitle/meta/stats/sections — see
//       ExcelReportExporter's doc comment for the exact shape).
//    3. Hand that array to ExcelReportExporter or PdfReportExporter
//       depending on the {format} route segment ("excel" or "pdf").
//
//  Labels are kept bilingual here (not pulled from the frontend's
//  appTranslations.js) since this controller never touches Vue —
//  see $this->labels(). Only the ~40 words a report file needs.
// ══════════════════════════════════════════════════════════════════
class ReportExportController extends Controller
{
    public function __construct(private readonly ReportDataService $reports) {}

    public function ledger(Request $request, string $format): Response
    {
        [$from, $to] = $this->reports->monthRange($request->string('from')->value() ?: null, $request->string('to')->value() ?: null);
        $entries = $this->reports->ledger($from, $to);
        $l = $this->labels();
        $currency = $this->currency();

        $statusLabel = fn (string $s) => $l['status_'.$s];
        $typeLabel   = fn (string $t) => $l['type_'.$t];

        $rows = $entries->map(fn ($e) => [
            $e['date'],
            $typeLabel($e['type']),
            $e['party'] ?? '—',
            $this->money($e['amount'], $currency),
            $this->money($e['balance'], $currency),
            $statusLabel($e['status']),
        ])->all();

        $doc = $this->baseDoc($l['report_ledger'], $from, $to, [
            [
                'columns' => [
                    ['label' => $l['date']], ['label' => $l['type']], ['label' => $l['party']],
                    ['label' => $l['amount'], 'align' => 'end'], ['label' => $l['balance'], 'align' => 'end'], ['label' => $l['status']],
                ],
                'rows' => $rows,
            ],
        ]);

        return $this->respond($format, $doc, 'ledger-'.$from.'-to-'.$to);
    }

    public function profitAndLoss(Request $request, string $format): Response
    {
        [$from, $to] = $this->reports->monthRange($request->string('from')->value() ?: null, $request->string('to')->value() ?: null);
        $data = $this->reports->profitAndLoss($from, $to);
        $l = $this->labels();
        $currency = $this->currency();

        $doc = $this->baseDoc($l['report_pl'], $from, $to, [
            [
                'heading' => $l['income_by_item'],
                'columns' => [['label' => $l['item']], ['label' => $l['amount'], 'align' => 'end']],
                'rows'    => collect($data['income_by_item'])->map(fn ($r) => [$r->item, $this->money((float) $r->total, $currency)])->all(),
            ],
            [
                'heading' => $l['expenses_by_category'],
                'columns' => [['label' => $l['category']], ['label' => $l['amount'], 'align' => 'end']],
                'rows'    => collect($data['expenses_by_category'])->map(fn ($r) => [$r->category, $this->money((float) $r->total, $currency)])->all(),
            ],
        ]);

        $doc['stats'] = [
            ['label' => $l['revenue'], 'value' => $this->money($data['revenue'], $currency), 'tone' => 'income'],
            ['label' => $l['cost_of_goods_sold'], 'value' => $this->money($data['cost_of_goods_sold'], $currency), 'tone' => 'expense'],
            ['label' => $l['gross_profit'], 'value' => $this->money($data['gross_profit'], $currency), 'tone' => 'primary'],
            ['label' => $l['operating_expenses'], 'value' => $this->money($data['operating_expenses'], $currency), 'tone' => 'expense'],
            ['label' => $l['net_profit'], 'value' => $this->money($data['net_profit'], $currency), 'tone' => 'primary'],
        ];

        return $this->respond($format, $doc, 'profit-and-loss-'.$from.'-to-'.$to);
    }

    public function customerStatement(Request $request, Customer $customer, string $format): Response
    {
        [$from, $to] = $this->statementRange($request);

        $data = $this->reports->customerStatement($customer, $from, $to);
        $l = $this->labels();
        $currency = $this->currency();

        $rows = $data['entries']->map(fn ($e) => [
            $e['date'],
            $e['ref'],
            $e['debit'] > 0 ? $this->money($e['debit'], $currency) : '—',
            $e['credit'] > 0 ? $this->money($e['credit'], $currency) : '—',
            $this->money($e['running_balance'], $currency),
        ])->all();

        // The brought-forward figure leads the table when a range is
        // in play, or the first running balance appears to come from
        // nowhere.
        if ($from) {
            array_unshift($rows, [
                $from, $l['balance_brought_forward'], '—', '—',
                $this->money($data['opening_balance'], $currency),
            ]);
        }

        $doc = $this->baseDoc($l['report_customer_statement'].' — '.$customer->name, $from, $to, [
            [
                'columns' => [
                    ['label' => $l['date']], ['label' => $l['reference']],
                    ['label' => $l['debit'], 'align' => 'end'], ['label' => $l['credit'], 'align' => 'end'],
                    ['label' => $l['running_balance'], 'align' => 'end'],
                ],
                'rows' => $rows,
                'totals' => ['', '', '', $l['total_balance'], $this->money($data['balance'], $currency)],
            ],
        ]);

        return $this->respond($format, $doc, 'customer-statement-'.str($customer->name)->slug());
    }

    public function supplierStatement(Request $request, Vendor $vendor, string $format): Response
    {
        [$from, $to] = $this->statementRange($request);

        $data = $this->reports->supplierStatement($vendor, $from, $to);
        $l = $this->labels();
        $currency = $this->currency();

        $rows = $data['entries']->map(fn ($e) => [
            $e['date'],
            $e['ref'],
            $e['debit'] > 0 ? $this->money($e['debit'], $currency) : '—',
            $e['credit_owed'] > 0 ? $this->money($e['credit_owed'], $currency) : '—',
            $this->money($e['running_balance'], $currency),
        ])->all();

        if ($from) {
            array_unshift($rows, [
                $from, $l['balance_brought_forward'], '—', '—',
                $this->money($data['opening_balance'], $currency),
            ]);
        }

        $doc = $this->baseDoc($l['report_supplier_statement'].' — '.$vendor->name, $from, $to, [
            [
                'columns' => [
                    ['label' => $l['date']], ['label' => $l['reference']],
                    ['label' => $l['paid'], 'align' => 'end'], ['label' => $l['owed'], 'align' => 'end'],
                    ['label' => $l['running_balance'], 'align' => 'end'],
                ],
                'rows' => $rows,
                'totals' => ['', '', '', $l['total_balance'], $this->money($data['balance'], $currency)],
            ],
        ]);

        return $this->respond($format, $doc, 'supplier-statement-'.str($vendor->name)->slug());
    }

    public function inventoryStatement(Request $request, string $format): Response
    {
        $itemId = $request->filled('item_id') ? $request->integer('item_id') : null;
        [$from, $to] = $this->statementRange($request);
        $data   = $this->reports->inventoryStatement($itemId, $from, $to);
        $l = $this->labels();
        $currency = $this->currency();

        if ($data['selected']) {
            $item = $data['selected'];

            $historyTypeLabels = [
                'purchase' => $l['type_inventory_purchase'],
                'sale'     => $l['type_sale'],
                'produced' => $l['type_produced'],
                'consumed' => $l['type_consumed'],
            ];

            $rows = $data['history']->map(fn ($h) => [
                $h['date'],
                $historyTypeLabels[$h['type']] ?? $h['type'],
                $h['ref'],
                ($h['qty'] >= 0 ? '+' : '').$this->qty($h['qty']).' '.$item['base_unit_name'],
                $this->money($h['unit_price'], $currency),
                $this->qty($h['stock_after']).' '.$item['base_unit_name'],
            ])->all();

            $doc = $this->baseDoc($l['report_inventory_statement'].' — '.$item['name'], $from, $to, [
                [
                    'columns' => [
                        ['label' => $l['date']], ['label' => $l['type']], ['label' => $l['reference']],
                        ['label' => $l['qty'], 'align' => 'end'], ['label' => $l['unit_price'], 'align' => 'end'],
                        ['label' => $l['stock_after'], 'align' => 'end'],
                    ],
                    'rows' => $rows,
                ],
            ]);

            $doc['stats'] = [
                ['label' => $l['total_in'], 'value' => $this->qty($item['total_in_base']).' '.$item['base_unit_name'], 'tone' => 'income'],
                ['label' => $l['total_out'], 'value' => $this->qty($item['total_out_base']).' '.$item['base_unit_name'], 'tone' => 'expense'],
                ['label' => $l['current_stock'], 'value' => $this->qty($item['current_stock']).' '.$item['base_unit_name'], 'tone' => 'primary'],
            ];

            return $this->respond($format, $doc, 'inventory-'.str($item['name'])->slug());
        }

        $rows = collect($data['items'])->map(fn ($it) => [
            $it['name'],
            $this->qty($it['total_in_base']).' '.$it['base_unit_name'],
            $this->qty($it['total_out_base']).' '.$it['base_unit_name'],
            $this->qty($it['current_stock']).' '.$it['base_unit_name'],
            $it['avg_purchase_cost'] !== null ? $this->money($it['avg_purchase_cost'], $currency) : '—',
            $this->money($it['stock_value'], $currency),
        ])->all();

        $doc = $this->baseDoc($l['report_inventory_statement'], $from, $to, [
            [
                'columns' => [
                    ['label' => $l['item']], ['label' => $l['total_in'], 'align' => 'end'],
                    ['label' => $l['total_out'], 'align' => 'end'], ['label' => $l['current_stock'], 'align' => 'end'],
                    ['label' => $l['avg_cost'], 'align' => 'end'], ['label' => $l['stock_value'], 'align' => 'end'],
                ],
                'rows' => $rows,
                'totals' => ['', '', '', '', $l['total_stock_value'], $this->money($data['total_stock_value'], $currency)],
            ],
        ]);

        return $this->respond($format, $doc, 'inventory-statement');
    }

    public function cashFlow(Request $request, string $format): Response
    {
        [$from, $to] = $this->reports->monthRange($request->string('from')->value() ?: null, $request->string('to')->value() ?: null);
        $data = $this->reports->cashFlow($from, $to);
        $l = $this->labels();
        $currency = $this->currency();

        $byMethod = collect($data['by_method'])->groupBy('method')->map(function ($rows, $method) {
            return [
                'method' => $method,
                'in'     => (float) $rows->firstWhere('direction', 'in')?->total ?? 0,
                'out'    => (float) $rows->firstWhere('direction', 'out')?->total ?? 0,
            ];
        })->values();

        $doc = $this->baseDoc($l['report_cashflow'], $from, $to, [
            [
                'heading' => $l['by_method'],
                'columns' => [['label' => $l['method']], ['label' => $l['cash_in'], 'align' => 'end'], ['label' => $l['cash_out'], 'align' => 'end']],
                'rows'    => $byMethod->map(fn ($m) => [$l['method_'.$m['method']] ?? ucfirst($m['method']), $this->money($m['in'], $currency), $this->money($m['out'], $currency)])->all(),
            ],
            [
                'heading' => $l['movements'],
                'columns' => [
                    ['label' => $l['date']], ['label' => $l['party']], ['label' => $l['method']],
                    ['label' => $l['direction']], ['label' => $l['amount'], 'align' => 'end'],
                ],
                'rows' => collect($data['movements'])->map(fn ($m) => [
                    $m['date'], $m['party'], $l['method_'.$m['method']] ?? ucfirst($m['method']),
                    $m['direction'] === 'in' ? $l['cash_in'] : $l['cash_out'],
                    ($m['direction'] === 'in' ? '+' : '-').$this->money($m['amount'], $currency),
                ])->all(),
            ],
        ]);

        $doc['stats'] = [
            ['label' => $l['cash_in'], 'value' => $this->money($data['cash_in'], $currency), 'tone' => 'income'],
            ['label' => $l['cash_out'], 'value' => $this->money($data['cash_out'], $currency), 'tone' => 'expense'],
            ['label' => $l['net_flow'], 'value' => $this->money($data['net_flow'], $currency), 'tone' => 'primary'],
        ];

        return $this->respond($format, $doc, 'cash-flow-'.$from.'-to-'.$to);
    }

    /**
     * Trial Balance — every account's balance as of a date. Meant
     * for the company's auditor; see ReportDataService::trialBalance().
     */
    public function trialBalance(Request $request, string $format): Response
    {
        $asOf = $request->string('as_of')->value() ?: now()->toDateString();
        $data = $this->reports->trialBalance($asOf);
        $l = $this->labels();
        $currency = $this->currency();

        $rows = collect($data['rows'])->map(fn ($row) => [
            $row['code'],
            (app()->getLocale() === 'ar' && $row['name_ar']) ? $row['name_ar'] : $row['name'],
            $row['debit_balance'] > 0 ? $this->money($row['debit_balance'], $currency) : '—',
            $row['credit_balance'] > 0 ? $this->money($row['credit_balance'], $currency) : '—',
        ])->all();

        $doc = $this->baseDoc($l['report_trial_balance'].' — '.$l['as_of'].' '.$asOf, null, null, [
            [
                'columns' => [
                    ['label' => $l['code']], ['label' => $l['account']],
                    ['label' => $l['debit'], 'align' => 'end'], ['label' => $l['credit'], 'align' => 'end'],
                ],
                'rows' => $rows,
                'totals' => ['', $l['total_balance'], $this->money($data['total_debit'], $currency), $this->money($data['total_credit'], $currency)],
            ],
        ]);

        return $this->respond($format, $doc, 'trial-balance-'.$asOf);
    }

    /**
     * Journal — every posted double-entry in the range, one section
     * per entry so each entry's own lines stay visually grouped.
     */
    public function journal(Request $request, string $format): Response
    {
        [$from, $to] = $this->reports->monthRange($request->string('from')->value() ?: null, $request->string('to')->value() ?: null);
        $entries = $this->reports->journalReport($from, $to);
        $l = $this->labels();
        $currency = $this->currency();
        $ar = app()->getLocale() === 'ar';

        $sections = $entries->map(function ($entry) use ($l, $currency, $ar) {
            $heading = $entry['date'].' — '.$entry['memo'].($entry['is_reversal'] ? ' ('.$l['reversal'].')' : '');

            return [
                'heading' => $heading,
                'columns' => [
                    ['label' => $l['account']], ['label' => $l['debit'], 'align' => 'end'], ['label' => $l['credit'], 'align' => 'end'],
                ],
                'rows' => collect($entry['lines'])->map(fn ($line) => [
                    ($ar && $line['account_name_ar']) ? $line['account_name_ar'] : $line['account_name'],
                    $line['debit'] > 0 ? $this->money($line['debit'], $currency) : '—',
                    $line['credit'] > 0 ? $this->money($line['credit'], $currency) : '—',
                ])->all(),
            ];
        })->all();

        $doc = $this->baseDoc($l['report_journal'], $from, $to, $sections);

        return $this->respond($format, $doc, 'journal-'.$from.'-to-'.$to);
    }

    // ── Shared helpers ──────────────────────────────────────────

    private function respond(string $format, array $doc, string $filename): Response
    {
        abort_unless(in_array($format, ['excel', 'pdf'], true), 404);

        return $format === 'excel'
            ? ExcelReportExporter::stream($doc, $filename)
            : PdfReportExporter::stream($doc, $filename);
    }

    /**
     * A statement's date window, read the same way
     * ReportController::statementRange() reads it — the export must
     * cover exactly the range the screen was showing when the button
     * was pressed, or the file and the page disagree.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function statementRange(Request $request): array
    {
        return [
            $request->string('from')->value() ?: null,
            $request->string('to')->value() ?: null,
        ];
    }

    private function baseDoc(string $title, ?string $from, ?string $to, array $sections): array
    {
        $l = $this->labels();
        $rtl = app()->getLocale() === 'ar';
        $company = auth()->user()?->company;
        $companyName = $company ? ($rtl && $company->name_ar ? $company->name_ar : $company->name) : '—';

        $subtitle = $from && $to ? "{$l['period']}: {$from} → {$to}" : null;

        return [
            'title'    => $title,
            'subtitle' => $subtitle,
            'rtl'      => $rtl,
            'meta'     => [
                ['label' => $l['company'], 'value' => $companyName],
                ['label' => $l['generated_at'], 'value' => now()->format('Y-m-d H:i')],
            ],
            'sections'    => $sections,
            'empty_label' => $l['no_data'],
            'footer'      => 'MaliyatDocs',
        ];
    }

    private function currency(): string
    {
        return auth()->user()?->company?->currency ?? 'EGP';
    }

    private function money(float $value, string $currency): string
    {
        return $currency.' '.number_format($value, 2);
    }

    private function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.') ?: '0';
    }

    /**
     * Small, self-contained bilingual label set for export files —
     * deliberately not shared with resources/js/lang/appTranslations.js
     * (this controller never touches the frontend bundle), just the
     * ~40 words a report file needs, in the user's current locale.
     */
    private function labels(): array
    {
        $ar = app()->getLocale() === 'ar';

        $en = [
            'company' => 'Company', 'generated_at' => 'Generated at', 'period' => 'Period', 'no_data' => 'No data for this period.',
            'date' => 'Date', 'type' => 'Type', 'party' => 'Party', 'amount' => 'Amount', 'balance' => 'Balance', 'status' => 'Status',
            'reference' => 'Reference', 'debit' => 'Debit', 'credit' => 'Credit', 'paid' => 'Paid', 'owed' => 'Owed',
            'running_balance' => 'Running balance', 'total_balance' => 'Total balance',
            'item' => 'Item', 'category' => 'Category', 'qty' => 'Quantity', 'unit_price' => 'Unit price', 'stock_after' => 'Stock after',
            'total_purchased' => 'Total purchased', 'total_sold' => 'Total sold', 'current_stock' => 'Current stock',
            'total_in' => 'Total received', 'total_out' => 'Total issued',
            'avg_cost' => 'Avg. cost / unit', 'stock_value' => 'Stock value', 'total_stock_value' => 'Total stock value',
            'revenue' => 'Revenue', 'cost_of_goods_sold' => 'Cost of goods sold', 'gross_profit' => 'Gross profit',
            'operating_expenses' => 'Operating expenses', 'net_profit' => 'Net profit',
            'income_by_item' => 'Income by item', 'expenses_by_category' => 'Expenses by category',
            'cash_in' => 'Cash in', 'cash_out' => 'Cash out', 'net_flow' => 'Net flow', 'by_method' => 'By payment method',
            'movements' => 'Movements', 'method' => 'Method', 'direction' => 'Direction',
            'balance_brought_forward' => 'Balance brought forward',
            'method_cash' => 'Cash', 'method_bank' => 'Bank', 'method_visa' => 'Visa', 'method_instapay' => 'InstaPay', 'method_wallet' => 'Electronic wallet',
            'status_paid' => 'Paid', 'status_partial' => 'Partial', 'status_unpaid' => 'Unpaid',
            'type_sale' => 'Sale', 'type_expense' => 'Expense', 'type_inventory_purchase' => 'Inventory purchase', 'type_equipment_purchase' => 'Equipment purchase',
            'type_produced' => 'Produced', 'type_consumed' => 'Consumed',
            'report_ledger' => 'All Entries', 'report_pl' => 'Profit & Loss', 'report_customer_statement' => 'Customer Statement',
            'report_supplier_statement' => 'Supplier Statement', 'report_inventory_statement' => 'Inventory Statement', 'report_cashflow' => 'Cash Flow',
            'report_trial_balance' => 'Trial Balance', 'report_journal' => 'Journal',
            'as_of' => 'as of', 'code' => 'Code', 'account' => 'Account', 'reversal' => 'Reversal',
        ];

        if (! $ar) {
            return $en;
        }

        return [
            'company' => 'الشركة', 'generated_at' => 'تاريخ الإصدار', 'period' => 'الفترة', 'no_data' => 'لا توجد بيانات لهذه الفترة.',
            'date' => 'التاريخ', 'type' => 'النوع', 'party' => 'الطرف', 'amount' => 'المبلغ', 'balance' => 'الرصيد', 'status' => 'الحالة',
            'reference' => 'المرجع', 'debit' => 'مدين', 'credit' => 'دائن', 'paid' => 'مدفوع', 'owed' => 'مستحق',
            'running_balance' => 'الرصيد التراكمي', 'total_balance' => 'إجمالي الرصيد',
            'item' => 'الصنف', 'category' => 'الفئة', 'qty' => 'الكمية', 'unit_price' => 'سعر الوحدة', 'stock_after' => 'الرصيد بعد الحركة',
            'total_purchased' => 'إجمالي المُشترى', 'total_sold' => 'إجمالي المُباع', 'current_stock' => 'المخزون الحالي',
            'total_in' => 'إجمالي الوارد', 'total_out' => 'إجمالي المنصرف',
            'avg_cost' => 'متوسط تكلفة الوحدة', 'stock_value' => 'قيمة المخزون', 'total_stock_value' => 'إجمالي قيمة المخزون',
            'revenue' => 'الإيرادات', 'cost_of_goods_sold' => 'تكلفة البضاعة المباعة', 'gross_profit' => 'مجمل الربح',
            'operating_expenses' => 'المصروفات التشغيلية', 'net_profit' => 'صافي الربح',
            'income_by_item' => 'الإيرادات حسب الصنف', 'expenses_by_category' => 'المصروفات حسب الفئة',
            'cash_in' => 'النقد الداخل', 'cash_out' => 'النقد الخارج', 'net_flow' => 'صافي التدفق', 'by_method' => 'حسب طريقة الدفع',
            'movements' => 'الحركات', 'method' => 'الطريقة', 'direction' => 'الاتجاه',
            'balance_brought_forward' => 'رصيد مُرحَّل',
            'method_cash' => 'نقدي', 'method_bank' => 'بنك', 'method_visa' => 'فيزا', 'method_instapay' => 'إنستاباي', 'method_wallet' => 'محفظة إلكترونية',
            'status_paid' => 'مدفوع', 'status_partial' => 'مدفوع جزئياً', 'status_unpaid' => 'غير مدفوع',
            'type_sale' => 'بيع', 'type_expense' => 'مصروف', 'type_inventory_purchase' => 'شراء مخزون', 'type_equipment_purchase' => 'شراء معدات',
            'type_produced' => 'إنتاج', 'type_consumed' => 'استهلاك',
            'report_ledger' => 'كل القيود', 'report_pl' => 'الأرباح والخسائر', 'report_customer_statement' => 'كشف حساب عميل',
            'report_supplier_statement' => 'كشف حساب مورد', 'report_inventory_statement' => 'كشف حساب المخزون', 'report_cashflow' => 'التدفق النقدي',
            'report_trial_balance' => 'ميزان المراجعة', 'report_journal' => 'دفتر اليومية',
            'as_of' => 'حتى تاريخ', 'code' => 'الرمز', 'account' => 'الحساب', 'reversal' => 'عكس قيد',
        ];
    }
}
