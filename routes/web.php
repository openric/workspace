<?php

// OpenRiC Workspace - web routes.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Default deny: every route except signing in and the public vocabulary pages
// requires a signed-in account,
// and each action that needs more names its permission IRI.

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\ClipboardController;
use App\Http\Controllers\ConceptController;
use App\Http\Controllers\DateController;
use App\Http\Controllers\DeletionController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\IdentifierController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InstantiationController;
use App\Http\Controllers\IriController;
use App\Http\Controllers\LocalVocabularyController;
use App\Http\Controllers\NameController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PlaceController;
use App\Http\Controllers\ProposalReviewController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RelationController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\VocabularyController;
use App\Http\Middleware\ServeDiscovery;
use App\Http\Middleware\SetLocale;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
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
foreach (['place', 'vocabulary', 'concept', 'other', 'match', 'agent', 'apiKey', 'proposal', 'assertion'] as $parameter) {
    Route::pattern($parameter, '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}');
}

Route::get('/vocab/{scheme}.ttl', [VocabularyController::class, 'turtleFile'])->where('scheme', '[a-z-]+');
Route::get('/vocab/{scheme}', [VocabularyController::class, 'scheme'])->where('scheme', '[a-z-]+');
Route::get('/vocab/{scheme}/{notation}', [VocabularyController::class, 'concept'])->where(['scheme' => '[a-z-]+', 'notation' => '[a-z0-9-]+']);

// Public: the logo appears on the sign-in page too.
Route::get('/branding/logo', [SettingsController::class, 'logo'])->name('branding.logo');

// Heratio's Mirador bundle asks here for the reader's saved Mirador workspaces and falls back to the browser's own storage;
// the Workspace keeps none on the server yet, so it says so instead of answering 404.
Route::get('/api/iiif/workspace', fn () => response()->json(['data' => []]))->name('iiif.workspaces');

// Persistent IRIs resolve, for anyone: the controller sends a page only to those signed in.
Route::get('/{segment}/{id}', [IriController::class, 'resolve'])->where(['segment' => 'record|agent|repository|activity|place|concept|instantiation', 'id' => '[0-9a-f-]{36}'])->name('iri');

// The clipboard (Heratio's): for anyone; it holds only what the reader may see.
Route::prefix('clipboard')->name('clipboard.')->middleware('throttle:120,1')->group(function () {
    Route::get('/', [ClipboardController::class, 'index'])->name('index');
    Route::post('/add', [ClipboardController::class, 'add'])->name('add');
    Route::post('/remove', [ClipboardController::class, 'remove'])->name('remove');
    Route::post('/clear', [ClipboardController::class, 'clear'])->name('clear');
    Route::post('/save', [ClipboardController::class, 'save'])->name('save');
    Route::post('/load', [ClipboardController::class, 'load'])->name('load');
    Route::get('/export', [ClipboardController::class, 'export'])->name('export');
});

// The front page: the workspace for someone signed in, the public catalogue for everyone else.
// Heratio's /set-locale (heratio-dev 4131171e4): choose the interface language, kept in the session and a
// year-long cookie, and for a signed-in account as its own choice; back to the page, on this site only.
Route::post('/language', function (Request $request) {
    $language = (string) $request->input('lang', '');
    if (SetLocale::supported($language)) {
        session(['locale' => $language]);
        Cookie::queue('locale', $language, 60 * 24 * 365, '/', null, true, false, false, 'lax');
        $request->user()?->update(['interface_language' => $language]);
    }
    $back = (string) $request->input('redirect_to', '/');

    return redirect(str_starts_with($back, '/') && ! str_starts_with($back, '//') ? $back : '/');
})->middleware('throttle:60,1')->name('language');

Route::get('/', fn () => auth()->check() ? view('home') : redirect()->route('discover.home'))->middleware(['web', 'active'])->name('home');

// The public catalogue (WS-009): no sign-in; DiscoverController shows only what the Public role may read and is published.
Route::middleware(ServeDiscovery::class)->prefix('discover')->name('discover.')->group(function () {
    Route::get('/', [DiscoverController::class, 'home'])->name('home');
    Route::get('/records/{id}', [DiscoverController::class, 'record'])->name('record');
    Route::get('/records/{id}/tree', [DiscoverController::class, 'treeChildren'])->name('record.tree');
    Route::get('/agents/{id}', [DiscoverController::class, 'agent'])->name('agent');
    Route::get('/repositories/{id}', [DiscoverController::class, 'repository'])->name('repository');
    Route::get('/places/{id}', [DiscoverController::class, 'place'])->name('place');
    Route::get('/activities/{id}', [DiscoverController::class, 'activity'])->name('activity');
    Route::get('/graph/{kind}/{id}', [DiscoverController::class, 'graph'])->where('kind', 'records|agents|repositories|places|activities')->name('graph');
    Route::get('/files/{file}', [DiscoverController::class, 'file'])->name('file');
});

