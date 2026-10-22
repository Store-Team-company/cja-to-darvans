<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Services\Saas\SaasPlanCatalog;
use Illuminate\View\View;

class SaasPublicController extends Controller
{
    public function home(SaasPlanCatalog $plans): View
    {
        return view('saas.public.home', ['plans' => $plans->all()]);
    }

    public function pricing(SaasPlanCatalog $plans): View
    {
        return view('saas.public.pricing', ['plans' => $plans->all()]);
    }
}
