<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class BasicAuthMiddleware
{
    public const SCOPE_WEBHOOK = 'webhook';

    public const SCOPE_ADMIN_API = 'admin_api';

    /** Failed attempts allowed per client IP and scope before further failures get 429. */
    private const MAX_FAILED_ATTEMPTS = 20;

    private const FAILED_ATTEMPT_DECAY_SECONDS = 60;

    /**
     * Basic Auth for machine clients.
     *
     * - webhook (default): Freshdesk apps -> /api/webhooks, /api/tickets.
     * - admin_api: /api/admin, with its own credential (ADMIN_API_USER/PASSWORD).
     *   Missing, or identical to the webhook credential, means the admin API is closed.
     *
     * Valid credentials always pass. Only failed attempts are counted, so a
     * brute-force burst cannot lock out legitimate callers behind the same IP.
     */
    public function handle(Request $request, Closure $next, string $scope = self::SCOPE_WEBHOOK): Response
    {
        [$username, $password] = $this->credentials($scope);

        if ($username !== '' && $password !== ''
            && hash_equals($username, (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword())) {
            return $next($request);
        }

        $key = "basic-auth-failed:{$scope}:".$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILED_ATTEMPTS)) {
            $retryAfter = RateLimiter::availableIn($key);
            Log::warning('Basic auth temporarily blocked after repeated failures', [
                'scope' => $scope,
                'ip' => $request->ip(),
                'retry_after' => $retryAfter,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Too many failed authentication attempts',
            ], 429, ['Retry-After' => (string) $retryAfter]);
        }
        RateLimiter::hit($key, self::FAILED_ATTEMPT_DECAY_SECONDS);

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized',
        ], 401, ['WWW-Authenticate' => 'Basic']);
    }

    /** @return array{0: string, 1: string} */
    private function credentials(string $scope): array
    {
        $webhook = [
            (string) config('freshdesk.basic_auth.username', ''),
            (string) config('freshdesk.basic_auth.password', ''),
        ];

        if ($scope !== self::SCOPE_ADMIN_API) {
            return $webhook;
        }

        $admin = [
            (string) config('freshdesk.admin_api_basic_auth.username', ''),
            (string) config('freshdesk.admin_api_basic_auth.password', ''),
        ];

        // The admin API must never be reachable with the credential that every
        // Freshdesk app installation holds.
        if ($admin[1] !== '' && $admin === $webhook) {
            Log::warning('Admin API credential equals the webhook credential; admin API is closed.');

            return ['', ''];
        }

        return $admin;
    }
}
