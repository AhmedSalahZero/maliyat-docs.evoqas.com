<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreEquipmentPurchaseRequest;
use App\Http\Requests\App\UpdateEquipmentPurchaseRequest;
use App\Models\Category;
use App\Models\EquipmentPurchase;
use App\Models\PaymentChannel;
use App\Models\Vendor;
use App\Services\JournalService;
use App\Services\PaymentRecorderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — EquipmentPurchaseController
//  Location: app/Http/Controllers/App/EquipmentPurchaseController.php
//
//  "Buy from {vendor}: {type} {name} x{qty} @ {price} for {total},
//   paid {mode}."
//
//  DEPRECIATION — entirely invisible here and in the UI. Every
//  purchase snapshots a useful_life_years figure from its category
//  (Category::default_useful_life_years — Machine 10yr, Vehicle
//  5yr, Mobile/Computer 3yr, Furniture 5yr, anything else 5yr) at
//  the moment it's bought, so a later change to the category's
//  default never retroactively changes an already-purchased
//  asset's depreciation. The actual monthly posting is a separate
//  scheduled process — see app/Console/Commands/RunDepreciation.php.
//  Nothing about useful life, accumulated depreciation, or a
//  depreciation expense is ever shown to the user.
//
//  Edit/Delete follow the same pattern as Sales/Expenses/Inventory
//  — see SaleController's class doc comment. Editing here also
//  resets the depreciation clock (see update()) since the asset's
//  cost/useful-life basis just changed.
// ══════════════════════════════════════════════════════════════════
class EquipmentPurchaseController extends Controller
{
    public function __construct(
        private readonly PaymentRecorderService $paymentRecorder,
        private readonly JournalService $journal,
    ) {}

    public function index(): Response
    {
        // Self-heal for companies created before the default category
        // list (and its useful-life years) existed.
        Category::seedDefaults(auth()->user()->company_id);

        $purchases = EquipmentPurchase::query()
            ->with(['vendor:id,name', 'category:id,name', 'payments'])
            ->latest('date')
            ->latest('id')
            ->paginate(20)
            ->through(fn (EquipmentPurchase $purchase) => [
                'id'             => $purchase->id,
                'date'           => $purchase->date->toDateString(),
                'due_date'    => $purchase->due_date?->toDateString(),
                'vendor_id'      => $purchase->vendor_id,
                'vendor'         => $purchase->vendor?->name,
                'category_id'    => $purchase->category_id,
                'category'       => $purchase->category?->name,
                'name'           => $purchase->name,
                'qty'            => (float) $purchase->qty,
                'unit_price'     => (float) $purchase->unit_price,
                'amount'         => (float) $purchase->amount,
                'paid_amount'    => $purchase->paidAmount(),
                'balance'        => $purchase->balance(),
                'is_paid'        => $purchase->isPaid(),
                'payments_count' => $purchase->payments->count(),
                // Individual payments so the edit view can show and
                // remove them — see PaymentController::destroy().
                'payments'       => $purchase->payments->map(fn ($payment) => [
                    'id'     => $payment->id,
                    'date'   => $payment->date->toDateString(),
                    'amount' => (float) $payment->amount,
                    'method' => $payment->method,
                    // Needed so the edit form can prefill the bank /
                    // operator the payment actually went through.
                    'payment_channel_id' => $payment->payment_channel_id,
                ])->values(),
            ]);

        return Inertia::render('App/EquipmentPurchases/Index', [
            'vendors'         => Vendor::query()->orderBy('name')->get(['id', 'name']),
            'categories'      => Category::query()->equipmentKind()->orderBy('name')->get(['id', 'name', 'name_ar']),
            'paymentChannels' => PaymentChannel::query()->orderBy('name')->get(['id', 'name']),
            'purchases'       => $purchases,
        ]);
    }

    public function store(StoreEquipmentPurchaseRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $qty    = (float) ($data['qty'] ?? 1);
        $amount = round($qty * $data['unit_price'], 2);

        $dueDate  = null;
        $schedule = [];

        if ($data['mode'] === 'installment') {
            $schedule = $this->paymentRecorder->buildInstallmentSchedule(
                $amount, $data['date'], (int) $data['installment_count'], (int) $data['installment_interval_days']
            );
            $dueDate = $schedule[0]['due_date'];
        } elseif ($data['mode'] !== 'now') {
            $dueDate = $this->paymentRecorder->dueDate($data['due_date'] ?? null, $data['due_in_days'] ?? null, $data['date']);
        }

        // Had no transaction either — same fix as
        // ExpenseController::store().
        DB::transaction(function () use ($data, $qty, $amount, $dueDate, $schedule) {
            $purchase = EquipmentPurchase::create([
                'vendor_id'         => $data['vendor_id'],
                'category_id'       => $data['category_id'],
                'name'              => $data['name'],
                'qty'               => $qty,
                'unit_price'        => $data['unit_price'],
                'amount'            => $amount,
                'date'              => $data['date'],
                'due_date'          => $dueDate,
                'useful_life_years' => $this->usefulLifeFor($data['category_id']),
                'created_by'        => auth()->id(),
            ]);

            $this->journal->postEquipmentPurchaseInvoice($purchase);

            $payment = $this->paymentRecorder->apply($purchase, 'out', $data['mode'], [
                'date'       => $data['date'],
                'method'     => $data['method'] ?? 'cash',
                'amount_now' => $data['amount_now'] ?? null,
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
            ], $schedule);

            if ($payment) {
                $this->journal->postBillPayment($payment);
            }
        });

        return back()->with('success', 'Equipment purchase recorded.');
    }

    /**
     * Edit an existing purchase. Because the cost and/or category
     * (and therefore useful life) can change, this resets the
     * depreciation clock — any depreciation already posted is
     * reversed (reverseEntriesFor() catches it since it shares
     * source = this purchase, same as the invoice entry) and
     * accumulated_depreciation/last_depreciated_through are cleared,
     * so RunDepreciation recomputes cleanly from the new figures on
     * its next run. Payments are left untouched, as elsewhere.
     */
    public function update(UpdateEquipmentPurchaseRequest $request, EquipmentPurchase $equipmentPurchase): RedirectResponse
    {
        $data = $request->validated();

        $qty    = (float) ($data['qty'] ?? 1);
        $amount = round($qty * $data['unit_price'], 2);

        DB::transaction(function () use ($equipmentPurchase, $data, $qty, $amount) {
            $this->journal->reverseEntriesFor($equipmentPurchase);

            $equipmentPurchase->update([
                'vendor_id'                => $data['vendor_id'],
                'category_id'              => $data['category_id'],
                'name'                     => $data['name'],
                'qty'                      => $qty,
                'unit_price'               => $data['unit_price'],
                'amount'                   => $amount,
                'date'                     => $data['date'],
                // Correctable now — see the note in the Update*Request.
                'due_date'                 => array_key_exists('due_date', $data) ? $data['due_date'] : $equipmentPurchase->due_date,
                'useful_life_years'        => $this->usefulLifeFor($data['category_id']),
                'accumulated_depreciation' => 0,
                'last_depreciated_through' => null,
            ]);

            $this->journal->postEquipmentPurchaseInvoice($equipmentPurchase->fresh());
        });

        return back()->with('success', 'Equipment purchase updated.');
    }

    public function destroy(EquipmentPurchase $equipmentPurchase): RedirectResponse
    {
        $this->authorizeDelete();

        DB::transaction(function () use ($equipmentPurchase) {
            $this->journal->reverseAllForPayable($equipmentPurchase);
            $equipmentPurchase->payments()->delete();
            $equipmentPurchase->installments()->delete();
            $equipmentPurchase->delete();
        });

        return back()->with('success', 'Equipment purchase deleted.');
    }

    private function usefulLifeFor(int $categoryId): int
    {
        return Category::find($categoryId)?->default_useful_life_years
            ?? Category::DEFAULT_EQUIPMENT_USEFUL_LIFE_YEARS;
    }
}
