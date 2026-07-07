<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks suspended users from starting new trades. In-flight trades are still
 * resolved by the poller, so suspension never leaves a trade stuck.
 */
class EnsureUserNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuspended()) {
            return response()->json([
                'message' => 'Your account is suspended and cannot trade.',
                'code' => 'account_suspended',
            ], 403);
        }

        return $next($request);
    }
}
