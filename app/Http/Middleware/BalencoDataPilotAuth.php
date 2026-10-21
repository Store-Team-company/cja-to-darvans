<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BalencoDataPilotAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('bdp_logged_in')) {
            return redirect()->route('bdp.login.form');
        }

        return $next($request);
    }
}
