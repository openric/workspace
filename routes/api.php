<?php

// OpenRiC Workspace - the OpenRiC read API at /api/ric/v1: the Core Discovery (0.7.0) and Export-Only (0.10.0) profiles.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\OaiController;
use App\Http\Middleware\ServeRicApi;
use Illuminate\Support\Facades\Route;

Route::middleware([ServeRicApi::class, 'throttle:600,1'])->group(function () {
    Route::get('/', [DiscoveryController::class, 'service'])->name('api.service');
    Route::get('/health', [DiscoveryController::class, 'health'])->name('api.health');
    Route::get('/vocabulary', [DiscoveryController::class, 'vocabulary'])->name('api.vocabulary');
    Route::get('/records', [DiscoveryController::class, 'records'])->name('api.records');
    Route::get('/records/{key}', [DiscoveryController::class, 'record'])->name('api.record');
    Route::get('/records/{key}/export', [DiscoveryController::class, 'export'])->name('api.export');
    Route::match(['get', 'post'], '/oai', [OaiController::class, 'handle'])->name('api.oai');
    Route::get('/agents', [DiscoveryController::class, 'agents'])->name('api.agents');
    Route::get('/agents/{key}', [DiscoveryController::class, 'agent'])->name('api.agent');
    Route::get('/repositories', [DiscoveryController::class, 'repositories'])->name('api.repositories');
    Route::get('/repositories/{key}', [DiscoveryController::class, 'repository'])->name('api.repository');
    Route::get('/autocomplete', [DiscoveryController::class, 'autocomplete'])->name('api.autocomplete');
});
