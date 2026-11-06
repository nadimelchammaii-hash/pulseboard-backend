<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks account settings (name, email, password) for demo accounts: they are
 * temporary and deleted after a day, not a place to keep personal details.
 */
class RejectDemoAccounts
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDemo()) {
            abort(Response::HTTP_FORBIDDEN, 'Demo accounts cannot change account settings. Create your own account to use your own name and email.');
        }

        return $next($request);
    }
}
