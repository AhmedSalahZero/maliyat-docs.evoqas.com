<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'phone'];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * The one reusable customer a "Cash Sale" (Sales tab) is filed
     * under, so the person recording it is never asked to pick a
     * customer — created once per company, on first use, and reused
     * from then on via Company::cash_customer_id (not looked up by
     * name — see that column's migration for why).
     */
    public static function cashCustomer(int $companyId): self
    {
        $company = Company::find($companyId);

        if ($company?->cash_customer_id) {
            $existing = static::find($company->cash_customer_id);
            if ($existing) {
                return $existing;
            }
        }

        $customer = static::create(['company_id' => $companyId, 'name' => 'Cash Customer']);

        $company?->forceFill(['cash_customer_id' => $customer->id])->save();

        return $customer;
    }

    /**
     * Receipts logged against this customer with no invoice behind
     * them — Receive Money's "or log a generic receipt", tagged with
     * a customer. They are cash this customer actually handed over,
     * so the statement has to show them; without this relation the
     * statement only ever saw payments reached through a Sale, and
     * a tagged receipt was invisible to the one report it was tagged
     * for. See ReportDataService::customerStatement().
     */
    public function standalonePayments(): HasMany
    {
        return $this->hasMany(Payment::class)->whereNull('payable_type');
    }
}