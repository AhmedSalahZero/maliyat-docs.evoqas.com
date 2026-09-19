<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — OwnerTransaction
//  "Receive money from owner" / "Pay money to owner" — see the
//  owner_transactions table migration's doc comment for the full
//  direction/category shape and why it's a single-step record
//  rather than an invoice+payment or given+settle pair.
// ══════════════════════════════════════════════════════════════════
class OwnerTransaction extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id', 'owner_id', 'direction', 'category',
        'amount', 'method', 'payment_channel_id', 'date', 'note', 'created_by',
    ];

    // decimal(12,2) in the database — cast explicitly for the same
    // reason every other money column in this app is (see Payment's
    // own doc comment on this).
    protected $casts = [
        'date'   => 'date',
        'amount' => 'decimal:2',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function paymentChannel(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class);
    }

    /**
     * The cash movement itself — kept in the shared `payments` table
     * (same polymorphic relation Sale/Expense/InventoryPurchase/
     * EquipmentPurchase/Custody all use) purely so this transaction
     * is counted correctly wherever the app reads cash from that
     * table directly rather than from the journal — DashboardController
     * ::cashFigures() and ReportDataService::cashFlow(), both of
     * which query `payments` with no idea OwnerTransaction exists.
     * The actual accounting entry is posted separately, straight
     * against this model — see JournalService::postOwnerTransaction().
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * True for the two "money coming in" categories — used wherever
     * code needs to check the category belongs to direction=in
     * without hardcoding the pair of strings twice (validation,
     * statement grouping).
     */
    public static function inCategories(): array
    {
        return ['capital_injection', 'repay_withdrawal'];
    }

    /**
     * True for the two "money going out" categories.
     */
    public static function outCategories(): array
    {
        return ['withdrawal', 'profit_distribution'];
    }
}
