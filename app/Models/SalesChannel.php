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
     * isn't empty on day one. Safe to call more than once — skips
     * names that already exist for that company (same idempotent
     * shape as Category::seedDefaults()).
     */
    public static function seedDefaults(int $companyId): void
    {
        $defaults = [
            ['Direct Sales', 'بيع مباشر'],
            ['Delivery Sales', 'بيع ديليفري'],
            ['Online Sales', 'بيع عبر المنصات'],
            ['WhatsApp Sales', 'جروبات الواتساب'],
        ];

        foreach ($defaults as [$name, $nameAr]) {
            self::query()->firstOrCreate(
                ['company_id' => $companyId, 'name' => $name],
                ['name_ar' => $nameAr],
            );
        }
    }

    /**
     * "Direct Sales" is the default every new sale is filed under
     * when nothing else is picked — mirrors Customer::cashCustomer():
     * the create form always sends one in practice, but a document
     * still has to land somewhere sensible if it's ever missing
     * (e.g. an older integration, or a request built by hand).
     * firstOrCreate so this never fails even if seedDefaults()
     * somehow hasn't run yet for this company.
     */
    public static function defaultChannel(int $companyId): self
    {
        return self::query()->firstOrCreate(
            ['company_id' => $companyId, 'name' => 'Direct Sales'],
            ['name_ar' => 'بيع مباشر'],
        );
    }
}