Route::middleware('guest')->group(function () {
    Route::get('/sign-in', [SessionController::class, 'create'])->name('sign-in');
    // Heratio's registration: a visitor asks for an account, where the institution takes requests.
    Route::get('/register', [RegistrationController::class, 'create'])->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:sign-in');
    Route::post('/sign-in', [SessionController::class, 'store'])->middleware('throttle:sign-in');
    Route::get('/set-password/{token}', [PasswordController::class, 'createFromLink'])->name('password.set');
    Route::post('/set-password', [PasswordController::class, 'storeFromLink'])->middleware('throttle:sign-in')->name('password.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    // Heratio's profile: an account sees and edits its own name, email and contact details.
    Route::get('/account', [AccountController::class, 'profile'])->name('account.profile');
    Route::get('/account/edit', [AccountController::class, 'editProfile'])->name('account.edit');
    Route::get('/account/access', [AccountController::class, 'profileAccess'])->name('account.access');
    Route::post('/account', [AccountController::class, 'updateProfile'])->name('account.update');
    Route::post('/account/contact', [AccountController::class, 'updateProfileContact'])->name('account.contact');
    // Heratio's /api/keys: an account's own API keys (ADR 0007).
    Route::get('/account/api-keys', [ApiKeyController::class, 'index'])->name('account.api-keys');
    Route::post('/account/api-keys', [ApiKeyController::class, 'store'])->name('account.api-keys.store');
    Route::post('/account/api-keys/{apiKey}/revoke', [ApiKeyController::class, 'revoke'])->name('account.api-keys.revoke');
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
        Route::get('/export', [ExportController::class, 'everything'])->name('export-all');
        Route::post('/', [RecordController::class, 'store'])->name('store');
        Route::get('/{record}', [RecordController::class, 'show'])->name('show');
        Route::get('/{record}/edit', [RecordController::class, 'edit'])->name('edit');
        Route::post('/{record}', [RecordController::class, 'update'])->name('update');
        Route::get('/{record}/move', [RecordController::class, 'moveForm'])->name('move-form');
        Route::post('/{record}/move', [RecordController::class, 'move'])->name('move');
        Route::post('/{record}/reorder', [RecordController::class, 'reorder'])->name('reorder');
        Route::get('/{record}/tree', [RecordController::class, 'tree'])->name('tree');
        Route::get('/{record}/tree-branch', [RecordController::class, 'treeChildren'])->name('tree-branch');
        Route::get('/{record}/inventory', [RecordController::class, 'inventory'])->name('inventory');
        Route::get('/{record}/finding-aid', [RecordController::class, 'findingAid'])->name('finding-aid');
        Route::get('/{record}/print', [RecordController::class, 'findingAid'])->name('print');
        Route::post('/{record}/calculate-dates', [RecordController::class, 'calculateDates'])->name('calculate-dates');
        Route::get('/{record}/export', [ExportController::class, 'form'])->name('export');
        Route::get('/{record}/export/{format}', [ExportController::class, 'download'])->where('format', 'ead|ead3|csv|dc|mods')->name('export.download');
    });

    // Instantiations and their files (rico:Instantiation, WS-007); the controller checks the unit's permissions.
    Route::post('/records/{record}/instantiations', [InstantiationController::class, 'store'])->name('instantiations.store');
    Route::get('/instantiations/{instantiation}', [InstantiationController::class, 'show'])->name('instantiations.show');
    Route::get('/instantiations/{instantiation}/edit', [InstantiationController::class, 'edit'])->name('instantiations.edit');
    Route::post('/instantiations/{instantiation}', [InstantiationController::class, 'update'])->name('instantiations.update');
    Route::get('/files/{file}', [InstantiationController::class, 'file'])->name('files.show');
    Route::post('/files/{file}/fixity', [InstantiationController::class, 'fixity'])->name('files.fixity');

    // Activities (rico:Activity); the controller checks the authority permissions.
    Route::middleware('access:read,authority')->prefix('activities')->name('activities.')->group(function () {
        Route::get('/', [ActivityController::class, 'index'])->name('index');
        Route::get('/new', [ActivityController::class, 'create'])->name('create');
        Route::post('/', [ActivityController::class, 'store'])->name('store');
        Route::get('/{activity}', [ActivityController::class, 'show'])->name('show');
        Route::get('/{activity}/edit', [ActivityController::class, 'edit'])->name('edit');
        Route::post('/{activity}', [ActivityController::class, 'update'])->name('update');
    });

    // Physical storage (openricx:Container): containers under the storage permissions, storage places
    // (places of a storage place type) made under the vocabulary permissions, as every place is.
    Route::middleware('access:read,storage')->group(function () {
        Route::prefix('containers')->name('containers.')->group(function () {
            Route::get('/', [StorageController::class, 'index'])->name('index');
            Route::get('/new', [StorageController::class, 'create'])->name('create');
            Route::get('/scan', [StorageController::class, 'scan'])->name('scan');
            Route::get('/holdings.csv', [StorageController::class, 'holdings'])->name('holdings');
            Route::post('/', [StorageController::class, 'store'])->name('store');
            Route::get('/{container}', [StorageController::class, 'show'])->name('show');
            Route::get('/{container}/edit', [StorageController::class, 'edit'])->name('edit');
            Route::post('/{container}', [StorageController::class, 'update'])->name('update');
            Route::post('/{container}/move', [StorageController::class, 'move'])->name('move');
            Route::get('/{container}/box-list', [StorageController::class, 'boxList'])->name('box-list');
        });
        Route::get('/storage-places', [StorageController::class, 'places'])->name('storage.places');
        Route::post('/storage-places', [StorageController::class, 'storePlace'])->name('storage.places.store');
        Route::get('/storage-places/{id}', [StorageController::class, 'place'])->where('id', '[0-9a-f-]{36}')->name('storage.place');
        Route::post('/storage-places/{id}/containers', [StorageController::class, 'moveHere'])->where('id', '[0-9a-f-]{36}')->name('storage.place.move');
    });

    // Imports of archival description: a dry run, its report, then the import (WS-015).
    // The review queue of proposals sent through the API (ADR 0007); each proposal is checked against its record.
    Route::middleware('access:review-proposals,description')->prefix('proposals')->name('proposals.')->group(function () {
        Route::get('/', [ProposalReviewController::class, 'index'])->name('index');
        Route::post('/{proposal}/accept', [ProposalReviewController::class, 'accept'])->name('accept');
        Route::post('/{proposal}/reject', [ProposalReviewController::class, 'reject'])->name('reject');
        Route::post('/relations/{assertion}', [ProposalReviewController::class, 'reviewRelation'])->name('relation');
    });

    Route::prefix('imports')->name('imports.')->group(function () {
        Route::get('/', [ImportController::class, 'index'])->name('index');
        Route::get('/new', [ImportController::class, 'create'])->name('create');
        Route::post('/', [ImportController::class, 'store'])->name('store');
        Route::get('/{import}', [ImportController::class, 'show'])->name('show');
        Route::post('/{import}/commit', [ImportController::class, 'commit'])->name('commit');
    });

    // Authority records (ISAAR(CPF)); the controller checks the authority permissions and embargoes.
    Route::middleware('access:read,authority')->prefix('agents')->name('agents.')->group(function () {
        Route::get('/', [AgentController::class, 'index'])->name('index');
        Route::get('/new', [AgentController::class, 'create'])->name('create');
        Route::post('/', [AgentController::class, 'store'])->name('store');
        Route::get('/dashboard', [AgentController::class, 'dashboard'])->name('dashboard');
        Route::get('/duplicates', [AgentController::class, 'duplicates'])->name('duplicates');
        Route::get('/{agent}', [AgentController::class, 'show'])->name('show');
        Route::get('/{agent}/print', [AgentController::class, 'print'])->name('print');
        Route::get('/{agent}/eac-cpf', [AgentController::class, 'eac'])->name('eac');
        Route::get('/{agent}/edit', [AgentController::class, 'edit'])->name('edit');
        Route::post('/{agent}', [AgentController::class, 'update'])->name('update');
        Route::post('/{agent}/contacts', [AgentController::class, 'storeContact'])->name('contacts.store');
        Route::post('/{agent}/contacts/{contact}', [AgentController::class, 'updateContact'])->name('contacts.update');
        Route::post('/{agent}/contacts/{contact}/remove', [AgentController::class, 'destroyContact'])->name('contacts.destroy');
        Route::get('/{agent}/merge', [AgentController::class, 'mergeForm'])->name('merge-form');
        Route::post('/{agent}/merge', [AgentController::class, 'merge'])->name('merge');
        Route::get('/{agent}/split', [AgentController::class, 'splitForm'])->name('split-form');
        Route::post('/{agent}/split', [AgentController::class, 'split'])->name('split');
        Route::post('/{agent}/not-a-duplicate', [AgentController::class, 'dismiss'])->name('dismiss');
    });

    // Places and the institution's own vocabularies (ADR 0006): any account of the institution reads them;
    // each controller checks the vocabulary permissions for changes.
    Route::prefix('places')->name('places.')->group(function () {
        Route::get('/', [PlaceController::class, 'index'])->name('index');
        Route::get('/new', [PlaceController::class, 'create'])->name('create');
        Route::post('/', [PlaceController::class, 'store'])->name('store');
        Route::get('/{place}', [PlaceController::class, 'show'])->name('show');
        Route::get('/{place}/edit', [PlaceController::class, 'edit'])->name('edit');
        Route::post('/{place}', [PlaceController::class, 'update'])->name('update');
        Route::get('/{place}/move', [PlaceController::class, 'moveForm'])->name('move-form');
        Route::post('/{place}/move', [PlaceController::class, 'move'])->name('move');
    });
    Route::prefix('vocabularies')->name('vocabularies.')->group(function () {
        Route::get('/', [LocalVocabularyController::class, 'index'])->name('index');
        Route::get('/new', [LocalVocabularyController::class, 'create'])->name('create');
        Route::post('/', [LocalVocabularyController::class, 'store'])->name('store');
        Route::get('/{vocabulary}', [LocalVocabularyController::class, 'show'])->name('show');
        Route::get('/{vocabulary}/edit', [LocalVocabularyController::class, 'edit'])->name('edit');
        Route::post('/{vocabulary}', [LocalVocabularyController::class, 'update'])->name('update');
        Route::post('/{vocabulary}/remove', [LocalVocabularyController::class, 'destroy'])->name('destroy');
        Route::get('/{vocabulary}/export/{format}', [LocalVocabularyController::class, 'export'])->where('format', 'turtle|rdfxml|ntriples|jsonld')->name('export');
        Route::get('/{vocabulary}/import', [LocalVocabularyController::class, 'importForm'])->name('import-form');
        Route::post('/{vocabulary}/import', [LocalVocabularyController::class, 'import'])->name('import');
        Route::get('/{vocabulary}/concepts/new', [LocalVocabularyController::class, 'createConcept'])->name('concepts.create');
        Route::post('/{vocabulary}/concepts', [LocalVocabularyController::class, 'storeConcept'])->name('concepts.store');
    });
    Route::prefix('concepts')->name('concepts.')->group(function () {
        Route::get('/{concept}', [ConceptController::class, 'show'])->name('show');
        Route::get('/{concept}/edit', [ConceptController::class, 'edit'])->name('edit');
        Route::post('/{concept}', [ConceptController::class, 'update'])->name('update');
        Route::post('/{concept}/related', [ConceptController::class, 'relate'])->name('related.store');
        Route::post('/{concept}/related/{other}/remove', [ConceptController::class, 'unrelate'])->name('related.destroy');
        Route::post('/{concept}/matches', [ConceptController::class, 'addMatch'])->name('matches.store');
        Route::post('/{concept}/matches/{match}/remove', [ConceptController::class, 'removeMatch'])->name('matches.destroy');
        Route::post('/{concept}/move', [ConceptController::class, 'move'])->name('move');
        Route::post('/{concept}/merge', [ConceptController::class, 'merge'])->name('merge');
        Route::post('/{concept}/deprecate', [ConceptController::class, 'deprecate'])->name('deprecate');
        Route::post('/{concept}/restore', [ConceptController::class, 'restore'])->name('restore');
    });

    Route::middleware('can:'.Vocab::permission('manage-institution'))->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });

    // Dates, names and identifiers of any described entity (ADR 0006); each controller checks update on the entity.
    // A concept has labels instead (ConceptController).
    foreach (['dates' => [DateController::class, 'date'], 'names' => [NameController::class, 'name'], 'identifiers' => [IdentifierController::class, 'identifier']] as $part => [$controller, $parameter]) {
        Route::prefix("{kind}/{id}/$part")->where(['kind' => 'records|repositories|places|agents|activities|instantiations|containers', 'id' => '[0-9a-f-]{36}', $parameter => '[0-9a-f-]{36}'])->name("$part.")
            ->group(function () use ($controller, $parameter) {
                Route::post('/', [$controller, 'store'])->name('store');
                Route::post("/{{$parameter}}", [$controller, 'update'])->name('update');
                Route::post("/{{$parameter}}/remove", [$controller, 'destroy'])->name('destroy');
            });
    }

    // Delete a unit of description, a repository or a place, after confirming (EntityDeletion).
    Route::get('{kind}/{id}/delete', [DeletionController::class, 'confirm'])->where(['kind' => 'records|repositories|places|agents|activities|instantiations|containers', 'id' => '[0-9a-f-]{36}'])->name('deletion.confirm');
    Route::post('{kind}/{id}/delete', [DeletionController::class, 'destroy'])->where(['kind' => 'records|repositories|places|agents|activities|instantiations|containers', 'id' => '[0-9a-f-]{36}'])->name('deletion.destroy');

    // The modification history of an entity and its parts, from the audit log.
    Route::get('{kind}/{id}/history', [HistoryController::class, 'show'])->where(['kind' => 'records|repositories|places|agents|activities|instantiations|containers|concepts', 'id' => '[0-9a-f-]{36}'])->name('history');

    // Review, publish and withdraw an entity's description (PublicationService).
    Route::get('{kind}/{id}/publication', [PublicationController::class, 'edit'])->where(['kind' => 'records|repositories|places|agents|activities|instantiations', 'id' => '[0-9a-f-]{36}'])->name('publication.edit');
    Route::post('{kind}/{id}/publication', [PublicationController::class, 'update'])->where(['kind' => 'records|repositories|places|agents|activities|instantiations', 'id' => '[0-9a-f-]{36}'])->name('publication.update');

    // Relations of any described entity, their assertions and evidence (ADR 0006, WS-006).
    Route::prefix('{kind}/{id}/relations')->where(['kind' => EntityKinds::segments(), 'id' => '[0-9a-f-]{36}', 'relation' => '[0-9a-f-]{36}',
        'assertion' => '[0-9a-f-]{36}', 'evidence' => '[0-9a-f-]{36}'])->name('relations.')->group(function () {
            Route::get('/new', [RelationController::class, 'create'])->name('create');
            Route::post('/', [RelationController::class, 'store'])->name('store');
            Route::post('/{relation}', [RelationController::class, 'update'])->name('update');
            Route::post('/{relation}/remove', [RelationController::class, 'destroy'])->name('destroy');
            Route::post('/{relation}/assertions', [RelationController::class, 'assert'])->name('assert');
            Route::post('/{relation}/assertions/{assertion}/review', [RelationController::class, 'review'])->name('review');
            Route::get('/{relation}/evidence', [RelationController::class, 'evidenceForm'])->name('evidence');
            Route::post('/{relation}/evidence', [RelationController::class, 'addEvidence'])->name('evidence.store');
            Route::post('/{relation}/evidence/{evidence}/remove', [RelationController::class, 'removeEvidence'])->name('evidence.destroy');
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

    Route::middleware('can:'.Vocab::permission('manage-accounts'))->group(function () {
        Route::get('/registrations', [RegistrationController::class, 'index'])->name('registrations.index');
        Route::post('/registrations/{registration}/approve', [RegistrationController::class, 'approve'])->name('registrations.approve');
        Route::post('/registrations/{registration}/reject', [RegistrationController::class, 'reject'])->name('registrations.reject');
    });

    Route::middleware('can:'.Vocab::permission('manage-accounts'))->prefix('accounts')->name('accounts.')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->name('index');
        Route::get('/new', [AccountController::class, 'create'])->name('create');
        Route::post('/', [AccountController::class, 'store'])->name('store');
        Route::get('/{account}', [AccountController::class, 'show'])->name('show');
        Route::get('/{account}/edit', [AccountController::class, 'edit'])->name('edit');
        Route::post('/{account}', [AccountController::class, 'update'])->name('update');
        Route::post('/{account}/contact', [AccountController::class, 'updateContact'])->name('contact');
        Route::get('/{account}/access', [AccountController::class, 'access'])->name('access');
        Route::get('/{account}/delete', [AccountController::class, 'confirmDelete'])->name('delete');
        Route::post('/{account}/delete', [AccountController::class, 'destroy'])->name('destroy');
        Route::post('/{account}/roles', [AccountController::class, 'updateRoles'])->name('roles');
        Route::post('/{account}/disable', [AccountController::class, 'disable'])->name('disable');
        Route::post('/{account}/enable', [AccountController::class, 'enable'])->name('enable');
        Route::post('/{account}/password-link', [AccountController::class, 'passwordLink'])->name('password-link');
        Route::post('/{account}/api-keys', [ApiKeyController::class, 'storeFor'])->name('api-keys.store');
        Route::post('/{account}/api-keys/{apiKey}/revoke', [ApiKeyController::class, 'revokeFor'])->name('api-keys.revoke');
    });
});
