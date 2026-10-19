<?php

// OpenRiC Workspace - web routes.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Default deny: every route except signing in requires a signed-in account,
// and each action that needs more names its permission IRI.

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\SessionController;
use App\Support\Vocab;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/sign-in', [SessionController::class, 'create'])->name('sign-in');
    Route::post('/sign-in', [SessionController::class, 'store'])->middleware('throttle:sign-in');
});

Route::middleware('auth')->group(function () {
    Route::view('/', 'home')->name('home');
    Route::post('/sign-out', [SessionController::class, 'destroy'])->name('sign-out');
    Route::get('/audit', [AuditLogController::class, 'index'])
        ->middleware('can:'.Vocab::permission('view-audit-log'))
        ->name('audit');
});
