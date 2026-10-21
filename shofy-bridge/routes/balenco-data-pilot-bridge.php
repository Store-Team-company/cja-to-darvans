<?php

use App\Http\Controllers\BalencoDataPilotImageBridgeController;
use Illuminate\Support\Facades\Route;

Route::post('/balenco-data-pilot/upload-image', [BalencoDataPilotImageBridgeController::class, 'upload'])
    ->name('balenco-data-pilot.upload-image');
