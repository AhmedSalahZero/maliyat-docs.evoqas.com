<?php

namespace App\Services;

use App\Enums\LoginActivityType;
use App\Models\User;
use App\Models\UserLoginActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserLoginFrequencyService
{
    private const ACTIVITY_TOUCH_MINUTES = 5;

    /**
     * Record an explicit authentication event (login or post-registration login).
     * Each successful auth increments the frequency counter, even on the same day.
     */
    public function recordExplicitLogin(User $user, string $source = 'web'): void
    {
        DB::transaction(function () use ($user, $source) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $now    = now();

            UserLoginActivity::query()->create([
                'user_id'       => $locked->id,
                'login_at'      => $now,
                'activity_date' => $now->toDateString(),
                'type'          => LoginActivityType::Login,
                'source'        => $source,
            ]);

            $locked->forceFill([
                'last_login_at'    => $now,
                'last_activity_at' => $now,
                'login_count'      => $locked->login_count + 1,
            ])->save();

            $this->markActivityForDate($locked->id, $now->toDateString());
        });

        $user->refresh();
    }

    /**
     * Record the first access of a new calendar day for a user who remains logged in.
     * Skipped when the user already has any activity row for today.
     */
    public function recordDailyAccessIfNeeded(User $user, string $source = 'web'): void
    {
        $today = now()->toDateString();

        if ($this->hasActivityForDate($user->id, $today)) {
            $this->touchLastActivityIfStale($user);

            return;
        }

        if ($user->last_activity_at?->toDateString() === $today) {
            $this->markActivityForDate($user->id, $today);
            $this->touchLastActivityIfStale($user);

            return;
        }

        DB::transaction(function () use ($user, $source, $today) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $now    = now();

            if ($this->hasActivityForDate($locked->id, $today)) {
                $this->touchLastActivityLocked($locked, $now);

                return;
            }

            $created = UserLoginActivity::query()->firstOrCreate(
                [
                    'user_id'       => $locked->id,
                    'activity_date' => $today,
                    'type'          => LoginActivityType::DailyAccess,
                ],
                [
                    'login_at' => $now,
                    'source'   => $source,
                ]
            );

            if (! $created->wasRecentlyCreated) {
                $this->touchLastActivityLocked($locked, $now);

                return;
            }

            $locked->forceFill([
                'last_activity_at' => $now,
                'login_count'      => $locked->login_count + 1,
            ])->save();

            $this->markActivityForDate($locked->id, $today);
        });
    }

    /**
     * @return array{
     *     login_count: int,
     *     last_login_at: ?string,
     *     last_activity_at: ?string
     * }
     */
    public function getUserStatistics(User $user): array
    {
        $user->refresh();

        return [
            'login_count'      => (int) $user->login_count,
            'last_login_at'    => $user->last_login_at?->toIso8601String(),
            'last_activity_at' => $user->last_activity_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     total_logins: int,
     *     unique_active_days: int,
     *     active_days_this_month: int,
     *     active_days_last_month: int
     * }
     */
    public function getLoginFrequencyAnalytics(User $user): array
    {
        $user->refresh();

        $now       = now();
        $thisMonth = $now->copy()->startOfMonth();
        $lastMonth = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $lastMonth->copy()->endOfMonth();

        $base = UserLoginActivity::query()->where('user_id', $user->id);

        return [
            'total_logins'           => (int) $user->login_count,
            'unique_active_days'     => (clone $base)->distinct('activity_date')->count('activity_date'),
            'active_days_this_month' => (clone $base)
                ->whereBetween('activity_date', [$thisMonth->toDateString(), $now->toDateString()])
                ->distinct('activity_date')
                ->count('activity_date'),
            'active_days_last_month' => (clone $base)
                ->whereBetween('activity_date', [$lastMonth->toDateString(), $lastMonthEnd->toDateString()])
                ->distinct('activity_date')
                ->count('activity_date'),
        ];
    }

    private function touchLastActivityIfStale(User $user): void
    {
        $threshold = now()->subMinutes(self::ACTIVITY_TOUCH_MINUTES);

        if ($user->last_activity_at && $user->last_activity_at->greaterThanOrEqualTo($threshold)) {
            return;
        }

        User::query()
            ->whereKey($user->id)
            ->where(function ($query) use ($threshold) {
                $query->whereNull('last_activity_at')
                    ->orWhere('last_activity_at', '<', $threshold);
            })
            ->update(['last_activity_at' => now()]);
    }

    private function touchLastActivityLocked(User $user, Carbon $now): void
    {
        $threshold = $now->copy()->subMinutes(self::ACTIVITY_TOUCH_MINUTES);

        if ($user->last_activity_at && $user->last_activity_at->greaterThanOrEqualTo($threshold)) {
            return;
        }

        $user->forceFill(['last_activity_at' => $now])->save();
    }

    private function hasActivityForDate(int $userId, string $date): bool
    {
        return Cache::remember(
            $this->activityCacheKey($userId, $date),
            now()->endOfDay(),
            fn () => UserLoginActivity::query()
                ->where('user_id', $userId)
                ->where('activity_date', $date)
                ->exists()
        );
    }

    private function markActivityForDate(int $userId, string $date): void
    {
        Cache::put($this->activityCacheKey($userId, $date), true, now()->endOfDay());
    }

    private function activityCacheKey(int $userId, string $date): string
    {
        return "user_login_activity:{$userId}:{$date}";
    }
}
