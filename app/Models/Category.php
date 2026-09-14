<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'name_ar', 'kind', 'default_useful_life_years'];

    // Every equipment category the user adds themselves gets this —
    // "if user add any new item make it 5 years". Entirely invisible
    // in the UI; only ever read by the depreciation command.
    public const DEFAULT_EQUIPMENT_USEFUL_LIFE_YEARS = 5;

    public function scopeExpenseKind($query)
    {
        return $query->where('kind', 'expense');
    }

    public function scopeEquipmentKind($query)
    {
        return $query->where('kind', 'equipment');
    }

    /**
     * The starter category list every new company gets, so the
     * "for {category}" combo isn't empty on day one — same names as
     * ledger-prototype-v8.html's defaultCategories()/
     * defaultEquipmentCategories(). Safe to call more than once:
     * skips names that already exist for that company+kind.
     *
     * The equipment categories also get their depreciation useful
     * life seeded here (invisible to the user, read only by the
     * depreciation command) — Machine 10yr, Vehicle 5yr, Mobile/
     * Computer 3yr, Furniture 5yr, Other 5yr.
     */
    public static function seedDefaults(int $companyId): void
    {
        $expense = [
            ['Software', 'برمجيات'], ['Travel', 'سفر'], ['Meals', 'مأكولات'],
            ['Office Supplies', 'مستلزمات مكتبية'], ['Consulting', 'استشارات'],
            ['Rent', 'إيجار'], ['Utilities', 'مرافق'], ['Salaries', 'رواتب'], ['Other', 'أخرى'],
        ];

        // [name, name_ar, useful_life_years]
        $equipment = [
            ['Machine', 'ماكينة', 10],
            ['Vehicle', 'سيارة', 5],
            ['Mobile/Computer', 'موبايل/كمبيوتر', 3],
            ['Furniture', 'أثاث', 5],
            ['Other', 'أخرى', self::DEFAULT_EQUIPMENT_USEFUL_LIFE_YEARS],
        ];

        foreach ($expense as [$name, $nameAr]) {
            self::query()->firstOrCreate(
                ['company_id' => $companyId, 'kind' => 'expense', 'name' => $name],
                ['name_ar' => $nameAr],
            );
        }

        foreach ($equipment as [$name, $nameAr, $years]) {
            self::query()->firstOrCreate(
                ['company_id' => $companyId, 'kind' => 'equipment', 'name' => $name],
                ['name_ar' => $nameAr, 'default_useful_life_years' => $years],
            );
        }
    }
}
