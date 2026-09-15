<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Notifications\TrialEndingNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — NotifyExpiringTrials
//
//  `php artisan subscriptions:notify-expiring` — scheduled daily in
//  routes/console.php, never run by hand.
//
//  Emails every company admin whose free trial is inside the warning
//  window, so nobody first learns their access has stopped by being
//  locked out. Two guards keep it from becoming spam:
//
//    • expiry_notified_at, so a company in its final week is not
//      emailed every single morning.
//    • only company_admins are written to — an employee can't renew
//      anything, so telling them would just be noise.
//
//  Runs unauthenticated from the scheduler, which means the
//  BelongsToCompany global scope is inert and every company is
//  visible. That is exactly what this command needs.
// ══════════════════════════════════════════════════════════════════
class NotifyExpiringTrials extends Command
{
    protected $signature = 'subscriptions:notify-expiring';

    protected $description = 'Email company admins whose free trial is about to end';

    public function handle(): int
    {
        $windowDays  = (int) config('subscription.notify_days_before', 7);
        $repeatAfter = (int) config('subscription.notify_again_after_days', 3);

        $companies = Company::query()
            ->where('is_active', true)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', now())
            ->where('trial_ends_at', '<=', now()->addDays($windowDays))
            ->where(function ($query) use ($repeatAfter) {
                $query->whereNull('expiry_notified_at')
                    ->orWhere('expiry_notified_at', '<=', now()->subDays($repeatAfter));
            })
            ->get();

        $emailed = 0;

        foreach ($companies as $company) {
            $admins = User::query()
                ->where('company_id', $company->id)
                ->where('role', UserRole::CompanyAdmin->value)
                ->where('is_active', true)
                ->whereNotNull('email_verified_at')
                ->get();

            if ($admins->isEmpty()) {
                $this->warn("Company #{$company->id} has no verified admin to notify.");

                continue;
            }

            $daysLeft = $company->daysUntilExpiry();

            foreach ($admins as $admin) {
                try {
                    $admin->notify(new TrialEndingNotification($company, $daysLeft));
                    $emailed++;
                } catch (\Throwable $e) {
                    // One bad address must not stop the whole run.
                    Log::error('Trial reminder failed', [
                        'company_id' => $company->id,
                        'user_id'    => $admin->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            $company->forceFill(['expiry_notified_at' => now()])->save();
        }

        $this->info("Trial reminders: emailed {$emailed} admin(s) across {$companies->count()} company(ies).");

        return self::SUCCESS;
    }
}
