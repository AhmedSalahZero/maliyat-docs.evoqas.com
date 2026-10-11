<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Vendor;
use App\Models\User;
use App\Support\NameNormalizer;
use Illuminate\Console\Command;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — names:duplicates
//
//  READ-ONLY report of customers, suppliers/employees and team
//  members that share a name inside the same company (spaces and
//  capitals ignored). Nothing is changed: whether two records are
//  really the same party — and which one should survive — is a
//  business decision, because each may already carry invoices and
//  payments. The owner reviews the list and renames one of each pair.
//
//      php artisan names:duplicates
// ══════════════════════════════════════════════════════════════════
class ListDuplicateNames extends Command
{
    protected $signature = 'names:duplicates';

    protected $description = 'List customers, suppliers/employees and team members that share a name within one company (read-only)';

    public function handle(): int
    {
        $found = 0;

        foreach ([
            'Customer'            => Customer::class,
            'Supplier / employee' => Vendor::class,
            'Team member'         => User::class,
        ] as $label => $model) {
            $groups = $model::query()
                ->withoutGlobalScopes()
                ->whereNotNull('company_id')
                ->get(['id', 'company_id', 'name'])
                ->groupBy(fn ($row) => $row->company_id.'|'.NameNormalizer::key($row->name))
                ->filter(fn ($rows) => $rows->count() > 1);

            foreach ($groups as $rows) {
                $found++;
                $this->line(sprintf(
                    '%s — company #%d — "%s" appears %d times (ids: %s)',
                    $label,
                    $rows->first()->company_id,
                    NameNormalizer::clean($rows->first()->name),
                    $rows->count(),
                    $rows->pluck('id')->implode(', ')
                ));
            }
        }

        $this->info($found === 0 ? 'No duplicate names found.' : "{$found} duplicate name group(s) found. Rename one of each pair from the Customers / Suppliers / Team screen.");

        return self::SUCCESS;
    }
}
