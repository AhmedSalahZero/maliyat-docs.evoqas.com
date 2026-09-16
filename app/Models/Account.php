<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory, BelongsToCompany;

    // ── Standard chart of accounts — every company gets these on
    // creation (see JournalService::seedChartOfAccounts()). Referenced
    // by code throughout JournalService rather than by numeric id,
    // since ids differ per company but codes don't. ──────────────
    public const CASH            = '1000';
    public const BANK            = '1010';
    public const ACCOUNTS_RECEIVABLE = '1100';
    public const VAT_RECEIVABLE  = '1150';
    public const INVENTORY_ASSET = '1200';
    public const EQUIPMENT_ASSET = '1300';
    public const ACCUMULATED_DEPRECIATION = '1310';
    public const CUSTODY_ADVANCES = '1400';
    public const ACCOUNTS_PAYABLE = '2000';
    public const VAT_PAYABLE     = '2100';
    // Production Labor Accrued — a clearing account. Production
    // Orders credit it (labor cost applied to inventory value);
    // the real payroll Expense (checked "Production Labor") debits
    // it back off. See JournalService::postProductionOrder() /
    // postProductionLaborExpense().
    public const PRODUCTION_LABOR_ACCRUED = '2200';
    public const OWNERS_EQUITY   = '3000';
    public const RETAINED_EARNINGS = '3900';
    public const SALES_REVENUE   = '4000';
    public const COST_OF_GOODS_SOLD = '5000';
    public const DEPRECIATION_EXPENSE = '5100';
    public const MISC_EXPENSE    = '5900';

    public const STANDARD_CODES = [
        self::CASH              => ['Cash on Hand',            'نقدية بالصندوق',       'asset'],
        self::BANK              => ['Bank Account',            'حساب بنكي',            'asset'],
        self::ACCOUNTS_RECEIVABLE => ['Accounts Receivable',   'ذمم مدينة (عملاء)',    'asset'],
        self::VAT_RECEIVABLE    => ['VAT Receivable (Input)',  'ضريبة مدخلات مستحقة',  'asset'],
        self::INVENTORY_ASSET   => ['Inventory (Stock)',       'مخزون البضاعة',        'asset'],
        self::EQUIPMENT_ASSET   => ['Equipment & Vehicles',    'معدات ومركبات',        'asset'],
        self::ACCUMULATED_DEPRECIATION => ['Accumulated Depreciation', 'مجمع الإهلاك', 'asset'],
        self::CUSTODY_ADVANCES  => ['Custody Advances',        'عهد نقدية',            'asset'],
        self::ACCOUNTS_PAYABLE  => ['Accounts Payable',        'ذمم دائنة (موردون)',   'liability'],
        self::VAT_PAYABLE       => ['VAT Payable (Output)',    'ضريبة مخرجات مستحقة',  'liability'],
        self::PRODUCTION_LABOR_ACCRUED => ['Production Labor Accrued', 'عمالة إنتاج مستحقة', 'liability'],
        self::OWNERS_EQUITY     => ["Owner's Equity",          'حقوق الملكية',         'equity'],
        self::RETAINED_EARNINGS => ['Retained Earnings',       'الأرباح المحتجزة',     'equity'],
        self::SALES_REVENUE     => ['Sales Revenue',           'إيرادات المبيعات',     'income'],
        self::COST_OF_GOODS_SOLD => ['Cost of Goods Sold',     'تكلفة البضاعة المباعة', 'expense'],
        self::DEPRECIATION_EXPENSE => ['Depreciation Expense', 'مصروف الإهلاك',        'expense'],
        self::MISC_EXPENSE      => ['General / Miscellaneous Expense', 'مصروفات عمومية', 'expense'],
    ];

    protected $fillable = ['company_id', 'code', 'name', 'name_ar', 'type'];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }
}
