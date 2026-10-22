<?php

namespace App\Http\Middleware;

use App\Services\Saas\SaasAccountAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSaasCustomerApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && app(SaasAccountAccess::class)->allows($user), 403,
            'Customer account is not activated.');

        if ($user->saas_password_change_required) {
            return redirect()->route('saas.password.change.form');
        }

        return $next($request);
    }
}
