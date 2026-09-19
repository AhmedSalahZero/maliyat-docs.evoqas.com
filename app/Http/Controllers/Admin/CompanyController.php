<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Models\Category;
use App\Models\Company;
use App\Models\Custody;
use App\Models\EquipmentPurchase;
use App\Models\Expense;
use App\Models\InventoryPurchase;
use App\Models\ProductionOrder;
use App\Models\Sale;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — CompanyController (super_admin side)
//  Location: app/Http/Controllers/Admin/CompanyController.php
//
//  A super_admin's view of every company on the platform. Most
//  companies arrive via public self-signup (RegisterService) — this
//  controller is the manual alternative (e.g. onboarding a company
//  over the phone) plus ongoing management (deactivate a company
//  that stopped paying, etc.).
// ══════════════════════════════════════════════════════════════════
class CompanyController extends Controller
{
    public function __construct(
        private readonly JournalService $journal,
    ) {}

    public function index(): Response
    {
        $companies = Company::query()
            ->withCount(['users'])
            ->orderByDesc('id')
            ->paginate(30)
            ->through(fn (Company $company) => [
                'id'         => $company->id,
                'name'       => $company->name,
                'name_ar'    => $company->name_ar,
                'currency'   => $company->currency,
                'is_active'  => $company->is_active,
                'users_count'=> $company->users_count,
                'created_at' => $company->created_at->toDateString(),
            ]);

        return Inertia::render('Admin/Companies/Index', ['companies' => $companies]);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $company = DB::transaction(function () use ($data) {
            $company = Company::create([
                'name'     => $data['name'],
                'name_ar'  => $data['name_ar'] ?? null,
                'currency' => $data['currency'] ?? 'EGP',
                'business_types' => $data['business_types'] ?? ['trading'],
            ]);

            $admin = User::create([
                'name'       => $data['admin_name'],
                'email'      => $data['admin_email'],
                'password'   => $data['admin_password'],
                'role'       => UserRole::CompanyAdmin->value,
                'company_id' => $company->id,
                'created_by' => auth()->id(),
            ]);

            $company->forceFill(['created_by' => $admin->id])->save();

            return $company;
        });

        $this->journal->seedChartOfAccounts($company);
        Category::seedDefaults($company->id);
        SalesChannel::seedDefaults($company->id);

        return back()->with('success', 'Company created.');
    }

    public function toggleActive(Company $company): RedirectResponse
    {
        $company->update(['is_active' => ! $company->is_active]);

        return back()->with('success', $company->is_active ? 'Company reactivated.' : 'Company deactivated.');
    }

    /**
     * Permanently delete a company and EVERYTHING it owns — sales,
     * expenses, inventory, equipment, custodies, payments,
     * installments, the full chart of accounts and general ledger,
     * opening balances, and its own users. This cannot be undone.
     *
     * Most company-owned tables have company_id set to
     * cascadeOnDelete() (see the migrations under
     * database/migrations), so deleting the Company row alone would
     * remove most of that automatically. But six tables —
     * sales, expenses, inventory_purchases, equipment_purchases,
     * production_orders, and custodies — hold RESTRICT (not cascade)
     * foreign keys into customers/vendors/categories/items, by
     * design: it's what stops someone deleting a vendor from the
     * ordinary company-side UI while a real invoice still points at
     * it. That protection means MySQL refuses to cascade-delete a
     * customer/vendor/category/item while one of those six still
     * references it — even though that referencing row is itself
     * about to be removed a moment later by the very same company_id
     * cascade. (This is exactly the
     * "equipment_purchases_category_id_foreign ... ON DELETE
     * RESTRICT" error this endpoint used to throw.)
     *
     * So those six are deleted explicitly, first — each one cascades
     * its own line items automatically (sale_lines,
     * inventory_purchase_lines, production order lines,
     * custody_settlement_lines all cascade on their parent's id).
     * Once they're gone, nothing restricts customers, vendors,
     * categories or items anymore, and the company_id cascade that
     * follows can remove everything else cleanly: those four,
     * accounts, journal_entries(+lines), payments, installments,
     * payment_channels, opening_balances, and this company's own
     * deletion_logs rows.
     *
     * users.company_id is a separate exception: it's nullOnDelete
     * (see 2026_09_14_000002_add_company_fields_to_users_table.php),
     * so a company_admin/employee would otherwise survive the
     * company's deletion with company_id = null, which isn't a valid
     * state for those roles (see User comment: only super_admin has
     * a null company_id) and would leave orphaned, unreachable
     * accounts behind. So this company's users are deleted
     * explicitly too — which also cascades their login activity and
     * email verification rows (both cascadeOnDelete on user_id).
     *
     * This is deliberately NOT run through DeletionLogger/
     * deletion_logs: that table is itself scoped by company_id with
     * cascadeOnDelete, so a row logged there would be destroyed by
     * the very deletion it was meant to record. A platform-level
     * event like this is written to the application log instead,
     * which survives independently of any one company's data.
     */
    public function destroy(Company $company): RedirectResponse
    {
        $snapshot = [
            'company_id'    => $company->id,
            'company_name'  => $company->name,
            'currency'      => $company->currency,
            'users_count'   => $company->users()->count(),
            'created_at'    => $company->created_at?->toDateTimeString(),
            'deleted_by_id' => auth()->id(),
            'deleted_by'    => auth()->user()?->email,
            'deleted_at'    => now()->toDateTimeString(),
        ];

        DB::transaction(function () use ($company) {
            // Clear every RESTRICT-guarded reference into
            // customers/vendors/categories/items first — see the
            // docblock above for why this has to happen before the
            // company_id cascade below, not as part of it.
            Sale::where('company_id', $company->id)->delete();
            Expense::where('company_id', $company->id)->delete();
            InventoryPurchase::where('company_id', $company->id)->delete();
            EquipmentPurchase::where('company_id', $company->id)->delete();
            ProductionOrder::where('company_id', $company->id)->delete();
            Custody::where('company_id', $company->id)->delete();

            $company->users()->delete();
            $company->delete();
        });

        // Kept out of the database on purpose — see destroy()'s
        // docblock for why. This is the only durable record that
        // this company ever existed and was removed.
        Log::warning('admin.company_permanently_deleted', $snapshot);

        return redirect()
            ->route('admin.companies.index')
            ->with('success', "\"{$snapshot['company_name']}\" and all its data were permanently deleted.");
    }
}
