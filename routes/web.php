<?php

// OpenRiC Workspace - web routes.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Default deny: every route except signing in and the public vocabulary pages
// requires a signed-in account,
// and each action that needs more names its permission IRI.

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DateController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\VocabularyController;
use App\Support\Vocab;
use Illuminate\Support\Facades\Route;

// Public: the Workspace's own vocabulary IRIs (VocabularyController). The one
// deliberate exception to default deny; they hold only published vocabulary data.
// An account is addressed by its UUID; anything else is a 404, never a database error.
Route::pattern('account', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
Route::pattern('role', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
Route::pattern('kind', '[a-z-]+');
Route::pattern('record', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
Route::pattern('date', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
Route::pattern('contact', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
Route::pattern('repository', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');

Route::get('/vocab/{scheme}.ttl', [VocabularyController::class, 'turtleFile'])->where('scheme', '[a-z-]+');
Route::get('/vocab/{scheme}', [VocabularyController::class, 'scheme'])->where('scheme', '[a-z-]+');
Route::get('/vocab/{scheme}/{notation}', [VocabularyController::class, 'concept'])->where(['scheme' => '[a-z-]+', 'notation' => '[a-z0-9-]+']);

// Public: the logo appears on the sign-in page too.
Route::get('/branding/logo', [SettingsController::class, 'logo'])->name('branding.logo');

Route::middleware('guest')->group(function () {
    Route::get('/sign-in', [SessionController::class, 'create'])->name('sign-in');
    Route::post('/sign-in', [SessionController::class, 'store'])->middleware('throttle:sign-in');
    Route::get('/set-password/{token}', [PasswordController::class, 'createFromLink'])->name('password.set');
    Route::post('/set-password', [PasswordController::class, 'storeFromLink'])->middleware('throttle:sign-in')->name('password.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::view('/', 'home')->name('home');
    Route::get('/account/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::post('/account/password', [PasswordController::class, 'update'])->name('password.update');
    Route::post('/sign-out', [SessionController::class, 'destroy'])->name('sign-out');
    Route::get('/audit', [AuditLogController::class, 'index'])
        ->middleware('can:'.Vocab::permission('view-audit-log'))
        ->name('audit');

    Route::middleware('access:read,repository')->prefix('repositories')->name('repositories.')->group(function () {
        Route::get('/', [RepositoryController::class, 'index'])->name('index');
        Route::get('/new', [RepositoryController::class, 'create'])->name('create');
        Route::post('/', [RepositoryController::class, 'store'])->name('store');
        Route::get('/{repository}', [RepositoryController::class, 'show'])->name('show');
        Route::get('/{repository}/edit', [RepositoryController::class, 'edit'])->name('edit');
        Route::post('/{repository}', [RepositoryController::class, 'update'])->name('update');
        Route::post('/{repository}/contacts', [RepositoryController::class, 'storeContact'])->name('contacts.store');
        Route::post('/{repository}/contacts/{contact}', [RepositoryController::class, 'updateContact'])->name('contacts.update');
        Route::post('/{repository}/contacts/{contact}/remove', [RepositoryController::class, 'destroyContact'])->name('contacts.destroy');
    });

    Route::middleware('access:read,description')->prefix('records')->name('records.')->group(function () {
        Route::get('/', [RecordController::class, 'index'])->name('index');
        Route::get('/new', [RecordController::class, 'create'])->name('create');
        Route::post('/', [RecordController::class, 'store'])->name('store');
        Route::get('/{record}', [RecordController::class, 'show'])->name('show');
        Route::get('/{record}/edit', [RecordController::class, 'edit'])->name('edit');
        Route::post('/{record}', [RecordController::class, 'update'])->name('update');
        Route::get('/{record}/move', [RecordController::class, 'moveForm'])->name('move-form');
        Route::post('/{record}/move', [RecordController::class, 'move'])->name('move');
        Route::post('/{record}/reorder', [RecordController::class, 'reorder'])->name('reorder');
    });

    Route::middleware('can:'.Vocab::permission('manage-institution'))->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    // Dates of any described entity (ADR 0006); DateController checks update on the entity.
    Route::prefix('{kind}/{id}/dates')->where(['kind' => 'records|repositories', 'id' => '[0-9a-f-]{36}'])->name('dates.')->group(function () {
        Route::post('/', [DateController::class, 'store'])->name('store');
        Route::post('/{date}', [DateController::class, 'update'])->name('update');
        Route::post('/{date}/remove', [DateController::class, 'destroy'])->name('destroy');
    });

    Route::middleware('can:'.Vocab::permission('manage-roles'))->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('/roles/new', [RoleController::class, 'create'])->name('roles.create');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
        Route::post('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::post('/roles/{role}/remove', [RoleController::class, 'destroy'])->name('roles.destroy');
        Route::post('/roles/{role}/members', [RoleController::class, 'addMember'])->name('roles.members.add');
        Route::post('/roles/{role}/members/{account}/remove', [RoleController::class, 'removeMember'])->name('roles.members.remove');
        Route::post('/roles/{role}/languages', [RoleController::class, 'languages'])->name('roles.languages');
        Route::get('/roles/{role}/permissions/{kind}', [RoleController::class, 'permissions'])->name('roles.permissions');
        Route::post('/roles/{role}/permissions/{kind}', [RoleController::class, 'updatePermissions'])->name('roles.permissions.update');
        Route::get('/accounts/{account}/permissions/{kind}', [RoleController::class, 'accountPermissions'])->name('accounts.permissions');
        Route::post('/accounts/{account}/permissions/{kind}', [RoleController::class, 'updateAccountPermissions'])->name('accounts.permissions.update');
    });

    Route::middleware('can:'.Vocab::permission('manage-accounts'))->prefix('accounts')->name('accounts.')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->name('index');
        Route::get('/new', [AccountController::class, 'create'])->name('create');
        Route::post('/', [AccountController::class, 'store'])->name('store');
        Route::get('/{account}', [AccountController::class, 'show'])->name('show');
        Route::post('/{account}/roles', [AccountController::class, 'updateRoles'])->name('roles');
        Route::post('/{account}/disable', [AccountController::class, 'disable'])->name('disable');
        Route::post('/{account}/enable', [AccountController::class, 'enable'])->name('enable');
        Route::post('/{account}/password-link', [AccountController::class, 'passwordLink'])->name('password-link');
    });
});
