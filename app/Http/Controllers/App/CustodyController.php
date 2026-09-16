<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\SettleCustodyRequest;
use App\Http\Requests\App\StoreCustodyRequest;
use App\Http\Requests\App\UpdateCustodyRequest;
use App\Models\Category;
use App\Models\Custody;
use App\Models\PaymentChannel;
use App\Models\Vendor;
use App\Services\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — CustodyController
//  Location: app/Http/Controllers/App/CustodyController.php
//
//  "Give custody of {amount} to {holder}." — handing money to an
//  employee/vendor to spend on the business's behalf. Handing it
//  out is always an immediate cash-out (recorded as a Payment row
//  here); settling later records what it was actually spent on
//  (Custody::settle(), already on the model) and reconciles any
//  leftover returned to the business or extra reimbursed to them.
//
//  EDIT is only allowed before settlement — once settled, the
//  give+settle journal history is more involved to safely unwind
//  than the single-entry edits everywhere else, so rather than
//  build that now, editing a settled custody simply isn't offered.
//  DELETE works regardless of settled status (reverses everything
//  tied to it, same as every other page).
// ══════════════════════════════════════════════════════════════════
class CustodyController extends Controller
{
    public function __construct(
        private readonly JournalService $journal,
    ) {}

    public function index(): Response
    {
        $custodies = Custody::query()
            ->with('holder:id,name')
            ->with('settlementLines.category:id,name')
            ->latest('given_at')
            ->latest('id')
            ->paginate(20)
            ->through(fn (Custody $custody) => [
                'id'                => $custody->id,
                'holder_id'         => $custody->holder_id,
                'holder'            => $custody->holder?->name,
                'amount'            => (float) $custody->amount,
                'method'            => $custody->method,
                'payment_channel_id'=> $custody->payment_channel_id,
                'given_at'          => $custody->given_at->toDateString(),
                'settled'           => $custody->settled,
                'settlement_total'  => (float) $custody->settlement_total,
                'leftover_returned' => (float) $custody->leftover_returned,
                'extra_reimbursed'  => (float) $custody->extra_reimbursed,
                'settlement_lines'  => $custody->settlementLines->map(fn ($line) => [
                    'description' => $line->description,
                    'category'    => $line->category?->name,
                    'amount'      => (float) $line->amount,
                ]),
            ]);

        return Inertia::render('App/Custodies/Index', [
            'vendors'         => Vendor::query()->orderBy('name')->get(['id', 'name']),
            'categories'      => Category::query()->expenseKind()->orderBy('name')->get(['id', 'name', 'name_ar']),
            'paymentChannels' => PaymentChannel::query()->orderBy('name')->get(['id', 'name']),
            'custodies'       => $custodies,
        ]);
    }

    public function store(StoreCustodyRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // The custody row, the cash that left with it and the ledger
        // entry are one business fact — they commit together or not
        // at all. The posting used to sit after this block, so a
        // failure there left a custody on the books with nothing in
        // the general ledger. Same fix as SaleController::store() and
        // settle() below.
        DB::transaction(function () use ($data) {
            $custody = Custody::create([
                'holder_id'  => $data['holder_id'],
                'amount'     => $data['amount'],
                'method'     => $data['method'] ?? 'cash',
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
                'given_at'   => $data['given_at'],
                'created_by' => auth()->id(),
            ]);

            // Handing out custody is always an immediate cash movement.
            $custody->payments()->create([
                'company_id' => $custody->company_id,
                'date'       => $data['given_at'],
                'amount'     => $data['amount'],
                'method'     => $data['method'] ?? 'cash',
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
                'direction'  => 'out',
            ]);

            $this->journal->postCustodyGiven($custody);
        });

        return back()->with('success', 'Custody recorded.');
    }

    /**
     * Edit an unsettled custody's holder/amount/method/date. Not
     * available once settled — see class doc comment.
     */
    public function update(UpdateCustodyRequest $request, Custody $custody): RedirectResponse
    {
        abort_if($custody->settled, 422, 'A settled custody cannot be edited.');

        $data = $request->validated();

        DB::transaction(function () use ($custody, $data) {
            $this->journal->reverseEntriesFor($custody);
            $custody->payments()->delete();

            $custody->update([
                'holder_id'          => $data['holder_id'],
                'amount'             => $data['amount'],
                'method'             => $data['method'] ?? 'cash',
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
                'given_at'           => $data['given_at'],
            ]);

            $custody->payments()->create([
                'company_id' => $custody->company_id,
                'date'       => $data['given_at'],
                'amount'     => $data['amount'],
                'method'     => $data['method'] ?? 'cash',
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
                'direction'  => 'out',
            ]);

            $this->journal->postCustodyGiven($custody->fresh());
        });

        return back()->with('success', 'Custody updated.');
    }

    public function settle(SettleCustodyRequest $request, Custody $custody): RedirectResponse
    {
        $data = $request->validated();

        // The settlement rows, the cash that moved with them and the
        // ledger entry are one business fact — they commit together
        // or not at all. The posting used to sit outside this block,
        // which is the pattern SaleController::store() already moved
        // away from: a failure there left a settled custody with
        // nothing in the general ledger to show for it.
        DB::transaction(function () use ($custody, $data) {
            $custody->settle($data['lines'], $data['settlement_date']);
            $custody->refresh();

            if ($custody->leftover_returned > 0) {
                $custody->payments()->create([
                    'company_id' => $custody->company_id,
                    'date'       => $custody->settlement_date,
                    'amount'     => $custody->leftover_returned,
                    'method'     => $custody->method,
                    'direction'  => 'in',
                ]);
            }

            if ($custody->extra_reimbursed > 0) {
                $custody->payments()->create([
                    'company_id' => $custody->company_id,
                    'date'       => $custody->settlement_date,
                    'amount'     => $custody->extra_reimbursed,
                    'method'     => $custody->method,
                    'direction'  => 'out',
                ]);
            }

            $this->journal->postCustodySettlement($custody);
        });

        return back()->with('success', 'Custody settled.');
    }

    /**
     * Delete a custody entirely — the hand-out, any settlement, and
     * every payment tied to it. Works regardless of settled status.
     * The frontend has already warned the user what this removes.
     */
    public function destroy(Custody $custody): RedirectResponse
    {
        $this->authorizeDelete();

        DB::transaction(function () use ($custody) {
            $this->logDeletion(
                $custody,
                "Custody #{$custody->id} — ".($custody->holder?->name ?? 'Unknown holder').' — '.number_format((float) $custody->amount, 2),
                ['settlement_lines' => $custody->settlementLines->toArray()]
            );

            $this->journal->reverseAllForPayable($custody);
            $custody->settlementLines()->delete();
            $custody->payments()->delete();
            $custody->delete();
        });

        return back()->with('success', 'Custody deleted.');
    }
}
