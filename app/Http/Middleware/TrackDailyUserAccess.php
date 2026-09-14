<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\UserLoginFrequencyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackDailyUserAccess
{
    public function __construct(
        private readonly UserLoginFrequencyService $loginFrequency,
    ) {}

    /**
     * Count the first authenticated GET page visit on a new calendar day.
     * Explicit logins are handled by RecordSuccessfulLogin.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldTrack($request)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User) {
            $this->loginFrequency->recordDailyAccessIfNeeded($user, 'web');
        }

        return $next($request);
    }

    private function shouldTrack(Request $request): bool
    {
        if (! $request->user()) {
            return false;
        }

        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($request->header('Purpose') === 'prefetch') {
            return false;
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return false;
        }

        if ($request->is(
            'build/*',
            'images/*',
            'storage/*',
            'vendor/*',
            'favicon.ico',
            'manifest.webmanifest',
            'sw.js',
            'offline.html',
        )) {
            return false;
        }

        if (str_starts_with($request->path(), 'workbox-')) {
            return false;
        }

        return true;
    }
}
