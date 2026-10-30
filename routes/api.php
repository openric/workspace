<?php

// OpenRiC Workspace - the OpenRiC read API at /api/ric/v1: the Core Discovery (0.7.0), Export-Only (0.10.0), Portability
// (0.43.0) and Governance (0.45.0) profiles.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Controllers\Api\DatasetController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\OaiController;
use App\Http\Controllers\Api\ProposalController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\ServeRicApi;
use Illuminate\Support\Facades\Route;

// Reads are open to anyone (Core Discovery 2.1); a key, when sent, must be valid and counts against its limits,
// and reads still see only what the Public role sees (ADR 0007).
Route::middleware([ServeRicApi::class, 'throttle:600,1', AuthenticateApiKey::class])->group(function () {
    Route::get('/', [DiscoveryController::class, 'service'])->name('api.service');
    Route::get('/health', [DiscoveryController::class, 'health'])->name('api.health');
    Route::get('/vocabulary', [DiscoveryController::class, 'vocabulary'])->name('api.vocabulary');
    Route::get('/records', [DiscoveryController::class, 'records'])->name('api.records');
    Route::get('/records/{key}', [DiscoveryController::class, 'record'])->name('api.record');
    Route::get('/records/{key}/export', [DiscoveryController::class, 'export'])->name('api.export');
    Route::get('/records/{key}/files', [DiscoveryController::class, 'files'])->name('api.files');
    Route::match(['get', 'post'], '/oai', [OaiController::class, 'handle'])->name('api.oai');
    Route::get('/agents', [DiscoveryController::class, 'agents'])->name('api.agents');
    Route::get('/agents/{key}', [DiscoveryController::class, 'agent'])->name('api.agent');
    Route::get('/repositories', [DiscoveryController::class, 'repositories'])->name('api.repositories');
    Route::get('/repositories/{key}', [DiscoveryController::class, 'repository'])->name('api.repository');
    Route::get('/autocomplete', [DiscoveryController::class, 'autocomplete'])->name('api.autocomplete');
    // Portability 3 and 4: the dataset descriptor and its change feed (phase 11b).
    Route::get('/dataset', [DatasetController::class, 'dataset'])->name('api.dataset');
    Route::get('/changelog', [DatasetController::class, 'changelog'])->name('api.changelog');
});

// Proposals need a key (ADR 0007): to send them, one with the write scope; to list them, any.
Route::middleware([ServeRicApi::class, 'throttle:600,1'])->group(function () {
    Route::post('/records/{key}/proposals', [ProposalController::class, 'store'])->middleware(AuthenticateApiKey::class.':write')->name('api.proposals.store');
    Route::get('/proposals', [ProposalController::class, 'index'])->middleware(AuthenticateApiKey::class.':read')->name('api.proposals');
});
