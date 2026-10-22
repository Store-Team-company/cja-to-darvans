<?php

namespace Botble\DatapilotConnector\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        return view('plugins/datapilot-connector::settings');
    }
}
