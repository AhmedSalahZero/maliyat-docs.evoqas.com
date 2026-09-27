<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — SalesChannel
//  Location: app/Models/SalesChannel.php
//
//  Where a sale actually came from — walk-in/Direct, Delivery, an
//  online marketplace, a WhatsApp group, or anything the company
//  adds itself via the "+ Add new…" option on the Sales form (see
//  SalesChannelController::store()). "Direct Sales" is the one
//  every new sale defaults to — see defaultChannel() below.
// ══════════════════════════════════════════════════════════════════
class SalesChannel extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'name_ar'];

    /**
     * The starter channel list every company gets, so the dropdown
     * isn't empty on day one.
     *
     * Runs only for a company that has NO channels yet. It used to
     * re-create any starter name it could not find — so a company that
     * renamed "Direct Sales" (or translated it) got a brand-new
     * "Direct Sales" back on the next visit to the Sales screen
     * (audit finding M11). Also makes sure the company's default
     * channel pointer is set.
     */
    public static function seedDefaults(int $companyId): void
    {
        if (! self::query()->where('company_id', $companyId)->exists()) {
            $defaults = [
                ['Direct Sales', 'بيع مباشر'],
                ['Delivery Sales', 'بيع ديليفري'],
                ['Online Sales', 'بيع عبر المنصات'],
                ['WhatsApp Sales', 'جروبات الواتساب'],
            ];

            foreach ($defaults as [$name, $nameAr]) {
                self::query()->create(['company_id' => $companyId, 'name' => $name, 'name_ar' => $nameAr]);
            }
        }

        self::defaultChannel($companyId);
    }

    /**
     * The channel every new sale is filed under when nothing else is
     * picked ("Direct Sales" to begin with). Found through
     * Company::default_sales_channel_id — the specific row, not its
     * name — so renaming or translating it changes nothing (audit
     * finding M11). Same shape as Customer::cashCustomer().
     */
    public static function defaultChannel(int $companyId): self
    {
        $company = Company::find($companyId);

        if ($company?->default_sales_channel_id) {
            $existing = self::query()->where('company_id', $companyId)->find($company->default_sales_channel_id);
            if ($existing) {
                return $existing;
            }
        }

        // No pointer yet (or its channel was deleted): adopt the
        // company's "Direct Sales" row, else its oldest channel, else
        // create one.
        $channel = self::query()->where('company_id', $companyId)->where('name', 'Direct Sales')->orderBy('id')->first()
            ?? self::query()->where('company_id', $companyId)->orderBy('id')->first()
            ?? self::query()->create(['company_id' => $companyId, 'name' => 'Direct Sales', 'name_ar' => 'بيع مباشر']);

        $company?->forceFill(['default_sales_channel_id' => $channel->id])->save();

        return $channel;
    }
}
