<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'type'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function inventoryPurchases(): HasMany
    {
        return $this->hasMany(InventoryPurchase::class);
    }

    public function equipmentPurchases(): HasMany
    {
        return $this->hasMany(EquipmentPurchase::class);
    }

    /**
     * Custodies held by this vendor/employee.
     */
    public function custodies(): HasMany
    {
        return $this->hasMany(Custody::class, 'holder_id');
    }

    /**
     * Payments logged against this supplier with no bill behind them
     * — Pay Money's "or log a generic payment", tagged with a
     * vendor. Same reasoning as Customer::standalonePayments():
     * money that really moved between the company and this supplier
     * belongs on their statement whether or not a bill was raised
     * for it first.
     */
    public function standalonePayments(): HasMany
    {
        return $this->hasMany(Payment::class)->whereNull('payable_type');
    }
}
