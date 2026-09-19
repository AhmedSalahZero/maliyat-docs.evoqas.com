<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Company;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use App\Services\JournalService;
use App\Support\AuthVerification;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — RegisterService
//  Location: app/Services/RegisterService.php
//
//  Public sign-up (RegisteredUserController::store) is company
//  sign-up: the person filling the form becomes the first
//  company_admin of a brand-new Company. This is the manual
//  onboarding path's sibling — App\Http\Controllers\Admin\
//  CompanyController::store() does the exact same two-step
//  create (Company, then its admin User) for a super_admin
//  onboarding a company by hand; this is that same shape for
//  the public form instead.
// ══════════════════════════════════════════════════════════════════
class RegisterService
{
    public function __construct(
        private readonly EmailVerificationService $emailVerification,
        private readonly JournalService $journal,
    ) {}

    /**
     * @param  array{
     *     company_name:string, currency?:string|null,
     *     name:string, email:string, password:string, language?:string|null,
     * }  $data
     */
    public function register(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $company = Company::create([
                'name'     => $data['company_name'],
                'currency' => $data['currency'] ?? 'EGP',
                'business_types' => $data['business_types'] ?? ['trading'],
            ]);

            $user = User::create([
                'name'       => $data['name'],
                'email'      => $data['email'],
                'password'   => $data['password'], // hashed automatically — User::$casts['password'] = 'hashed'
                'role'       => UserRole::CompanyAdmin->value,
                'company_id' => $company->id,
                'language'   => $data['language'] ?? 'en',
            ]);

            // The company's "owner" is its own first admin, not the
            // (nonexistent, for public sign-up) creator — mirrors
            // Admin\CompanyController::store()'s manual-onboarding path.
            $company->forceFill(['created_by' => $user->id])->save();

            $this->journal->seedChartOfAccounts($company);
            Category::seedDefaults($company->id);
            SalesChannel::seedDefaults($company->id);

            return $user;
        });

        if (AuthVerification::sendOnRegister()) {
            $this->emailVerification->issueAndSend($user);
        }

        return $user;
    }
}
