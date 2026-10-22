<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSaasEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('saas.enabled', false), 404);

        return $next($request);
    }
}
