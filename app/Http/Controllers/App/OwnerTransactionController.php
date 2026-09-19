<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreOwnerTransactionRequest;
use App\Http\Requests\App\UpdateOwnerTransactionRequest;
use App\Models\Owner;
use App\Models\OwnerTransaction;
use App\Models\PaymentChannel;
use App\Services\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — OwnerTransactionController
//  Location: app/Http/Controllers/App/OwnerTransactionController.php
//
//  "Receive {amount} from {owner}" / "Pay {amount} to {owner}" — a
//  single-step cash movement, closest in shape to Custody's "give"
//  (CustodyController::store()/update()): one Payment row for cash
//  figures, one journal entry posted directly against the record
//  itself, both committed together, both unwound together on edit
//  or delete. No settle-later step exists here the way Custody has
//  one — see OwnerTransaction's own doc comment for why.
// ══════════════════════════════════════════════════════════════════
class OwnerTransactionController extends Controller
{
    public function __construct(
        private readonly JournalService $journal,
    ) {}

    public function index(): Response
    {
        $transactions = OwnerTransaction::query()
            ->with('owner:id,name')
            ->latest('date')
            ->latest('id')
            ->paginate(20)
            ->through(fn (OwnerTransaction $tx) => [
                'id'                 => $tx->id,
                'owner_id'           => $tx->owner_id,
                'owner'              => $tx->owner?->name,
                'direction'          => $tx->direction,
                'category'           => $tx->category,
                'amount'             => (float) $tx->amount,
                'method'             => $tx->method,
                'payment_channel_id' => $tx->payment_channel_id,
                'date'               => $tx->date->toDateString(),
                'note'               => $tx->note,
            ]);

        return Inertia::render('App/OwnerTransactions/Index', [
            'owners'          => Owner::query()->orderBy('name')->get(['id', 'name']),
            'paymentChannels' => PaymentChannel::query()->orderBy('name')->get(['id', 'name']),
            'transactions'    => $transactions,
        ]);
    }

    public function store(StoreOwnerTransactionRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // The transaction row, the cash that moved with it and the
        // ledger entry are one business fact — committed together or
        // not at all, same reasoning as CustodyController::store().
        DB::transaction(function () use ($data) {
            $transaction = OwnerTransaction::create([
                'owner_id'           => $data['owner_id'],
                'direction'          => $data['direction'],
                'category'           => $data['category'],
                'amount'             => $data['amount'],
                'method'             => $data['method'] ?? 'cash',
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
                'date'               => $data['date'],
                'note'               => $data['note'] ?? null,
                'created_by'         => Auth::id(),
            ]);

            $transaction->payments()->create([
                'company_id'         => $transaction->company_id,
                'date'               => $data['date'],
                'amount'             => $data['amount'],
                'method'             => $data['method'] ?? 'cash',
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
                'direction'          => $data['direction'],
            ]);

            $this->journal->postOwnerTransaction($transaction);
        });

        return back()->with('success', 'Owner transaction recorded.');
    }

    public function update(UpdateOwnerTransactionRequest $request, OwnerTransaction $ownerTransaction): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($ownerTransaction, $data) {
            $this->journal->reverseEntriesFor($ownerTransaction);
            $ownerTransaction->payments()->delete();

            $ownerTransaction->update([
                'owner_id'           => $data['owner_id'],
                'direction'          => $data['direction'],
                'category'           => $data['category'],
                'amount'             => $data['amount'],
                'method'             => $data['method'] ?? 'cash',
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
                'date'               => $data['date'],
                'note'               => $data['note'] ?? null,
            ]);

            $ownerTransaction->payments()->create([
                'company_id'         => $ownerTransaction->company_id,
                'date'               => $data['date'],
                'amount'             => $data['amount'],
                'method'             => $data['method'] ?? 'cash',
                'payment_channel_id' => $data['payment_channel_id'] ?? null,
                'direction'          => $data['direction'],
            ]);

            $this->journal->postOwnerTransaction($ownerTransaction->fresh());
        });

        return back()->with('success', 'Owner transaction updated.');
    }

    public function destroy(OwnerTransaction $ownerTransaction): RedirectResponse
    {
        $this->authorizeDelete();

        DB::transaction(function () use ($ownerTransaction) {
            $this->logDeletion(
                $ownerTransaction,
                "Owner transaction #{$ownerTransaction->id} — ".($ownerTransaction->owner?->name ?? 'Unknown owner').' — '.number_format((float) $ownerTransaction->amount, 2)
            );

            $this->journal->reverseAllForPayable($ownerTransaction);
            $ownerTransaction->payments()->delete();
            $ownerTransaction->delete();
        });

        return back()->with('success', 'Owner transaction deleted.');
    }
}
