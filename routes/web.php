<?php

// OpenRiC Workspace - web routes.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Default deny: every route except signing in and the public vocabulary pages
// requires a signed-in account,
// and each action that needs more names its permission IRI.

use App\Http\Controllers\AccessionAppraisalController;
use App\Http\Controllers\AccessionController;
use App\Http\Controllers\AccessionIntakeController;
use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgreementController;
use App\Http\Controllers\AltTextController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CaptionController;
use App\Http\Controllers\ClearanceController;
use App\Http\Controllers\ClipboardController;
use App\Http\Controllers\CommunityLabelController;
use App\Http\Controllers\ConceptController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\DateController;
use App\Http\Controllers\DeletionController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\DuplicateController;
use App\Http\Controllers\EmailBounceController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\EmbargoController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FavouritesController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\FormatController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\IdentifierController;
use App\Http\Controllers\IiifAuthController;
use App\Http\Controllers\IiifCollectionController;
use App\Http\Controllers\IiifController;
use App\Http\Controllers\IiifValidationController;
use App\Http\Controllers\IiifWorkspaceController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\IngestController;
use App\Http\Controllers\InstantiationController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\IriController;
use App\Http\Controllers\JobsController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\JurisdictionController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\LocalVocabularyController;
use App\Http\Controllers\NameController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PlaceController;
use App\Http\Controllers\PreservationController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\PrivacyScreeningController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProposalReviewController;
use App\Http\Controllers\ProvenanceController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\PublishingController;
use App\Http\Controllers\ReadingRoomController;
use App\Http\Controllers\ReasoningController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\RecordMembershipController;
use App\Http\Controllers\RecordsManagementController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\RelationController;
use App\Http\Controllers\ReportBuilderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\ResearchDeskController;
use App\Http\Controllers\ResearcherController;
use App\Http\Controllers\ResearchFundingController;
use App\Http\Controllers\ResearchNotesController;
use App\Http\Controllers\ResearchToolsController;
use App\Http\Controllers\ResearchWorkspaceController;
use App\Http\Controllers\RetentionController;
use App\Http\Controllers\RightsAdminController;
use App\Http\Controllers\RightsController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShareLinkController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\SignOnController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\TeachingController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VersionController;
use App\Http\Controllers\VocabularyController;
use App\Http\Controllers\WatermarkController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\WorkflowQueueController;
use App\Http\Middleware\ServeDiscovery;
use App\Http\Middleware\SetLocale;
use App\Privacy\Registers;
use App\Relations\EntityKinds;
use App\Reports\ReportService;
use App\Research\ProjectExport;
use App\Support\Vocab;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
Route::get('/{segment}/{id}', [IriController::class, 'resolve'])->where(['segment' => 'record|agent|repository|activity|place|concept|instantiation|accession|agreement|provenance|rule|proxy', 'id' => '[0-9a-f-]{36}'])->name('iri');

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
    if (SetLocale::supported($request, $language)) {
        session(['locale' => $language]);
        Cookie::queue('locale', $language, 60 * 24 * 365, '/', null, true, false, false, 'lax');
        $request->user()?->update(['interface_language' => $language]);
    }
    $back = (string) $request->input('redirect_to', '/');

    return redirect(str_starts_with($back, '/') && ! str_starts_with($back, '//') ? $back : '/');
})->middleware('throttle:60,1')->name('language');

Route::get('/', fn () => auth()->check() ? view('home') : redirect()->route('discover.home'))->middleware(['web', 'active'])->name('home');

// The public catalogue (WS-009): no sign-in; DiscoverController shows only what the Public role may read and is published.
// Sitemaps, robots.txt naming them, and an Atom feed of what was published last (roadmap phase 22c).
// Prometheus text for a monitor that sends the operator's token (roadmap phase 12a); not served without one.
Route::get('/metrics', [OperationsController::class, 'metrics'])->middleware('throttle:120,1')->name('metrics');

Route::middleware([ServeDiscovery::class, 'throttle:120,1'])->group(function () {
    Route::get('/robots.txt', [PublishingController::class, 'robots'])->name('robots');
    Route::get('/sitemap.xml', [PublishingController::class, 'index'])->name('sitemap');
    Route::get('/sitemap-{kind}.xml', [PublishingController::class, 'kind'])->whereIn('kind', ['records', 'agents', 'repositories', 'places', 'activities', 'rules'])->name('sitemap.kind');
    Route::get('/feed.atom', [PublishingController::class, 'feed'])->name('feed');
});
Route::middleware(ServeDiscovery::class)->prefix('discover')->name('discover.')->group(function () {
    Route::get('/', [DiscoverController::class, 'home'])->name('home');
    Route::get('/records/{id}', [DiscoverController::class, 'record'])->name('record');
    Route::get('/records/{id}/tree', [DiscoverController::class, 'treeChildren'])->name('record.tree');
    Route::get('/agents/{id}', [DiscoverController::class, 'agent'])->name('agent');
    Route::get('/repositories/{id}', [DiscoverController::class, 'repository'])->name('repository');
    Route::get('/repositories/{id}/theme/{part}', [DiscoverController::class, 'repositoryImage'])->whereIn('part', ['banner', 'logo'])->name('repository.image');
    Route::get('/places/{id}', [DiscoverController::class, 'place'])->name('place');
    Route::get('/activities/{id}', [DiscoverController::class, 'activity'])->name('activity');
    Route::get('/rules/{id}', [DiscoverController::class, 'rule'])->name('rule');
    Route::get('/graph/{kind}/{id}', [DiscoverController::class, 'graph'])->where('kind', 'records|agents|repositories|places|activities|rules')->name('graph');
    Route::get('/files/{file}', [DiscoverController::class, 'file'])->name('file');
    Route::get('/captions/{track}', [DiscoverController::class, 'caption'])->whereUuid('track')->name('caption');
    Route::get('/records/{id}/iiif', [IiifController::class, 'viewer'])->name('iiif');
});

// IIIF Presentation 3, Change Discovery and Content State (roadmap phase 14a, ADR 0014): what the public catalogue shows.
Route::middleware([ServeDiscovery::class, 'throttle:600,1'])->prefix('iiif')->name('iiif.')->group(function () {
    Route::get('/collection', [IiifController::class, 'top'])->name('top');
    Route::get('/records/{id}/manifest', [IiifController::class, 'manifest'])->name('manifest');
    Route::get('/records/{id}/collection', [IiifController::class, 'collection'])->name('collection');
    Route::get('/collections/{collection}', [IiifController::class, 'curated'])->whereUuid('collection')->name('curated');
    Route::get('/discovery/changes', [IiifController::class, 'changes'])->name('changes');
    Route::get('/content-state', [IiifController::class, 'contentState'])->name('content-state');
    // Content Search 2.0 and Authorization Flow 2.0 (phase 14b).
    Route::get('/records/{id}/search', [IiifController::class, 'search'])->name('search');
    Route::get('/records/{id}/autocomplete', [IiifController::class, 'autocomplete'])->name('autocomplete');
    Route::match(['get', 'options'], '/auth/probe/{file}', [IiifAuthController::class, 'probe'])->whereUuid('file')->name('auth.probe');
    Route::get('/auth/access', [IiifAuthController::class, 'access'])->name('auth.access');
    Route::get('/auth/token', [IiifAuthController::class, 'token'])->name('auth.token');
});

// A built report opened by its share link (roadmap phase 8): no sign-in; the link expires, and only its hash is kept.
Route::get('/shared/reports/{token}', [ReportBuilderController::class, 'shared'])->middleware('throttle:60,1')->name('reports.shared');

// The mail provider's bounce and complaint webhook (roadmap phase 7; Heratio's): public, HMAC-signed, so no session or CSRF token.
Route::post('/webhooks/email/bounce', [EmailBounceController::class, 'receive'])->withoutMiddleware(PreventRequestForgery::class)
    ->middleware('throttle:120,1')->name('email.bounce');

// The cart (roadmap phase 19a, ADR 0017): for anyone; a visitor's is kept by session. PayFast's notification comes from
// PayFast, so it carries no CSRF token (bootstrap/app.php); it is trusted only once verified (App\Research\Shop).
Route::middleware('throttle:120,1')->prefix('cart')->name('shop.cart')->whereUuid(['record', 'item'])->group(function () {
    Route::get('/', [ShopController::class, 'cart']);
    Route::post('/add/{record}', [ShopController::class, 'add'])->name('.add');
    Route::post('/items/{item}', [ShopController::class, 'change'])->name('.change');
    Route::post('/items/{item}/remove', [ShopController::class, 'remove'])->name('.remove');
    Route::post('/clear', [ShopController::class, 'clear'])->name('.clear');
});
Route::post('/shop/payfast/notify', [ShopController::class, 'notify'])->middleware('throttle:60,1')->name('shop.payfast.notify');
// Citing a record, and a bibliography opened by its link (roadmap phase 19c, ADR 0017): for anyone.
Route::get('/cite/{record}', [ResearchToolsController::class, 'cite'])->middleware('throttle:120,1')->whereUuid('record')->name('cite');
Route::get('/bibliographies/shared/{token}', [ResearchToolsController::class, 'sharedBibliography'])->middleware('throttle:60,1')->where('token', '[A-Za-z0-9]{48}')->name('research.bibliographies.shared');
// Feedback on the catalogue or a record (roadmap phase 19a, ADR 0017): for anyone, rate limited.
Route::get('/feedback', [FeedbackController::class, 'create'])->name('feedback.create');
Route::post('/feedback', [FeedbackController::class, 'store'])->middleware('throttle:5,1')->name('feedback.store');
Route::get('/feedback/thanks', [FeedbackController::class, 'thanks'])->name('feedback.thanks');
// A record opened by a share link (roadmap phase 19a, ADR 0017): for anyone holding it.
Route::middleware('throttle:60,1')->prefix('shared')->name('share-links.')->where(['token' => '[A-Za-z0-9]{48}'])->group(function () {
    Route::get('/{token}', [ShareLinkController::class, 'open'])->name('open');
    Route::get('/{token}/files/{file}', [ShareLinkController::class, 'file'])->whereUuid('file')->name('file');
});
// A folder of favourites opened by its link (roadmap phase 19a): for anyone holding it.
Route::get('/favourites/shared/{token}', [FavouritesController::class, 'shared'])->middleware('throttle:60,1')->where('token', '[A-Za-z0-9]{48}')->name('favourites.shared');
// A researcher's collection opened by its link (roadmap phase 19d): for anyone holding it.
Route::get('/collections/shared/{token}', [ResearchNotesController::class, 'sharedCollection'])->middleware('throttle:60,1')->where('token', '[A-Za-z0-9]{48}')->name('research.collections.shared');
// A research project's summary opened by its link (roadmap phase 19f): for anyone holding it.
Route::get('/projects/shared/{token}', [ProjectController::class, 'shared'])->middleware('throttle:60,1')->where('token', '[A-Za-z0-9]{48}')->name('research.projects.shared');
// A training certificate, checked by its number, public so whoever is shown one can check it (roadmap phase 19j).
Route::get('/certificates/{number}', [TeachingController::class, 'certificate'])->middleware('throttle:60,1')->where('number', 'CERT-[0-9]{4}-[A-Za-z0-9]{8}')->name('research.certificates.show');
// A DOI's landing page, public as a DOI must resolve (roadmap phase 19h, ADR 0017).
Route::get('/doi/{doi}', [ResearchFundingController::class, 'landing'])->middleware('throttle:60,1')->whereUuid('doi')->name('research.dois.landing');

Route::middleware('guest')->group(function () {
    Route::get('/sign-in', [SessionController::class, 'create'])->name('sign-in');
    // Heratio's registration: a visitor asks for an account, where the institution takes requests.
    Route::get('/register', [RegistrationController::class, 'create'])->name('register');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:sign-in');
    Route::post('/sign-in', [SessionController::class, 'store'])->middleware('throttle:sign-in');
    Route::get('/set-password/{token}', [PasswordController::class, 'createFromLink'])->name('password.set');
    Route::post('/set-password', [PasswordController::class, 'storeFromLink'])->middleware('throttle:sign-in')->name('password.store');
    // Single sign-on through the institution's identity provider, and the second factor after a password (roadmap phase 12b).
    Route::get('/sign-in/provider/{provider}', [SignOnController::class, 'start'])->whereUuid('provider')->middleware('throttle:sign-in')->name('sso.start');
    Route::get('/sign-in/provider/{provider}/callback', [SignOnController::class, 'callback'])->whereUuid('provider')->middleware('throttle:sign-in')->name('sso.callback');
    Route::get('/sign-in/two-factor', [SignOnController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/sign-in/two-factor', [SignOnController::class, 'verify'])->middleware('throttle:sign-in')->name('two-factor.verify');
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
    // The account's own two-factor sign-in (roadmap phase 12b).
    Route::get('/account/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('/account/two-factor', [TwoFactorController::class, 'begin'])->name('two-factor.begin');
    Route::post('/account/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:sign-in')->name('two-factor.confirm');
    Route::post('/account/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->middleware('throttle:sign-in')->name('two-factor.regenerate');
    Route::post('/account/two-factor/off', [TwoFactorController::class, 'disable'])->middleware('throttle:sign-in')->name('two-factor.disable');
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
        Route::get('/{repository}/theme', [RepositoryController::class, 'editTheme'])->name('theme');
        Route::post('/{repository}/theme', [RepositoryController::class, 'updateTheme'])->name('theme.update');
        Route::get('/{repository}/theme/{part}', [RepositoryController::class, 'themeImage'])->whereIn('part', ['banner', 'logo'])->name('theme.image');
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
        // In record sets besides its parent (rico:Proxy; roadmap phase 24).
        Route::post('/{record}/memberships', [RecordMembershipController::class, 'store'])->name('memberships.store');
        Route::post('/memberships/{membership}/{step}', [RecordMembershipController::class, 'step'])->whereUuid('membership')->whereIn('step', ['up', 'down', 'remove'])->name('memberships.step');
        Route::get('/{record}/tree', [RecordController::class, 'tree'])->name('tree');
        Route::get('/{record}/tree-branch', [RecordController::class, 'treeChildren'])->name('tree-branch');
        Route::get('/{record}/inventory', [RecordController::class, 'inventory'])->name('inventory');
        Route::get('/{record}/finding-aid', [RecordController::class, 'findingAid'])->name('finding-aid');
        Route::get('/{record}/print', [RecordController::class, 'findingAid'])->name('print');
        Route::post('/{record}/calculate-dates', [RecordController::class, 'calculateDates'])->name('calculate-dates');
        Route::get('/{record}/export', [ExportController::class, 'form'])->name('export');
        // What each format leaves out of the unit (roadmap phase 23b).
        Route::get('/{record}/export/left-out.json', [ExportController::class, 'loss'])->name('export.loss');
        Route::get('/{record}/export/{format}', [ExportController::class, 'download'])->where('format', 'ead|ead3|csv|dc|mods|mets|premis')->name('export.download');
        // Provenance and custody history (roadmap phase 9e; Heratio's provenance); the controller checks update to change it.
        Route::get('/{record}/publish-readiness', [WorkflowQueueController::class, 'readiness'])->name('publish-readiness');
        Route::get('/{record}/provenance', [ProvenanceController::class, 'index'])->name('provenance');
        Route::get('/{record}/provenance/export.csv', [ProvenanceController::class, 'csv'])->name('provenance.csv');
        Route::post('/{record}/provenance/overview', [ProvenanceController::class, 'overview'])->name('provenance.overview');
        Route::post('/{record}/provenance/steps/{entry?}', [ProvenanceController::class, 'saveEntry'])->whereUuid('entry')->name('provenance.steps.save');
        Route::post('/{record}/provenance/steps/{entry}/move', [ProvenanceController::class, 'move'])->whereUuid('entry')->name('provenance.steps.move');
        Route::post('/{record}/provenance/steps/{entry}/remove', [ProvenanceController::class, 'removeEntry'])->whereUuid('entry')->name('provenance.steps.destroy');
        Route::post('/{record}/provenance/documents', [ProvenanceController::class, 'addDocument'])->name('provenance.documents.store');
        Route::get('/{record}/provenance/documents/{document}', [ProvenanceController::class, 'document'])->whereUuid('document')->name('provenance.documents.show');
        Route::post('/{record}/provenance/documents/{document}/remove', [ProvenanceController::class, 'removeDocument'])->whereUuid('document')->name('provenance.documents.destroy');
    });

    // Instantiations and their files (rico:Instantiation, WS-007); the controller checks the unit's permissions.
    Route::post('/records/{record}/instantiations', [InstantiationController::class, 'store'])->name('instantiations.store');
    Route::get('/instantiations/{instantiation}', [InstantiationController::class, 'show'])->name('instantiations.show');
    Route::get('/instantiations/{instantiation}/edit', [InstantiationController::class, 'edit'])->name('instantiations.edit');
    Route::post('/instantiations/{instantiation}', [InstantiationController::class, 'update'])->name('instantiations.update');
    Route::post('/instantiations/{instantiation}/copies', [WatermarkController::class, 'remake'])->name('instantiations.remake-copies');
    Route::post('/instantiations/{instantiation}/own-copy', [InstantiationController::class, 'replaceCopy'])->name('instantiations.replace-copy');
    Route::get('/files/{file}', [InstantiationController::class, 'file'])->name('files.show');
    // WebVTT caption tracks of a sound or video instantiation (roadmap phase 22c).
    Route::post('/instantiations/{instantiation}/captions', [CaptionController::class, 'store'])->name('captions.store');
    Route::get('/captions/{track}', [CaptionController::class, 'show'])->whereUuid('track')->name('captions.show');
    Route::post('/captions/{track}/delete', [CaptionController::class, 'destroy'])->whereUuid('track')->name('captions.destroy');
    Route::post('/files/{file}/fixity', [InstantiationController::class, 'fixity'])->name('files.fixity');

    // Activities (rico:Activity); the controller checks the authority permissions.
    Route::middleware('access:read,authority')->prefix('activities')->name('activities.')->group(function () {
        Route::get('/', [ActivityController::class, 'index'])->name('index');
        Route::get('/new', [ActivityController::class, 'create'])->name('create');
        Route::post('/', [ActivityController::class, 'store'])->name('store');
        Route::get('/{activity}', [ActivityController::class, 'show'])->name('show');
        Route::get('/{activity}/edit', [ActivityController::class, 'edit'])->name('edit');
        Route::get('/{activity}/eac-f', [ActivityController::class, 'eac'])->name('eac');
        Route::post('/{activity}', [ActivityController::class, 'update'])->name('update');
    });
    // Rules and mandates (rico:Rule, rico:Mandate; roadmap phase 23b); the controller checks the authority permissions.
    Route::middleware('access:read,authority')->prefix('rules')->name('rules.')->group(function () {
        Route::get('/', [RuleController::class, 'index'])->name('index');
        Route::get('/new', [RuleController::class, 'create'])->name('create');
        Route::post('/', [RuleController::class, 'store'])->name('store');
        Route::get('/{rule}', [RuleController::class, 'show'])->name('show');
        Route::get('/{rule}/edit', [RuleController::class, 'edit'])->name('edit');
        Route::post('/{rule}', [RuleController::class, 'update'])->name('update');
    });

    // Vendors and the work sent to them (Heratio's ahg-vendor, roadmap phase 22d): staff only, under the vendor permissions.
    Route::middleware('access:read,vendor')->prefix('vendors')->name('vendors.')->group(function () {
        Route::get('/', [VendorController::class, 'index'])->name('index');
        Route::get('/list', [VendorController::class, 'list'])->name('list');
        Route::get('/services', [VendorController::class, 'services'])->name('services');
        Route::post('/services', [VendorController::class, 'saveServices'])->name('services.save');
        Route::get('/transactions', [VendorController::class, 'transactions'])->name('transactions');
        Route::get('/add', [VendorController::class, 'create'])->name('create');
        Route::post('/add', [VendorController::class, 'store'])->name('store');
        Route::get('/transactions/{transaction}', [VendorController::class, 'transaction'])->whereUuid('transaction')->name('transaction');
        Route::get('/transactions/{transaction}/edit', [VendorController::class, 'editTransaction'])->whereUuid('transaction')->name('transaction.edit');
        Route::post('/transactions/{transaction}', [VendorController::class, 'updateTransaction'])->whereUuid('transaction')->name('transaction.update');
        Route::post('/transactions/{transaction}/status', [VendorController::class, 'status'])->whereUuid('transaction')->name('transaction.status');
        Route::post('/transactions/{transaction}/items', [VendorController::class, 'addItem'])->whereUuid('transaction')->name('items.add');
        Route::post('/transactions/{transaction}/items/{item}', [VendorController::class, 'updateItem'])->whereUuid(['transaction', 'item'])->name('items.update');
        Route::post('/transactions/{transaction}/items/{item}/remove', [VendorController::class, 'removeItem'])->whereUuid(['transaction', 'item'])->name('items.remove');
        Route::post('/transactions/{transaction}/documents', [VendorController::class, 'attach'])->whereUuid('transaction')->name('attach');
        Route::get('/transactions/{transaction}/documents/{attachment}', [VendorController::class, 'attachment'])->whereUuid(['transaction', 'attachment'])->name('attachment');
        Route::get('/{vendor}', [VendorController::class, 'show'])->whereUuid('vendor')->name('show');
        Route::get('/{vendor}/edit', [VendorController::class, 'edit'])->whereUuid('vendor')->name('edit');
        Route::post('/{vendor}', [VendorController::class, 'update'])->whereUuid('vendor')->name('update');
        Route::post('/{vendor}/delete', [VendorController::class, 'destroy'])->whereUuid('vendor')->name('destroy');
        Route::get('/{vendor}/request', [VendorController::class, 'requestWork'])->whereUuid('vendor')->name('request');
        Route::post('/{vendor}/request', [VendorController::class, 'storeTransaction'])->whereUuid('vendor')->name('transactions.store');
    });

    // Accessions (Heratio's ahg-accession-manage, roadmap phase 9a): staff only, under the accession permissions.
    Route::middleware('access:read,accession')->prefix('accessions')->name('accessions.')->group(function () {
        Route::get('/', [AccessionController::class, 'index'])->name('index');
        Route::get('/export.csv', [AccessionController::class, 'csv'])->name('csv');
        Route::get('/caais', [AccessionController::class, 'caaisAll'])->name('caais-all');
        Route::get('/donors', [AccessionController::class, 'donors'])->name('donors');
        // The intake queue (roadmap phase 9c; PSIS's accessionIntake, Heratio's intake queue and dashboard).
        Route::get('/queue', [AccessionIntakeController::class, 'queue'])->name('queue');
        // The valuation report (roadmap phase 9d; PSIS's and Heratio's valuation report).
        Route::get('/valuation-report', [AccessionAppraisalController::class, 'report'])->name('valuation-report');
        Route::get('/new', [AccessionController::class, 'create'])->name('create');
        Route::post('/', [AccessionController::class, 'store'])->name('store');
        Route::get('/{accession}', [AccessionController::class, 'show'])->name('show');
        Route::get('/{accession}/edit', [AccessionController::class, 'edit'])->name('edit');
        Route::post('/{accession}', [AccessionController::class, 'update'])->name('update');
        Route::get('/{accession}/caais', [AccessionController::class, 'caais'])->name('caais');
        Route::post('/{accession}/rows/{rowKind}', [AccessionController::class, 'addRow'])->where('rowKind', 'events|extents|languages|preservation')->name('rows.store');
        Route::post('/{accession}/rows/{rowKind}/{row}/remove', [AccessionController::class, 'removeRow'])->where('rowKind', 'events|extents|languages|preservation')->whereUuid('row')->name('rows.destroy');
        Route::post('/{accession}/confidentiality', [AccessionController::class, 'confidentiality'])->name('confidentiality');
        Route::post('/{accession}/deaccessions', [AccessionController::class, 'deaccession'])->name('deaccessions.store');
        Route::post('/{accession}/deaccessions/{deaccession}/remove', [AccessionController::class, 'removeDeaccession'])->whereUuid('deaccession')->name('deaccessions.destroy');
        Route::post('/{accession}/description', [AccessionController::class, 'createDescription'])->name('description');
        Route::post('/{accession}/intake', [AccessionIntakeController::class, 'transition'])->name('intake');
        Route::post('/{accession}/assign', [AccessionIntakeController::class, 'assign'])->name('assign');
        Route::post('/{accession}/timeline', [AccessionIntakeController::class, 'note'])->name('timeline');
        Route::post('/{accession}/checklist', [AccessionIntakeController::class, 'checklist'])->name('checklist');
        Route::post('/{accession}/attachments', [AccessionIntakeController::class, 'attach'])->name('attachments.store');
        Route::get('/{accession}/attachments/{attachment}', [AccessionIntakeController::class, 'attachment'])->whereUuid('attachment')->name('attachments.show');
        Route::post('/{accession}/attachments/{attachment}/remove', [AccessionIntakeController::class, 'detach'])->whereUuid('attachment')->name('attachments.destroy');
        // Appraisal, valuation and containers (roadmap phase 9d; PSIS's accessionAppraisal and accessionContainer).
        Route::post('/{accession}/appraisals/{appraisal?}', [AccessionAppraisalController::class, 'save'])->whereUuid('appraisal')->name('appraisals.save');
        Route::post('/{accession}/appraisals/{appraisal}/remove', [AccessionAppraisalController::class, 'remove'])->whereUuid('appraisal')->name('appraisals.destroy');
        Route::post('/{accession}/appraisals/{appraisal}/criteria', [AccessionAppraisalController::class, 'criteria'])->whereUuid('appraisal')->name('appraisals.criteria');
        Route::post('/{accession}/valuations', [AccessionAppraisalController::class, 'value'])->name('valuations.store');
        Route::post('/{accession}/valuations/{valuation}/remove', [AccessionAppraisalController::class, 'removeValuation'])->whereUuid('valuation')->name('valuations.destroy');
        Route::post('/{accession}/containers', [AccessionIntakeController::class, 'containerise'])->name('containers.store');
    });

    // Donor agreements (Heratio's ahg-donor-manage agreements, roadmap phase 9b): staff only, under the agreement permissions.
    Route::middleware('access:read,agreement')->prefix('agreements')->name('agreements.')->group(function () {
        Route::get('/', [AgreementController::class, 'index'])->name('index');
        Route::get('/reminders', [AgreementController::class, 'reminders'])->name('reminders');
        Route::get('/new', [AgreementController::class, 'create'])->name('create');
        Route::post('/', [AgreementController::class, 'store'])->name('store');
        Route::get('/{agreement}', [AgreementController::class, 'show'])->name('show');
        Route::get('/{agreement}/edit', [AgreementController::class, 'edit'])->name('edit');
        Route::post('/{agreement}', [AgreementController::class, 'update'])->name('update');
        Route::post('/{agreement}/rows/{rowKind}', [AgreementController::class, 'addRow'])->where('rowKind', 'rights|restrictions')->name('rows.store');
        Route::post('/{agreement}/rows/{rowKind}/{row}/remove', [AgreementController::class, 'removeRow'])->where('rowKind', 'rights|restrictions|reminders')->whereUuid('row')->name('rows.destroy');
        Route::post('/{agreement}/reminders', [AgreementController::class, 'addReminder'])->name('reminders.store');
        Route::post('/{agreement}/reminders/{reminder}/status', [AgreementController::class, 'reminderStatus'])->whereUuid('reminder')->name('reminders.status');
        Route::post('/{agreement}/documents', [AgreementController::class, 'addDocument'])->name('documents.store');
        Route::get('/{agreement}/documents/{document}', [AgreementController::class, 'document'])->whereUuid('document')->name('documents.show');
        Route::post('/{agreement}/documents/{document}/remove', [AgreementController::class, 'removeDocument'])->whereUuid('document')->name('documents.destroy');
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

    // A sheet of labels for records or containers (roadmap phase 22a).
    Route::get('/labels', [LabelController::class, 'print'])->name('labels.print');

    Route::prefix('imports')->name('imports.')->group(function () {
        Route::get('/', [ImportController::class, 'index'])->name('index');
        Route::get('/new', [ImportController::class, 'create'])->name('create');
        Route::get('/grid', [IngestController::class, 'grid'])->name('grid');
        Route::post('/grid', [IngestController::class, 'saveGrid'])->name('grid.save');
        Route::post('/', [ImportController::class, 'store'])->name('store');
        Route::get('/{import}', [ImportController::class, 'show'])->name('show');
        Route::post('/{import}/commit', [ImportController::class, 'commit'])->name('commit');
        // Phase 22a: mapping a spreadsheet's headings, grid entry, undo, and the import as a BagIt bag.
        Route::get('/{import}/mapping', [IngestController::class, 'mapping'])->name('mapping');
        Route::post('/{import}/mapping', [IngestController::class, 'saveMapping'])->name('mapping.save');
        Route::post('/{import}/undo', [IngestController::class, 'undo'])->name('undo');
        Route::get('/{import}/bag', [IngestController::class, 'bag'])->name('bag');
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
        Route::post('/{concept}/protocol', [ConceptController::class, 'protocol'])->name('protocol');
        Route::post('/{concept}/relation-type', [ConceptController::class, 'relationType'])->name('relation-type');
        Route::post('/{concept}/matches/{match}/remove', [ConceptController::class, 'removeMatch'])->name('matches.destroy');
        Route::post('/{concept}/move', [ConceptController::class, 'move'])->name('move');
        Route::post('/{concept}/merge', [ConceptController::class, 'merge'])->name('merge');
        Route::post('/{concept}/deprecate', [ConceptController::class, 'deprecate'])->name('deprecate');
        Route::post('/{concept}/restore', [ConceptController::class, 'restore'])->name('restore');
    });

    Route::middleware('can:'.Vocab::permission('manage-institution'))->group(function () {
        // The institution's own fields and form templates (roadmap phase 22b).
        Route::prefix('custom-fields')->name('custom-fields.')->whereUuid(['definition', 'template'])->group(function () {
            Route::get('/', [CustomFieldController::class, 'index'])->name('index');
            Route::post('/definitions', [CustomFieldController::class, 'saveDefinition'])->name('definitions.store');
            Route::post('/definitions/{definition}', [CustomFieldController::class, 'saveDefinition'])->name('definitions.update');
            Route::get('/templates/new', [CustomFieldController::class, 'template'])->name('templates.create');
            Route::post('/templates', [CustomFieldController::class, 'saveTemplate'])->name('templates.store');
            Route::get('/templates/{template}', [CustomFieldController::class, 'template'])->name('template');
            Route::post('/templates/{template}', [CustomFieldController::class, 'saveTemplate'])->name('templates.update');
        });
        // Label templates (roadmap phase 22a).
        Route::get('/labels/templates', [LabelController::class, 'templates'])->name('labels.templates');
        Route::post('/labels/templates', [LabelController::class, 'saveTemplate'])->name('labels.templates.store');
        Route::post('/labels/templates/{template}', [LabelController::class, 'saveTemplate'])->whereUuid('template')->name('labels.templates.update');
        // Operations and fixity on a schedule (roadmap phase 12a).
        Route::get('/operations', [OperationsController::class, 'index'])->name('operations.index');
        Route::post('/operations/failed-jobs/{uuid}/{action}', [OperationsController::class, 'failedJob'])->whereUuid('uuid')->whereIn('action', ['retry', 'forget'])->name('operations.failed-job');
        // File formats as PRONOM identifies them (roadmap phase 15a).
        Route::get('/formats', [FormatController::class, 'index'])->name('formats.index');
        Route::post('/formats/identify', [FormatController::class, 'identify'])->name('formats.identify');
        Route::post('/formats/{format}', [FormatController::class, 'update'])->whereUuid('format')->name('formats.update');
        // Preservation planning: normalisation, migration and replication (roadmap phase 15c).
        Route::prefix('preservation')->name('preservation.')->whereUuid(['rule', 'pathway', 'plan', 'target', 'instantiation'])->group(function () {
            Route::get('/', [PreservationController::class, 'index'])->name('index');
            Route::post('/settings', [PreservationController::class, 'saveSettings'])->name('settings');
            Route::post('/normalise', [PreservationController::class, 'normaliseAll'])->name('normalise-all');
            Route::post('/instantiations/{instantiation}/normalise', [PreservationController::class, 'normalise'])->name('normalise');
            Route::post('/rules', [PreservationController::class, 'saveRule'])->name('rules.store');
            Route::post('/rules/{rule}', [PreservationController::class, 'saveRule'])->name('rules.update');
            Route::post('/rules/{rule}/delete', [PreservationController::class, 'deleteRule'])->name('rules.delete');
            Route::post('/pathways', [PreservationController::class, 'savePathway'])->name('pathways.store');
            Route::post('/pathways/{pathway}', [PreservationController::class, 'savePathway'])->name('pathways.update');
            Route::post('/plans', [PreservationController::class, 'savePlan'])->name('plans.store');
            Route::get('/plans/{plan}', [PreservationController::class, 'plan'])->name('plans.show');
            Route::post('/plans/{plan}', [PreservationController::class, 'savePlan'])->name('plans.update');
            Route::post('/plans/{plan}/{action}', [PreservationController::class, 'planAction'])->whereIn('action', ['approve', 'start', 'cancel', 'retry'])->name('plans.action');
            Route::post('/replication', [PreservationController::class, 'saveTarget'])->name('targets.store');
            Route::post('/replication/{target}', [PreservationController::class, 'saveTarget'])->name('targets.update');
            Route::post('/replication/{target}/{operation}', [PreservationController::class, 'runTarget'])->whereIn('operation', ['sync', 'verify'])->name('targets.run');
        });
        Route::get('/fixity', [OperationsController::class, 'schedules'])->name('fixity.schedules');
        Route::post('/fixity', [OperationsController::class, 'saveSchedule'])->name('fixity.schedules.store');
        Route::post('/fixity/{schedule}', [OperationsController::class, 'saveSchedule'])->whereUuid('schedule')->name('fixity.schedules.update');
        Route::post('/fixity/{schedule}/run', [OperationsController::class, 'runNow'])->whereUuid('schedule')->name('fixity.schedules.run');
        Route::get('/fixity/runs/{run}', [OperationsController::class, 'run'])->whereUuid('run')->name('fixity.run');
        Route::get('/fixity/failures', [OperationsController::class, 'failures'])->name('fixity.failures');
        Route::post('/fixity/failures/{failure}', [OperationsController::class, 'updateFailure'])->whereUuid('failure')->name('fixity.failures.update');
        // Webhooks (roadmap phase 22c).
        Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
        Route::post('/webhooks', [WebhookController::class, 'save'])->name('webhooks.store');
        Route::post('/webhooks/{hook}', [WebhookController::class, 'save'])->whereUuid('hook')->name('webhooks.update');
        // Watched folders and what was taken in (roadmap phase 22a).
        Route::get('/intake', [IntakeController::class, 'index'])->name('intake.index');
        Route::post('/intake', [IntakeController::class, 'save'])->name('intake.store');
        Route::post('/intake/{folder}', [IntakeController::class, 'save'])->whereUuid('folder')->name('intake.update');
        Route::post('/intake/{folder}/run', [IntakeController::class, 'run'])->whereUuid('folder')->name('intake.run');
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
        // The watermark on images' reference copies, and making the copies again (roadmap phase 5).
        // The accession settings, checklist templates and numbering (roadmap phase 9c; Heratio's intake configuration and numbering).
        Route::get('/settings/accessions', [AccessionIntakeController::class, 'settings'])->name('accessions.settings');
        Route::post('/settings/accessions', [AccessionIntakeController::class, 'updateSettings'])->name('accessions.settings.update');
        Route::post('/settings/accessions/templates/{template?}', [AccessionIntakeController::class, 'saveTemplate'])->whereUuid('template')->name('accessions.templates.save');
        Route::post('/settings/accessions/templates/{template}/remove', [AccessionIntakeController::class, 'removeTemplate'])->whereUuid('template')->name('accessions.templates.destroy');
        Route::post('/settings/accessions/appraisal-templates/{template?}', [AccessionAppraisalController::class, 'saveTemplate'])->whereUuid('template')->name('accessions.appraisal-templates.save');
        Route::post('/settings/accessions/appraisal-templates/{template}/remove', [AccessionAppraisalController::class, 'removeTemplate'])->whereUuid('template')->name('accessions.appraisal-templates.destroy');
        Route::get('/settings/watermark', [WatermarkController::class, 'edit'])->name('watermark.edit');
        Route::post('/settings/watermark', [WatermarkController::class, 'update'])->name('watermark.update');
        Route::post('/settings/watermark/remake', [WatermarkController::class, 'remakeAll'])->name('watermark.remake-all');
    });

    // Dates, names and identifiers of any described entity (ADR 0006); each controller checks update on the entity.
    // A concept has labels instead (ConceptController).
    foreach (['dates' => [DateController::class, 'date'], 'names' => [NameController::class, 'name'], 'identifiers' => [IdentifierController::class, 'identifier']] as $part => [$controller, $parameter]) {
        Route::prefix("{kind}/{id}/$part")->where(['kind' => 'records|repositories|places|agents|activities|instantiations|containers|accessions|agreements|rules', 'id' => '[0-9a-f-]{36}', $parameter => '[0-9a-f-]{36}'])->name("$part.")
            ->group(function () use ($controller, $parameter) {
                Route::post('/', [$controller, 'store'])->name('store');
                Route::post("/{{$parameter}}", [$controller, 'update'])->name('update');
                Route::post("/{{$parameter}}/remove", [$controller, 'destroy'])->name('destroy');
            });
    }

    // Requests for files available on request (roadmap phase 7; Heratio's ahg-access-request).
    Route::get('/instantiations/{instantiation}/request-access', [AccessRequestController::class, 'create'])->whereUuid('instantiation')->name('access-requests.create');
    Route::post('/instantiations/{instantiation}/request-access', [AccessRequestController::class, 'store'])->whereUuid('instantiation')->name('access-requests.store');
    Route::get('/access-requests', [AccessRequestController::class, 'index'])->name('access-requests.index');
    Route::get('/access-requests/new', [AccessRequestController::class, 'newRequest'])->name('access-requests.new');
    Route::post('/access-requests', [AccessRequestController::class, 'storeGeneral'])->name('access-requests.store-general');
    Route::get('/access-requests/mine', [AccessRequestController::class, 'mine'])->name('access-requests.mine');
    // Checkout, one's orders and downloads; the orders and settings of the shop for who may manage it (phase 19a).
    Route::get('/cart/checkout', [ShopController::class, 'checkoutForm'])->name('shop.checkout');
    Route::post('/cart/checkout', [ShopController::class, 'checkout'])->name('shop.checkout.store');
    Route::prefix('shop')->name('shop.')->whereUuid(['order', 'type', 'file'])->group(function () {
        Route::get('/my-orders', [ShopController::class, 'myOrders'])->name('orders.mine');
        Route::get('/orders', [ShopController::class, 'orders'])->name('orders');
        Route::get('/orders/{order}', [ShopController::class, 'order'])->name('orders.show');
        Route::post('/orders/{order}/pay', [ShopController::class, 'pay'])->name('orders.pay');
        Route::post('/orders/{order}/status', [ShopController::class, 'setStatus'])->name('orders.status');
        Route::get('/download/{token}/{file}', [ShopController::class, 'download'])->where('token', '[0-9a-f]{64}')->name('download');
        Route::get('/settings', [ShopController::class, 'settings'])->name('settings');
        Route::post('/settings', [ShopController::class, 'saveSettings'])->name('settings.save');
        Route::post('/settings/products', [ShopController::class, 'saveProduct'])->name('settings.products.store');
        Route::post('/settings/products/{type}', [ShopController::class, 'saveProduct'])->name('settings.products.update');
    });
    // A researcher's own pages (roadmap phase 19b, ADR 0017): registration, booking the reading room, requests.
    Route::prefix('research')->name('research.')->whereUuid(['booking', 'material', 'equipment', 'file'])->group(function () {
        Route::get('/', [ResearcherController::class, 'profile'])->name('profile');
        Route::post('/register', [ResearcherController::class, 'register'])->name('register');
        Route::post('/profile', [ResearcherController::class, 'update'])->name('profile.update');
        Route::post('/renew', [ResearcherController::class, 'renew'])->name('renew');
        Route::get('/book', [ResearcherController::class, 'bookForm'])->name('book');
        Route::post('/book', [ResearcherController::class, 'book'])->name('book.store');
        Route::get('/bookings/{booking}', [ResearcherController::class, 'booking'])->name('bookings.show');
        Route::post('/bookings/{booking}/cancel', [ResearcherController::class, 'cancel'])->name('bookings.cancel');
        Route::post('/bookings/{booking}/records', [ResearcherController::class, 'addRecord'])->name('bookings.records');
        Route::post('/requests/{material}/messages', [ResearcherController::class, 'write'])->name('requests.write');
        Route::post('/equipment/{equipment}/book', [ResearcherController::class, 'bookEquipment'])->name('equipment.book');
        Route::get('/files/{file}', [ResearcherController::class, 'file'])->name('files.show');
    });
    // Reproductions, bibliographies and saved searches (roadmap phase 19c, ADR 0017).
    Route::prefix('research')->name('research.')->whereUuid(['reproduction', 'item', 'bibliography', 'entry', 'search'])->group(function () {
        Route::get('/reproductions', [ResearchToolsController::class, 'reproductions'])->name('reproductions.index');
        Route::post('/reproductions', [ResearchToolsController::class, 'createReproduction'])->name('reproductions.store');
        Route::get('/reproductions/{reproduction}', [ResearchToolsController::class, 'reproduction'])->name('reproductions.show');
        Route::post('/reproductions/{reproduction}/{step}', [ResearchToolsController::class, 'reproductionStep'])->whereIn('step', ['item', 'submit', 'status', 'payment', 'handle'])->name('reproductions.step');
        Route::post('/reproduction-items/{item}/{step}', [ResearchToolsController::class, 'reproductionItem'])->whereIn('step', ['remove', 'finish'])->name('reproductions.item');
        Route::get('/reproduction-files/{token}', [ResearchToolsController::class, 'reproductionDownload'])->where('token', '[0-9a-f]{64}')->name('reproductions.download');
        Route::get('/bibliographies', [ResearchToolsController::class, 'bibliographies'])->name('bibliographies.index');
        Route::post('/bibliographies', [ResearchToolsController::class, 'saveBibliography'])->name('bibliographies.store');
        Route::get('/bibliographies/{bibliography}', [ResearchToolsController::class, 'bibliography'])->name('bibliographies.show');
        Route::post('/bibliographies/{bibliography}', [ResearchToolsController::class, 'saveBibliography'])->name('bibliographies.update');
        Route::post('/bibliographies/{bibliography}/{step}', [ResearchToolsController::class, 'bibliographyStep'])->whereIn('step', ['entry', 'record', 'import', 'share', 'delete'])->name('bibliographies.step');
        Route::get('/bibliographies/{bibliography}/export/{format}', [ResearchToolsController::class, 'exportBibliography'])->name('bibliographies.export');
        Route::post('/bibliography-entries/{entry}/{step}', [ResearchToolsController::class, 'entryStep'])->whereIn('step', ['update', 'remove'])->name('bibliographies.entry');
        Route::post('/favourites-to-bibliography', [ResearchToolsController::class, 'favouritesToBibliography'])->name('bibliographies.from-favourites');
        Route::get('/searches', [ResearchToolsController::class, 'searches'])->name('searches.index');
        Route::post('/searches', [ResearchToolsController::class, 'saveSearch'])->name('searches.store');
        Route::post('/searches/{search}', [ResearchToolsController::class, 'saveSearch'])->name('searches.update');
        Route::match(['get', 'post'], '/searches/{search}/{step}', [ResearchToolsController::class, 'searchStep'])->whereIn('step', ['run', 'snapshot', 'diff', 'delete'])->name('searches.step');
    });
    // A researcher's own working pages (roadmap phase 19d, ADR 0017): collections, annotations, notebooks, the diary, research workspaces.
    Route::prefix('research')->name('research.')->whereUuid(['collection', 'item', 'annotation', 'notebook', 'entry', 'workspace', 'member', 'resource', 'file', 'id'])->group(function () {
        Route::get('/collections', [ResearchNotesController::class, 'collections'])->name('collections.index');
        Route::post('/collections', [ResearchNotesController::class, 'saveCollection'])->name('collections.store');
        Route::get('/collections/{collection}', [ResearchNotesController::class, 'collection'])->name('collections.show');
        Route::post('/collections/{collection}', [ResearchNotesController::class, 'saveCollection'])->name('collections.update');
        Route::get('/collections/{collection}/finding-aid', [ResearchNotesController::class, 'findingAid'])->name('collections.finding-aid');
        Route::post('/collections/{collection}/{step}', [ResearchNotesController::class, 'collectionStep'])->whereIn('step', ['link', 'import', 'share', 'delete'])->name('collections.step');
        Route::post('/collection-items/{item}/{step}', [ResearchNotesController::class, 'collectionItem'])->whereIn('step', ['update', 'remove'])->name('collections.item');
        Route::post('/add-to-collection/{kind}/{id}', [ResearchNotesController::class, 'addToCollection'])->name('collections.add');
        Route::post('/favourites-to-collection', [ResearchNotesController::class, 'favouritesToCollection'])->name('collections.from-favourites');
        Route::get('/annotations', [ResearchNotesController::class, 'annotations'])->name('annotations.index');
        Route::post('/annotations/{annotation}/delete', [ResearchNotesController::class, 'deleteAnnotation'])->name('annotations.delete');
        Route::get('/annotate/{kind}/{id}', [ResearchNotesController::class, 'studio'])->name('studio');
        Route::post('/annotate/{kind}/{id}', [ResearchNotesController::class, 'saveAnnotation'])->name('studio.save');
        Route::get('/annotate/{kind}/{id}/annotations.json', [ResearchNotesController::class, 'annotationPage'])->name('studio.page');
        Route::get('/notebooks', [ResearchNotesController::class, 'notebooks'])->name('notebooks.index');
        Route::post('/notebooks', [ResearchNotesController::class, 'saveNotebook'])->name('notebooks.store');
        Route::get('/notebooks/{notebook}', [ResearchNotesController::class, 'notebook'])->name('notebooks.show');
        Route::post('/notebooks/{notebook}', [ResearchNotesController::class, 'saveNotebook'])->name('notebooks.update');
        Route::post('/notebooks/{notebook}/{step}', [ResearchNotesController::class, 'notebookStep'])->whereIn('step', ['item', 'delete'])->name('notebooks.step');
        Route::post('/notebook-items/{item}/{step}', [ResearchNotesController::class, 'notebookItem'])->whereIn('step', ['update', 'remove'])->name('notebooks.item');
        Route::get('/diary', [ResearchNotesController::class, 'diary'])->name('diary.index');
        Route::post('/diary', [ResearchNotesController::class, 'saveDiaryEntry'])->name('diary.store');
        Route::post('/diary/{entry}', [ResearchNotesController::class, 'saveDiaryEntry'])->name('diary.update');
        Route::post('/diary/{entry}/delete', [ResearchNotesController::class, 'deleteDiaryEntry'])->name('diary.delete');
        Route::get('/workspaces', [ResearchWorkspaceController::class, 'index'])->name('workspaces.index');
        Route::post('/workspaces', [ResearchWorkspaceController::class, 'store'])->name('workspaces.store');
        Route::get('/workspaces/{workspace}', [ResearchWorkspaceController::class, 'show'])->name('workspaces.show');
        Route::post('/workspaces/{workspace}/{step}', [ResearchWorkspaceController::class, 'step'])->whereIn('step', ['update', 'invite', 'resource', 'file', 'delete'])->name('workspaces.step');
        Route::post('/workspace-members/{member}/{step}', [ResearchWorkspaceController::class, 'member'])->whereIn('step', ['accept', 'decline', 'remove', 'role'])->name('workspaces.member');
        Route::get('/workspace-resources/{resource}', [ResearchWorkspaceController::class, 'resource'])->name('workspaces.resource');
        Route::post('/workspace-resources/{resource}/remove', [ResearchWorkspaceController::class, 'removeResource'])->name('workspaces.resource.remove');
        Route::get('/workspace-files/{file}', [ResearchWorkspaceController::class, 'file'])->name('workspaces.file');
        Route::post('/workspace-files/{file}/remove', [ResearchWorkspaceController::class, 'removeFile'])->name('workspaces.file.remove');
    });
    // Researcher submissions and their review (roadmap phase 19e, ADR 0017).
    Route::prefix('research/submissions')->name('research.submissions.')->whereUuid(['submission', 'item', 'file'])->group(function () {
        Route::get('/', [SubmissionController::class, 'index'])->name('index');
        Route::post('/', [SubmissionController::class, 'store'])->name('store');
        Route::post('/from-collection', [SubmissionController::class, 'fromCollection'])->name('from-collection');
        Route::post('/import', [SubmissionController::class, 'import'])->name('import');
        Route::get('/review', [SubmissionController::class, 'pending'])->name('pending');
        Route::get('/{submission}', [SubmissionController::class, 'show'])->name('show');
        Route::post('/{submission}', [SubmissionController::class, 'update'])->name('update');
        Route::post('/{submission}/items', [SubmissionController::class, 'saveItem'])->name('items.store');
        Route::post('/{submission}/items/{item}', [SubmissionController::class, 'saveItem'])->name('items.update');
        Route::post('/{submission}/review/{action}', [SubmissionController::class, 'review'])->whereIn('action', ['take', 'comment', 'return', 'approve', 'reject'])->name('review');
        Route::post('/{submission}/publish', [SubmissionController::class, 'publish'])->name('publish');
        Route::post('/{submission}/{step}', [SubmissionController::class, 'step'])->whereIn('step', ['submit', 'delete'])->name('step');
        Route::post('/items/{item}/{step}', [SubmissionController::class, 'itemStep'])->whereIn('step', ['remove', 'file'])->name('item');
        Route::get('/files/{file}', [SubmissionController::class, 'file'])->name('files.show');
        Route::post('/files/{file}/remove', [SubmissionController::class, 'removeFile'])->name('files.remove');
    });
    // Research projects and working together on them (roadmap phase 19f, ADR 0017).
    Route::prefix('research')->name('research.')->whereUuid(['project', 'member', 'resource', 'milestone', 'output', 'item', 'notebook', 'discussion', 'comment', 'id'])->group(function () {
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::post('/projects/{project}/milestones/{milestone?}', [ProjectController::class, 'milestone'])->name('projects.milestones');
        Route::post('/projects/{project}/team/{member?}', [ProjectController::class, 'team'])->name('projects.team');
        Route::post('/projects/{project}/outputs/{output?}', [ProjectController::class, 'output'])->name('projects.outputs');
        Route::get('/projects/{project}/export/{format}', [ProjectController::class, 'export'])->whereIn('format', array_keys(ProjectExport::FORMATS))->name('projects.export');
        Route::post('/projects/{project}/evidence-comments', [ProjectController::class, 'evidenceComment'])->name('projects.evidence-comments');
        Route::get('/projects/{project}/poll', [ProjectController::class, 'poll'])->name('projects.poll');
        Route::post('/projects/{project}/{step}', [ProjectController::class, 'step'])->whereIn('step', ['update', 'invite', 'resource', 'clip', 'share', 'delete'])->name('projects.step');
        Route::post('/project-members/{member}/{step}', [ProjectController::class, 'member'])->whereIn('step', ['accept', 'decline', 'remove', 'role'])->name('projects.member');
        Route::get('/project-resources/{resource}', [ProjectController::class, 'resource'])->name('projects.resource');
        Route::post('/project-resources/{resource}/remove', [ProjectController::class, 'removeResource'])->name('projects.resource.remove');
        Route::post('/project-parts/{kind}/{id}/remove', [ProjectController::class, 'remove'])->whereIn('kind', ['milestones', 'team', 'outputs'])->name('projects.part.remove');
        Route::post('/project-clipboard/{item}/{step}', [ProjectController::class, 'clipboard'])->whereIn('step', ['pin', 'notes', 'remove'])->name('projects.clipboard');
        Route::post('/evidence-comments/{comment}/resolve', [ProjectController::class, 'resolveEvidenceComment'])->name('projects.evidence-comments.resolve');
        Route::post('/assign/{kind}/{id}', [ProjectController::class, 'assign'])->whereIn('kind', ['collection', 'annotation', 'diary', 'bibliography', 'submission', 'workspace-file', 'search', 'booking'])->name('projects.assign');
        Route::post('/notebooks/{notebook}/promote', [ProjectController::class, 'promote'])->name('notebooks.promote');
        Route::post('/favourites-to-project', [ProjectController::class, 'favouritesToProject'])->name('projects.from-favourites');
        Route::post('/discuss/{home}/{id}', [ProjectController::class, 'discuss'])->whereIn('home', ['projects', 'workspaces'])->name('discussions.store');
        Route::post('/discussions/{discussion}/{step}', [ProjectController::class, 'discussion'])->whereIn('step', ['update', 'pin', 'resolve', 'delete'])->name('discussions.step');
        Route::post('/comment/{kind}/{id}', [ProjectController::class, 'comment'])->whereIn('kind', ['annotation', 'diary', 'collection', 'report', 'report-section'])->name('comments.store');
        Route::post('/comments/{comment}/{step}', [ProjectController::class, 'commentStep'])->whereIn('step', ['resolve', 'delete'])->name('comments.step');
    });
    // Funding, grant applications, data management plans, ethics, impact, compliance, replication packs, DOIs and ORCID (roadmap phase 19h, ADR 0017).
    Route::prefix('research')->name('research.')->whereUuid(['project', 'funding', 'draft', 'plan', 'approval', 'doi', 'call', 'output', 'id'])->group(function () {
        Route::get('/projects/{project}/funding', [ResearchFundingController::class, 'funding'])->name('funding');
        Route::post('/projects/{project}/funding/{funding?}', [ResearchFundingController::class, 'saveFunding'])->name('funding.save');
        Route::post('/projects/{project}/grants', [ResearchFundingController::class, 'startGrant'])->name('grants.store');
        Route::get('/grants/{draft}', [ResearchFundingController::class, 'grant'])->name('grants.show');
        Route::post('/grants/{draft}', [ResearchFundingController::class, 'saveGrant'])->name('grants.save');
        Route::get('/projects/{project}/plans', [ResearchFundingController::class, 'plans'])->name('projects.plans');
        Route::post('/projects/{project}/plans', [ResearchFundingController::class, 'startPlan'])->name('projects.plans.store');
        Route::get('/projects/{project}/plans/{plan}', [ResearchFundingController::class, 'plan'])->name('projects.plans.show');
        Route::post('/projects/{project}/plans/{plan}', [ResearchFundingController::class, 'savePlan'])->name('projects.plans.save');
        Route::get('/projects/{project}/ethics', [ResearchFundingController::class, 'ethics'])->name('ethics');
        Route::post('/projects/{project}/ethics/{approval?}', [ResearchFundingController::class, 'saveEthics'])->name('ethics.save');
        Route::get('/projects/{project}/impact', [ResearchFundingController::class, 'impact'])->name('impact');
        Route::post('/projects/{project}/impact/{step}', [ResearchFundingController::class, 'impactStep'])->whereIn('step', ['scan', 'record'])->name('impact.step');
        Route::get('/projects/{project}/compliance', [ResearchFundingController::class, 'compliance'])->name('compliance');
        Route::post('/projects/{project}/replication-pack', [ResearchFundingController::class, 'replication'])->name('replication');
        Route::post('/projects/{project}/dois', [ResearchFundingController::class, 'registerDoi'])->name('dois.store');
        Route::post('/dois/{doi}/publish', [ResearchFundingController::class, 'publishDoi'])->name('dois.publish');
        Route::post('/funding-parts/{kind}/{id}/remove', [ResearchFundingController::class, 'remove'])->whereIn('kind', ['funding', 'grants', 'plans', 'ethics', 'impact'])->name('funding.part.remove');
        Route::get('/calls', [ResearchFundingController::class, 'calls'])->name('calls');
        Route::post('/calls/{call?}', [ResearchFundingController::class, 'saveCall'])->name('calls.save');
        Route::post('/calls/{call}/remove', [ResearchFundingController::class, 'removeCall'])->name('calls.remove');
        Route::post('/orcid/{step}', [ResearchFundingController::class, 'orcidStep'])->whereIn('step', ['link', 'unlink', 'pull'])->name('orcid.step');
        Route::get('/orcid/callback', [ResearchFundingController::class, 'orcidCallback'])->name('orcid.callback');
        Route::post('/outputs/{output}/orcid', [ResearchFundingController::class, 'orcidPush'])->name('orcid.push');
        Route::get('/settings/services', [ResearchFundingController::class, 'settings'])->name('services.settings');
        Route::post('/settings/services', [ResearchFundingController::class, 'saveSettings'])->name('services.settings.update');
    });
    // Lectures, training, notices, the inbox, research documents, print templates, quotas and rights policies (roadmap phase 19j, ADR 0017).
    Route::prefix('research')->name('research.')->whereUuid(['lecture', 'course', 'enrolment', 'notice', 'item', 'template', 'document', 'policy', 'record', 'id'])->group(function () {
        Route::get('/teaching', [TeachingController::class, 'index'])->name('teaching');
        Route::post('/lectures/{lecture?}', [TeachingController::class, 'saveLecture'])->name('lectures.save');
        Route::get('/lectures/{lecture}', [TeachingController::class, 'lecture'])->name('lectures.show');
        Route::post('/lectures/{lecture}/{step}/{id?}', [TeachingController::class, 'lectureStep'])->whereIn('step', ['section', 'resource', 'remove-section', 'remove-resource', 'delete'])->name('lectures.step');
        Route::post('/courses/{course?}', [TeachingController::class, 'saveCourse'])->name('courses.save');
        Route::get('/courses/{course}', [TeachingController::class, 'course'])->name('courses.show');
        Route::post('/courses/{course}/{step}/{id?}', [TeachingController::class, 'courseStep'])->whereIn('step', ['module', 'assessment', 'enrol', 'enrol-learner', 'remove-module', 'remove-enrolment', 'delete'])->name('courses.step');
        Route::post('/enrolments/{enrolment}/{step}/{id?}', [TeachingController::class, 'enrolmentStep'])->whereIn('step', ['done', 'undo', 'submit'])->name('enrolments.step');
        Route::get('/notifications', [ResearchDeskController::class, 'notices'])->name('notifications');
        Route::get('/notifications/{notice}', [ResearchDeskController::class, 'openNotice'])->name('notifications.open');
        Route::post('/notifications/preferences', [ResearchDeskController::class, 'preferences'])->name('notifications.preferences');
        Route::post('/notifications/read/{notice?}', [ResearchDeskController::class, 'read'])->name('notifications.read');
        Route::get('/inbox', [ResearchDeskController::class, 'inbox'])->name('inbox');
        Route::post('/inbox', [ResearchDeskController::class, 'capture'])->name('inbox.capture');
        Route::post('/inbox/{item}/{step}', [ResearchDeskController::class, 'inboxStep'])->whereIn('step', ['sort', 'project', 'archive', 'restore', 'delete'])->name('inbox.step');
        Route::get('/documents', [ResearchDeskController::class, 'documents'])->name('documents');
        Route::post('/document-templates/{template?}', [ResearchDeskController::class, 'saveTemplate'])->name('documents.templates.save');
        Route::get('/documents/{template}/{document?}', [ResearchDeskController::class, 'document'])->name('documents.edit');
        Route::post('/documents/{template}/{document?}', [ResearchDeskController::class, 'saveDocument'])->name('documents.save');
        Route::get('/print-templates', [ResearchDeskController::class, 'printTemplates'])->name('print-templates');
        Route::post('/print-templates/{template?}', [ResearchDeskController::class, 'savePrintTemplate'])->name('print-templates.save');
        Route::get('/quotas', [ResearchDeskController::class, 'quotas'])->name('quotas');
        Route::post('/quotas/{policy?}', [ResearchDeskController::class, 'saveQuota'])->name('quotas.save');
        Route::get('/records/{record}/rights-policies', [ResearchDeskController::class, 'rights'])->name('rights-policies');
        Route::post('/records/{record}/rights-policies/{policy?}', [ResearchDeskController::class, 'saveRights'])->name('rights-policies.save');
    });
    // The reasoning of a research project (roadmap phase 19i, ADR 0017): claims, the knowledge graph, hypotheses, contradictions,
    // the decision log, research memory and analysis results.
    Route::prefix('research')->name('research.reasoning.')->whereUuid(['project', 'claim', 'evidence', 'hypothesis', 'finding', 'decision', 'item', 'result', 'link', 'code'])->group(function () {
        Route::get('/projects/{project}/claims', [ReasoningController::class, 'claims'])->name('claims');
        Route::post('/projects/{project}/claims', [ReasoningController::class, 'saveClaim'])->name('claims.store');
        Route::post('/projects/{project}/claims/review', [ReasoningController::class, 'review'])->name('claims.review');
        Route::get('/projects/{project}/claims/{claim}', [ReasoningController::class, 'claim'])->name('claim');
        Route::post('/projects/{project}/claims/{claim}', [ReasoningController::class, 'saveClaim'])->name('claims.update');
        Route::post('/projects/{project}/claims/{claim}/{step}', [ReasoningController::class, 'claimStep'])->whereIn('step', ['status', 'evidence', 'delete'])->name('claims.step');
        Route::post('/claim-evidence/{evidence}/remove', [ReasoningController::class, 'detach'])->name('evidence.remove');
        Route::get('/projects/{project}/graph', [ReasoningController::class, 'graph'])->name('graph');
        Route::get('/projects/{project}/hypotheses', [ReasoningController::class, 'hypotheses'])->name('hypotheses');
        Route::post('/projects/{project}/hypotheses/{hypothesis?}', [ReasoningController::class, 'saveHypothesis'])->name('hypotheses.save');
        Route::post('/hypotheses/{hypothesis}/{step}', [ReasoningController::class, 'hypothesisStep'])->whereIn('step', ['evidence', 'delete'])->name('hypotheses.step');
        Route::post('/hypothesis-evidence/{evidence}/remove', [ReasoningController::class, 'detachHypothesis'])->name('hypotheses.evidence.remove');
        Route::get('/projects/{project}/contradictions', [ReasoningController::class, 'contradictions'])->name('contradictions');
        Route::post('/projects/{project}/contradictions/scan', [ReasoningController::class, 'scan'])->name('contradictions.scan');
        Route::post('/contradictions/{finding}/{status}', [ReasoningController::class, 'finding'])->whereIn('status', ['open', 'dismissed', 'resolved'])->name('contradictions.status');
        Route::get('/projects/{project}/decisions', [ReasoningController::class, 'decisions'])->name('decisions');
        Route::post('/projects/{project}/decisions/{decision?}', [ReasoningController::class, 'saveDecision'])->name('decisions.save');
        Route::post('/decisions/{decision}/delete', [ReasoningController::class, 'deleteDecision'])->name('decisions.delete');
        Route::get('/memory', [ReasoningController::class, 'memory'])->name('memory');
        Route::post('/memory/{item?}', [ReasoningController::class, 'saveMemory'])->name('memory.save');
        Route::post('/memory-step/{step}/{item?}', [ReasoningController::class, 'memoryStep'])->whereIn('step', ['delete', 'accept'])->name('memory.step');
        Route::get('/projects/{project}/analysis', [ReasoningController::class, 'analysis'])->name('analysis');
        Route::post('/projects/{project}/analysis/{result?}', [ReasoningController::class, 'saveResult'])->name('analysis.save');
        Route::match(['get', 'post'], '/analysis-results/{result}/{step}', [ReasoningController::class, 'resultStep'])->whereIn('step', ['claim', 'delete', 'artifact'])->name('analysis.step');
        Route::post('/analysis-claims/{link}/remove', [ReasoningController::class, 'unlink'])->name('analysis.unlink');
        Route::post('/projects/{project}/codes/{code?}', [ReasoningController::class, 'code'])->name('analysis.code');
    });
    // The analysis of research (roadmap phase 19i, ADR 0017): snapshots, timeline, map, field alerts, the RiC bridge, source
    // assessments, entity resolution, searches across fonds, research reports and the use of the research services.
    Route::prefix('research')->name('research.')->whereUuid(['project', 'snapshot', 'id', 'resolution', 'report', 'section', 'review', 'record'])->group(function () {
        Route::get('/projects/{project}/snapshots', [AnalysisController::class, 'snapshots'])->name('analysis.snapshots');
        Route::post('/projects/{project}/snapshots/{step}/{snapshot?}', [AnalysisController::class, 'snapshotStep'])->whereIn('step', ['take', 'retake', 'freeze', 'archive', 'delete'])->name('analysis.snapshots.step');
        Route::get('/snapshots/{snapshot}', [AnalysisController::class, 'snapshot'])->name('analysis.snapshot');
        Route::get('/projects/{project}/timeline', [AnalysisController::class, 'timeline'])->name('analysis.timeline');
        Route::get('/projects/{project}/map', [AnalysisController::class, 'map'])->name('analysis.map');
        Route::post('/projects/{project}/plot/{kind}/{id?}', [AnalysisController::class, 'plot'])->whereIn('kind', ['events', 'points', 'from-collection'])->name('analysis.plot');
        Route::get('/projects/{project}/field-alerts', [AnalysisController::class, 'alerts'])->name('analysis.alerts');
        Route::post('/projects/{project}/field-alerts/{step}', [AnalysisController::class, 'alertStep'])->whereIn('step', ['scan', 'seed', 'watch', 'unwatch', 'read'])->name('analysis.alerts.step');
        Route::post('/projects/{project}/describe-in-catalogue', [AnalysisController::class, 'describe'])->name('analysis.describe');
        Route::get('/assess/{record}', [AnalysisController::class, 'assessment'])->name('assess');
        Route::post('/assess/{record}', [AnalysisController::class, 'assess'])->name('assess.store');
        Route::get('/entity-resolution', [AnalysisController::class, 'resolutions'])->name('resolutions');
        Route::post('/entity-resolution/{step}/{resolution?}', [AnalysisController::class, 'resolution'])->whereIn('step', ['propose', 'accept', 'reject', 'withdraw'])->name('resolutions.step');
        Route::get('/across-fonds', [AnalysisController::class, 'across'])->name('across');
        Route::get('/reports', [AnalysisController::class, 'reports'])->name('reports.index');
        Route::post('/reports', [AnalysisController::class, 'createReport'])->name('reports.store');
        Route::get('/reports/{report}', [AnalysisController::class, 'report'])->name('reports.show');
        Route::post('/reports/{report}/{step}', [AnalysisController::class, 'reportStep'])->whereIn('step', ['update', 'section', 'fill', 'review', 'delete'])->name('reports.step');
        Route::post('/report-sections/{section}/{step}', [AnalysisController::class, 'sectionStep'])->whereIn('step', ['update', 'up', 'down', 'remove'])->name('reports.section');
        Route::post('/peer-reviews/{review}/{step}', [AnalysisController::class, 'peerReview'])->whereIn('step', ['accept', 'decline', 'complete'])->name('reports.peer-review');
        Route::get('/analytics', [AnalysisController::class, 'analytics'])->name('analytics');
    });
    // The studios of a research project (roadmap phase 19g, ADR 0017), its time machine, and research journals.
    Route::prefix('research')->name('research.')->whereUuid(['project', 'doc', 'id', 'comment', 'submission', 'protocol', 'argumentStep', 'journal'])->group(function () {
        Route::get('/projects/{project}/writing', [StudioController::class, 'writing'])->name('studios.writing');
        Route::post('/projects/{project}/writing/{step}/{id?}', [StudioController::class, 'writingStep'])->whereIn('step', ['doc', 'section', 'version', 'update-section', 'pull', 'remove-section'])->name('studios.writing.step');
        Route::get('/writing/{doc}', [StudioController::class, 'doc'])->name('studios.doc');
        Route::get('/projects/{project}/review', [StudioController::class, 'review'])->name('studios.review');
        Route::post('/projects/{project}/review/{comment?}', [StudioController::class, 'reviewStep'])->name('studios.review.step');
        Route::get('/projects/{project}/publication', [StudioController::class, 'publication'])->name('studios.publication');
        Route::post('/projects/{project}/publication/{step}/{submission?}', [StudioController::class, 'publicationStep'])->whereIn('step', ['save', 'move', 'requirement', 'tick', 'unrequire', 'response', 'unrespond', 'delete'])->name('studios.publication.step');
        Route::get('/projects/{project}/method', [StudioController::class, 'method'])->name('studios.method');
        Route::post('/projects/{project}/method/{protocol?}', [StudioController::class, 'saveProtocol'])->name('studios.method.save');
        Route::get('/projects/{project}/question', [StudioController::class, 'question'])->name('studios.question');
        Route::post('/projects/{project}/question', [StudioController::class, 'saveBrief'])->name('studios.question.save');
        Route::get('/projects/{project}/argument', [StudioController::class, 'argument'])->name('studios.argument');
        Route::post('/projects/{project}/argument/{step}/{argumentStep?}', [StudioController::class, 'argumentStep'])->whereIn('step', ['save', 'add', 'update', 'up', 'down', 'remove'])->name('studios.argument.step');
        Route::get('/projects/{project}/triage', [StudioController::class, 'triage'])->name('studios.triage');
        Route::post('/projects/{project}/triage', [StudioController::class, 'saveTriage'])->name('studios.triage.save');
        Route::get('/projects/{project}/time-machine', [StudioController::class, 'timeMachine'])->name('studios.time-machine');
        Route::get('/journals', [JournalController::class, 'index'])->name('journals.index');
        Route::post('/journals', [JournalController::class, 'store'])->name('journals.store');
        Route::get('/journals/directory', [JournalController::class, 'directory'])->name('journals.directory');
        Route::post('/journals/directory/{journal?}', [JournalController::class, 'saveTarget'])->name('journals.directory.save');
        Route::get('/journals/{journal}', [JournalController::class, 'show'])->name('journals.show');
        Route::post('/journals/{journal}/{step}/{id?}', [JournalController::class, 'step'])->whereIn('step', ['update', 'issue', 'article', 'delete'])->name('journals.step');
        Route::post('/journal-parts/{kind}/{id}/remove', [JournalController::class, 'remove'])->whereIn('kind', ['issues', 'articles'])->name('journals.remove');
    });
    // Running the reading room (roadmap phase 19b): for who may manage it.
    Route::prefix('reading-room')->name('reading-room.')->whereUuid(['researcher', 'booking', 'room', 'visitor', 'material', 'queue', 'seat', 'schedule', 'equipment', 'type'])->group(function () {
        Route::get('/researchers', [ReadingRoomController::class, 'researchers'])->name('researchers');
        Route::get('/researchers/{researcher}', [ReadingRoomController::class, 'researcher'])->name('researchers.show');
        Route::post('/researchers/{researcher}/verify', [ReadingRoomController::class, 'verify'])->name('researchers.verify');
        Route::post('/researchers/{researcher}/{step}', [ReadingRoomController::class, 'decide'])->whereIn('step', ['approve', 'reject', 'suspend'])->name('researchers.decide');
        Route::get('/desk', [ReadingRoomController::class, 'desk'])->name('desk');
        Route::post('/bookings/{booking}/{step}', [ReadingRoomController::class, 'bookingStep'])->whereIn('step', ['confirm', 'check-in', 'check-out', 'no-show', 'seat'])->name('bookings.step');
        Route::post('/rooms/{room}/walk-ins', [ReadingRoomController::class, 'walkIn'])->name('walk-ins.store');
        Route::post('/walk-ins/{visitor}/leave', [ReadingRoomController::class, 'walkOut'])->name('walk-ins.leave');
        Route::post('/requests/{material}/{step}', [ReadingRoomController::class, 'materialStep'])->whereIn('step', ['status', 'approve', 'handle', 'handoff', 'verify-return'])->name('requests.step');
        Route::get('/queues', [ReadingRoomController::class, 'queues'])->name('queues');
        Route::post('/queues', [ReadingRoomController::class, 'saveQueue'])->name('queues.store');
        Route::post('/queues/{queue}', [ReadingRoomController::class, 'saveQueue'])->name('queues.update');
        Route::get('/slips', [ReadingRoomController::class, 'slips'])->name('slips');
        Route::get('/rooms', [ReadingRoomController::class, 'rooms'])->name('rooms');
        Route::get('/rooms/new', [ReadingRoomController::class, 'room'])->name('rooms.create');
        Route::post('/rooms', [ReadingRoomController::class, 'saveRoom'])->name('rooms.store');
        Route::get('/rooms/{room}', [ReadingRoomController::class, 'room'])->name('rooms.edit');
        Route::post('/rooms/{room}', [ReadingRoomController::class, 'saveRoom'])->name('rooms.update');
        Route::post('/rooms/{room}/seats', [ReadingRoomController::class, 'addSeats'])->name('seats.store');
        Route::post('/seats/{seat}/toggle', [ReadingRoomController::class, 'toggleSeat'])->name('seats.toggle');
        Route::post('/rooms/{room}/schedules', [ReadingRoomController::class, 'saveSchedule'])->name('schedules.store');
        Route::post('/schedules/{schedule}/remove', [ReadingRoomController::class, 'removeSchedule'])->name('schedules.remove');
        Route::post('/rooms/{room}/equipment', [ReadingRoomController::class, 'saveEquipment'])->name('equipment.store');
        Route::post('/rooms/{room}/equipment/{equipment}', [ReadingRoomController::class, 'saveEquipment'])->name('equipment.update');
        Route::post('/equipment/{equipment}/maintenance', [ReadingRoomController::class, 'maintain'])->name('equipment.maintain');
        Route::post('/equipment-bookings/{booking}/{step}', [ReadingRoomController::class, 'equipmentStep'])->whereIn('step', ['check-out', 'return', 'cancel', 'no-show'])->name('equipment-bookings.step');
        Route::get('/types', [ReadingRoomController::class, 'types'])->name('types');
        Route::post('/types', [ReadingRoomController::class, 'saveType'])->name('types.store');
        Route::post('/types/{type}', [ReadingRoomController::class, 'saveType'])->name('types.update');
    });
    // Feedback received (roadmap phase 19a): for staff who may change descriptions.
    Route::prefix('feedback-received')->name('feedback.')->whereUuid('feedback')->group(function () {
        Route::get('/', [FeedbackController::class, 'index'])->name('index');
        Route::get('/{feedback}', [FeedbackController::class, 'show'])->name('show');
        Route::post('/{feedback}', [FeedbackController::class, 'update'])->name('update');
        Route::post('/{feedback}/delete', [FeedbackController::class, 'destroy'])->name('destroy');
    });
    // Share links (roadmap phase 19a, ADR 0017): issued on a record's page by who may change it.
    Route::get('/share-links', [ShareLinkController::class, 'index'])->name('share-links.index');
    Route::post('/share-links/{link}/revoke', [ShareLinkController::class, 'revoke'])->whereUuid('link')->name('share-links.revoke');
    Route::post('/records/{record}/share-links', [ShareLinkController::class, 'issue'])->whereUuid('record')->name('records.share-links.issue');
    // Favourites and their folders (roadmap phase 19a, ADR 0017): every signed-in account keeps its own.
    Route::prefix('favourites')->name('favourites.')->whereUuid(['favourite', 'folder', 'id'])->group(function () {
        Route::get('/', [FavouritesController::class, 'index'])->name('index');
        Route::post('/toggle/{kind}/{id}', [FavouritesController::class, 'toggle'])->name('toggle');
        Route::post('/links', [FavouritesController::class, 'addLink'])->name('links.store');
        Route::post('/bulk', [FavouritesController::class, 'bulk'])->name('bulk');
        Route::post('/clear', [FavouritesController::class, 'clear'])->name('clear');
        Route::post('/import', [FavouritesController::class, 'import'])->name('import');
        Route::get('/export/{format}', [FavouritesController::class, 'export'])->name('export');
        Route::post('/{favourite}/notes', [FavouritesController::class, 'notes'])->name('notes');
        Route::post('/folders', [FavouritesController::class, 'saveFolder'])->name('folders.store');
        Route::post('/folders/{folder}', [FavouritesController::class, 'saveFolder'])->name('folders.update');
        Route::post('/folders/{folder}/delete', [FavouritesController::class, 'deleteFolder'])->name('folders.delete');
        Route::post('/folders/{folder}/share', [FavouritesController::class, 'share'])->name('folders.share');
        Route::post('/folders/{folder}/unshare', [FavouritesController::class, 'unshare'])->name('folders.unshare');
    });
    Route::get('/access-requests/{accessRequest}', [AccessRequestController::class, 'show'])->whereUuid('accessRequest')->name('access-requests.show');
    Route::post('/access-requests/{accessRequest}/approve', [AccessRequestController::class, 'approve'])->whereUuid('accessRequest')->name('access-requests.approve');
    Route::post('/access-requests/{accessRequest}/deny', [AccessRequestController::class, 'deny'])->whereUuid('accessRequest')->name('access-requests.deny');
    Route::post('/access-requests/{accessRequest}/cancel', [AccessRequestController::class, 'cancel'])->whereUuid('accessRequest')->name('access-requests.cancel');

    // Alternative text for images (roadmap phase 5; Heratio's ahg-core alt-text worklist).
    Route::get('/alt-text', [AltTextController::class, 'index'])->name('alt-text.index');
    Route::post('/alt-text/{instantiation}', [AltTextController::class, 'store'])->whereUuid('instantiation')->name('alt-text.store');

    // Background jobs (roadmap phase 5): each account its own, all of them for one that may manage the institution.
    Route::get('/jobs', [JobsController::class, 'index'])->name('jobs.index');
    Route::get('/jobs/export.csv', [JobsController::class, 'csv'])->name('jobs.csv');
    Route::post('/jobs/clear-inactive', [JobsController::class, 'clearInactive'])->name('jobs.clear-inactive');
    Route::get('/jobs/{job}', [JobsController::class, 'show'])->whereUuid('job')->name('jobs.show');
    Route::post('/jobs/{job}/cancel', [JobsController::class, 'cancel'])->whereUuid('job')->name('jobs.cancel');

    // Full-text search and its statistics (roadmap phase 6, ADR 0009).
    Route::get('/search', [SearchController::class, 'index'])->name('search');
    Route::get('/search/statistics', [SearchController::class, 'statistics'])->name('search.statistics')
        ->middleware('can:'.Vocab::permission('view-audit-log'));

    // Reports and statistics (roadmap phase 8): the overview, data quality and one route for each list; the controller checks what each needs.
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/quality', [ReportController::class, 'quality'])->name('reports.quality');
    Route::get('/reports/usage', [ReportController::class, 'usage'])->name('reports.usage');
    // The report builder (roadmap phase 8): the controller checks that the report is the account's own or shared, and on a kind it may read.
    // The viewer layouts an account saves, and IIIF validation (roadmap phase 14b, ADR 0014).
    Route::prefix('iiif-workspaces')->name('iiif-workspaces.')->group(function () {
        Route::get('/', [IiifWorkspaceController::class, 'index'])->name('index');
        Route::post('/', [IiifWorkspaceController::class, 'store'])->name('store');
        Route::post('/{workspace}', [IiifWorkspaceController::class, 'update'])->whereUuid('workspace')->name('update');
        Route::post('/{workspace}/delete', [IiifWorkspaceController::class, 'destroy'])->whereUuid('workspace')->name('destroy');
        Route::get('/{workspace}/open', [IiifWorkspaceController::class, 'open'])->whereUuid('workspace')->name('open');
    });
    Route::prefix('iiif-validation')->name('iiif-validation.')->group(function () {
        Route::get('/', [IiifValidationController::class, 'index'])->name('index');
        Route::post('/all', [IiifValidationController::class, 'validateAll'])->name('all');
        Route::get('/records/{record}', [IiifValidationController::class, 'record'])->whereUuid('record')->name('record');
        Route::post('/records/{record}', [IiifValidationController::class, 'validateRecord'])->whereUuid('record')->name('validate');
    });
    // Curated IIIF collections (roadmap phase 14a, ADR 0014): who may publish descriptions may curate them.
    Route::prefix('iiif-collections')->name('iiif-collections.')->group(function () {
        Route::get('/', [IiifCollectionController::class, 'index'])->name('index');
        Route::post('/', [IiifCollectionController::class, 'save'])->name('store');
        Route::get('/{collection}', [IiifCollectionController::class, 'show'])->whereUuid('collection')->name('show');
        Route::post('/{collection}', [IiifCollectionController::class, 'save'])->whereUuid('collection')->name('update');
        Route::post('/{collection}/delete', [IiifCollectionController::class, 'destroy'])->whereUuid('collection')->name('destroy');
        Route::post('/{collection}/items', [IiifCollectionController::class, 'add'])->whereUuid('collection')->name('items.add');
        Route::post('/{collection}/items/{item}/move/{direction}', [IiifCollectionController::class, 'move'])->whereUuid(['collection', 'item'])->whereIn('direction', ['up', 'down'])->name('items.move');
        Route::post('/{collection}/items/{item}/remove', [IiifCollectionController::class, 'remove'])->whereUuid(['collection', 'item'])->name('items.remove');
    });
    Route::prefix('reports/builder')->name('reports.builder.')->group(function () {
        Route::get('/', [ReportBuilderController::class, 'index'])->name('index');
        Route::post('/', [ReportBuilderController::class, 'store'])->name('store');
        Route::post('/templates/{template}/remove', [ReportBuilderController::class, 'destroyTemplate'])->name('templates.destroy');
        Route::get('/{report}', [ReportBuilderController::class, 'show'])->name('show');
        Route::get('/{report}/edit', [ReportBuilderController::class, 'edit'])->name('edit');
        Route::post('/{report}', [ReportBuilderController::class, 'update'])->name('update');
        Route::post('/{report}/status', [ReportBuilderController::class, 'status'])->name('status');
        Route::post('/{report}/copy', [ReportBuilderController::class, 'copy'])->name('copy');
        Route::post('/{report}/template', [ReportBuilderController::class, 'saveTemplate'])->name('template');
        Route::post('/{report}/remove', [ReportBuilderController::class, 'destroy'])->name('destroy');
        Route::post('/{report}/sections', [ReportBuilderController::class, 'addSection'])->name('sections.store');
        Route::post('/{report}/sections/{section}', [ReportBuilderController::class, 'updateSection'])->name('sections.update');
        Route::post('/{report}/sections/{section}/move', [ReportBuilderController::class, 'moveSection'])->name('sections.move');
        Route::post('/{report}/sections/{section}/remove', [ReportBuilderController::class, 'removeSection'])->name('sections.destroy');
        Route::get('/{report}/export/{format}', [ReportBuilderController::class, 'export'])->where('format', 'csv|xlsx|pdf|docx')->name('export');
        Route::post('/{report}/archive', [ReportBuilderController::class, 'generate'])->name('generate');
        Route::get('/{report}/archive/{archive}', [ReportBuilderController::class, 'archive'])->name('archive');
        Route::post('/{report}/schedules', [ReportBuilderController::class, 'storeSchedule'])->name('schedules.store');
        Route::post('/{report}/schedules/{schedule}/remove', [ReportBuilderController::class, 'destroySchedule'])->name('schedules.destroy');
        Route::post('/{report}/shares', [ReportBuilderController::class, 'storeShare'])->name('shares.store');
        Route::post('/{report}/shares/{share}/revoke', [ReportBuilderController::class, 'revokeShare'])->name('shares.revoke');
        Route::post('/{report}/versions', [ReportBuilderController::class, 'storeVersion'])->name('versions.store');
        Route::post('/{report}/versions/{version}/restore', [ReportBuilderController::class, 'restoreVersion'])->name('versions.restore');
    });
    foreach (array_keys(ReportService::LISTS) as $list) {
        Route::get("/reports/$list", [ReportController::class, 'show'])->defaults('list', $list)->name("reports.$list");
    }

    // The rights report and rights for what is on the clipboard (roadmap phase 2); the controller checks read or update.
    Route::get('/rights', [RightsAdminController::class, 'index'])->name('rights.index');
    Route::get('/rights/report/{list}.csv', [RightsAdminController::class, 'csv'])->where('list', '[a-z]+')->name('rights.csv');
    Route::get('/rights/batch', [RightsAdminController::class, 'batch'])->name('rights.batch');
    Route::post('/rights/batch', [RightsAdminController::class, 'batchStore'])->name('rights.batch.store');

    // Rights of a unit of description, an instantiation (roadmap phase 2) or an accession (phase 9d), and Local Contexts
    // Labels of the first two; each controller checks update on it.
    // Privacy: POPIA governance (roadmap phase 18a, ADR 0016).
    Route::prefix('privacy')->name('privacy.')->group(function () {
        $registers = implode('|', array_keys(Registers::ALL));
        Route::get('/', [PrivacyController::class, 'index'])->name('index');
        Route::get('/controls', [PrivacyController::class, 'controls'])->name('controls');
        // Privacy in the catalogue (roadmap phase 18b): where files are screened, and the scans waiting for a decision.
        Route::get('/screening', [PrivacyScreeningController::class, 'index'])->name('screening');
        Route::post('/screening/scopes', [PrivacyScreeningController::class, 'requireScope'])->name('screening.require');
        Route::post('/screening/scopes/{scope}/remove', [PrivacyScreeningController::class, 'removeScope'])->whereUuid('scope')->name('screening.remove');
        Route::get('/{register}', [PrivacyController::class, 'register'])->where('register', $registers)->name('register');
        Route::get('/{register}/new', [PrivacyController::class, 'create'])->where('register', $registers)->name('create');
        Route::post('/{register}', [PrivacyController::class, 'save'])->where('register', $registers)->name('store');
        Route::get('/{register}/{id}', [PrivacyController::class, 'show'])->where('register', $registers)->whereUuid('id')->name('show');
        Route::post('/{register}/{id}', [PrivacyController::class, 'save'])->where('register', $registers)->whereUuid('id')->name('update');
        Route::post('/{register}/{id}/{step}', [PrivacyController::class, 'step'])->where('register', $registers)->whereUuid('id')
            ->whereIn('step', ['status', 'submit', 'approve', 'reject', 'sign-off', 'withdraw', 'verify', 'notify'])->name('step');
    });
    // Retention and disposal (roadmap phase 13b, ADR 0015).
    // Who may delete a description takes part in retention and disposal (ADR 0015), as Heratio keeps it to staff (phase 23).
    Route::middleware('access:delete,description')->group(function () {
        Route::prefix('retention')->name('retention.')->group(function () {
            Route::get('/', [RetentionController::class, 'index'])->name('index');
            Route::post('/schedules', [RetentionController::class, 'saveSchedule'])->name('schedules.store');
            Route::get('/schedules/{schedule}', [RetentionController::class, 'schedule'])->whereUuid('schedule')->name('schedule');
            Route::post('/schedules/{schedule}', [RetentionController::class, 'saveSchedule'])->whereUuid('schedule')->name('schedules.update');
            Route::post('/schedules/{schedule}/approve', [RetentionController::class, 'approveSchedule'])->whereUuid('schedule')->name('schedules.approve');
            Route::post('/schedules/{schedule}/version', [RetentionController::class, 'newVersion'])->whereUuid('schedule')->name('schedules.version');
            Route::post('/schedules/{schedule}/classes', [RetentionController::class, 'saveClass'])->whereUuid('schedule')->name('classes.store');
            Route::post('/schedules/{schedule}/classes/{class}', [RetentionController::class, 'saveClass'])->whereUuid(['schedule', 'class'])->name('classes.update');
            Route::post('/reviews/{review}', [RetentionController::class, 'decide'])->whereUuid('review')->name('reviews.decide');
            Route::get('/certificates/{certificate}', [RetentionController::class, 'certificate'])->whereUuid('certificate')->name('certificate');
        });
        Route::prefix('records/{record}/retention')->name('records.retention.')->whereUuid('record')->group(function () {
            Route::post('/', [RetentionController::class, 'assign'])->name('assign');
            Route::post('/reviews', [RetentionController::class, 'scheduleReview'])->name('review');
            Route::post('/disposals', [RetentionController::class, 'initiate'])->name('initiate');
        });
        // Classifying and declassifying a record (roadmap phase 18c, ADR 0016).
        Route::post('/records/{record}/classification', [ClearanceController::class, 'classify'])->whereUuid('record')->name('records.classification');
        // Records management beyond retention and disposal (roadmap phase 13c, ADR 0015).
        Route::prefix('records-management')->name('records-management.')->whereUuid(['node', 'rule', 'email', 'declaration', 'vital', 'assessment'])->group(function () {
            Route::get('/file-plan', [RecordsManagementController::class, 'filePlan'])->name('file-plan');
            Route::post('/file-plan', [RecordsManagementController::class, 'saveNode'])->name('nodes.store');
            Route::post('/file-plan/import', [RecordsManagementController::class, 'import'])->name('import');
            Route::get('/file-plan/{node}', [RecordsManagementController::class, 'node'])->name('node');
            Route::post('/file-plan/{node}', [RecordsManagementController::class, 'saveNode'])->name('nodes.update');
            Route::post('/file-plan/{node}/remove', [RecordsManagementController::class, 'removeNode'])->name('nodes.remove');
            Route::get('/rules', [RecordsManagementController::class, 'rules'])->name('rules');
            Route::post('/rules/classify', [RecordsManagementController::class, 'classifyAll'])->name('rules.classify');
            Route::post('/rules/{rule?}', [RecordsManagementController::class, 'saveRule'])->name('rules.save');
            Route::get('/emails', [RecordsManagementController::class, 'emails'])->name('emails');
            Route::post('/emails', [RecordsManagementController::class, 'capture'])->name('emails.capture');
            Route::get('/emails/{email}', [RecordsManagementController::class, 'email'])->name('email');
            Route::get('/emails/{email}/original', [RecordsManagementController::class, 'original'])->name('email.original');
            Route::post('/emails/{email}/{step}', [RecordsManagementController::class, 'emailStep'])->whereIn('step', ['classify', 'declare'])->name('email.step');
            Route::get('/declarations', [RecordsManagementController::class, 'declarations'])->name('declarations');
            Route::post('/declarations/{declaration}/{step}', [RecordsManagementController::class, 'declarationStep'])->whereIn('step', ['approve', 'reject'])->name('declarations.step');
            Route::post('/vital/{vital}/{step}', [RecordsManagementController::class, 'vitalStep'])->whereIn('step', ['review', 'unflag'])->name('vital.step');
            Route::get('/compliance', [RecordsManagementController::class, 'assessments'])->name('compliance');
            Route::post('/compliance', [RecordsManagementController::class, 'createAssessment'])->name('compliance.store');
            Route::get('/compliance/{assessment}', [RecordsManagementController::class, 'assessment'])->name('assessment');
            Route::post('/compliance/{assessment}/{step}', [RecordsManagementController::class, 'assessmentStep'])->whereIn('step', ['run', 'sign-off'])->name('assessment.step');
        });
        Route::post('/records/{record}/records-management/{step}', [RecordsManagementController::class, 'recordStep'])->whereUuid('record')
            ->whereIn('step', ['place', 'unplace', 'classify', 'declare', 'vital'])->name('records.records-management');
        // The jurisdiction packs (roadmap phases 18d and 18e, ADR 0016): NARSSA transfers, NHRA s.32 export permits, the National Archives of Zimbabwe.
        Route::prefix('jurisdiction')->name('jurisdiction.')->whereUuid(['transfer', 'permit', 'document', 'id'])->group(function () {
            Route::get('/narssa', [JurisdictionController::class, 'narssa'])->name('narssa');
            Route::post('/narssa', [JurisdictionController::class, 'narssaBuild'])->name('narssa.build');
            Route::get('/narssa/{transfer}/package', [JurisdictionController::class, 'narssaPackage'])->name('narssa.package');
            Route::post('/narssa/{transfer}/{step}', [JurisdictionController::class, 'narssaStep'])->whereIn('step', ['transmitted', 'accepted', 'returned'])->name('narssa.step');
            Route::get('/export-permits', [JurisdictionController::class, 'permits'])->name('permits');
            Route::post('/export-permits', [JurisdictionController::class, 'savePermit'])->name('permits.store');
            Route::get('/export-permits/{permit}', [JurisdictionController::class, 'permit'])->name('permit');
            Route::post('/export-permits/{permit}', [JurisdictionController::class, 'savePermit'])->name('permits.update');
            Route::get('/export-permits/documents/{document}', [JurisdictionController::class, 'permitDocument'])->name('permits.document');
            Route::post('/export-permits/{permit}/{step}', [JurisdictionController::class, 'permitStep'])
                ->whereIn('step', ['submit-for-approval', 'approve', 'return', 'lodge', 'issued', 'declined', 'revoke', 'close', 'cancel', 'document'])->name('permits.step');
            Route::get('/naz', [JurisdictionController::class, 'naz'])->name('naz');
            Route::post('/naz/{step}/{id?}', [JurisdictionController::class, 'nazStep'])
                ->whereIn('step', ['restrict', 'release', 'permit', 'approve', 'reject', 'revoke', 'schedule', 'transfer'])->name('naz.step');
        });
        // Duplicate archival descriptions (roadmap phase 22b).
        Route::prefix('duplicates')->name('duplicates.')->whereUuid(['pair', 'rule'])->group(function () {
            Route::get('/', [DuplicateController::class, 'index'])->name('index');
            Route::post('/scan', [DuplicateController::class, 'scan'])->name('scan');
            Route::post('/rules', [DuplicateController::class, 'saveRule'])->name('rules.store');
            Route::post('/rules/{rule}', [DuplicateController::class, 'saveRule'])->name('rules.update');
            Route::get('/{pair}', [DuplicateController::class, 'show'])->name('show');
            Route::post('/{pair}/{step}', [DuplicateController::class, 'step'])->whereIn('step', ['dismiss', 'confirm', 'merge'])->name('step');
        });
        Route::get('/disposals/{disposal}', [RetentionController::class, 'disposal'])->whereUuid('disposal')->name('disposals.show');
        Route::post('/disposals/{disposal}/{step}', [RetentionController::class, 'step'])->whereUuid('disposal')
            ->whereIn('step', ['recommend', 'approve', 'clear', 'reject', 'cancel', 'execute', 'verify'])->name('disposals.step');
    });
    // A record scanned for personal information, its fields redacted for the public, its files released or withheld
    // (roadmap phase 18b, ADR 0016).
    Route::prefix('records/{record}/privacy')->name('records.privacy.')->whereUuid(['record', 'redaction'])->group(function () {
        Route::post('/scan', [PrivacyScreeningController::class, 'scan'])->name('scan');
        Route::post('/redactions', [PrivacyScreeningController::class, 'redact'])->name('redact');
        Route::post('/redactions/{redaction}/remove', [PrivacyScreeningController::class, 'unredact'])->name('unredact');
        Route::post('/files', [PrivacyScreeningController::class, 'decide'])->name('files');
    });
    // Security clearance (roadmap phase 18c, ADR 0016): what the institution keeps, and requests for access above one's clearance.
    Route::get('/clearance', [ClearanceController::class, 'index'])->name('clearance.index');
    Route::post('/clearance/{step}/{id?}', [ClearanceController::class, 'step'])->whereIn('step', ['level', 'compartment', 'grant', 'revoke', 'approve', 'deny', 'revoke-grant'])->whereUuid('id')->name('clearance.step');
    Route::get('/clearance-requests', [ClearanceController::class, 'requestForm'])->name('clearance.request');
    Route::post('/clearance-requests', [ClearanceController::class, 'submitRequest'])->name('clearance.request.submit');
    // Embargoes on units of description (roadmap phase 13a, ADR 0015).
    Route::get('/embargoes', [EmbargoController::class, 'index'])->name('embargoes.index');
    Route::prefix('records/{record}/embargoes')->name('records.embargoes.')->whereUuid(['record', 'embargo', 'exception'])->group(function () {
        Route::post('/', [EmbargoController::class, 'store'])->name('store');
        Route::post('/{embargo}', [EmbargoController::class, 'update'])->name('update');
        Route::post('/{embargo}/lift', [EmbargoController::class, 'lift'])->name('lift');
        Route::post('/{embargo}/exceptions', [EmbargoController::class, 'except'])->name('exceptions.add');
        Route::post('/{embargo}/exceptions/{exception}/remove', [EmbargoController::class, 'removeException'])->name('exceptions.remove');
    });
    // An entity's own fields, and describing it through a form template (roadmap phase 22b).
    Route::prefix('{kind}/{id}')->where(['kind' => 'records|agents|repositories|accessions|activities', 'id' => '[0-9a-f-]{36}'])->group(function () {
        Route::get('/fields', [CustomFieldController::class, 'entity'])->name('custom-fields.entity');
        Route::post('/fields', [CustomFieldController::class, 'saveEntity'])->name('custom-fields.entity.save');
    });
    // The version history of a unit of description or an authority record (roadmap phase 22b).
    Route::prefix('{kind}/{id}')->where(['kind' => 'records|agents', 'id' => '[0-9a-f-]{36}'])->group(function () {
        Route::get('/versions', [VersionController::class, 'index'])->name('versions.index');
        Route::post('/versions/restore', [VersionController::class, 'restore'])->name('versions.restore');
    });
    Route::prefix('{kind}/{id}')->where(['kind' => 'records|instantiations|accessions', 'id' => '[0-9a-f-]{36}', 'right' => '[0-9a-f-]{36}'])->group(function () {
        Route::post('/rights', [RightsController::class, 'store'])->name('rights.store');
        Route::post('/rights/{right}', [RightsController::class, 'update'])->name('rights.update');
        Route::post('/rights/{right}/remove', [RightsController::class, 'destroy'])->name('rights.destroy');
    });
    Route::prefix('{kind}/{id}')->where(['kind' => 'records|instantiations', 'id' => '[0-9a-f-]{36}', 'label' => '[0-9a-f-]{36}'])->group(function () {
        Route::post('/community-labels', [CommunityLabelController::class, 'store'])->name('community-labels.store');
        Route::post('/community-labels/{label}/remove', [CommunityLabelController::class, 'destroy'])->name('community-labels.destroy');
    });

    // Every authority record as EAC-CPF, or every activity as EAC-F, in a ZIP (roadmap phase 11a); the controller checks read.
    Route::get('/exports/{format}.zip', [ExportController::class, 'contexts'])->where('format', 'eac-cpf|eac-f')->name('exports.contexts');

    // The workflow (roadmap phase 10a; Heratio's ahg-workflow, PSIS's ahgWorkflowPlugin): every signed-in account has
    // its tasks; working one needs update on its record; workflows and steps need Manage workflows (the controller checks).
    Route::prefix('workflow')->name('workflow.')->group(function () {
        Route::get('/', [WorkflowController::class, 'dashboard'])->name('dashboard');
        Route::get('/my-tasks', [WorkflowController::class, 'mine'])->name('mine');
        Route::get('/pool', [WorkflowController::class, 'pool'])->name('pool');
        Route::get('/team', [WorkflowController::class, 'team'])->name('team');
        Route::get('/history', [WorkflowController::class, 'history'])->name('history');
        Route::post('/bulk', [WorkflowController::class, 'bulk'])->name('bulk');
        Route::get('/tasks/{task}', [WorkflowController::class, 'show'])->whereUuid('task')->name('tasks.show');
        Route::post('/tasks/{task}/{action}', [WorkflowController::class, 'act'])->whereUuid('task')->where('action', 'claim|release|begin|assign|decide|resubmit|cancel|note|priority|due')->name('tasks.act');
        Route::get('/admin', [WorkflowController::class, 'index'])->name('admin');
        Route::get('/admin/new', [WorkflowController::class, 'edit'])->name('admin.create');
        Route::post('/admin', [WorkflowController::class, 'save'])->name('admin.store');
        Route::get('/admin/{workflow}', [WorkflowController::class, 'edit'])->whereUuid('workflow')->name('admin.edit');
        Route::post('/admin/{workflow}', [WorkflowController::class, 'save'])->whereUuid('workflow')->name('admin.update');
        Route::post('/admin/{workflow}/remove', [WorkflowController::class, 'remove'])->whereUuid('workflow')->name('admin.destroy');
        Route::post('/admin/{workflow}/steps/{step?}', [WorkflowController::class, 'saveStep'])->whereUuid(['workflow', 'step'])->name('admin.steps.save');
        Route::post('/admin/{workflow}/steps/{step}/move', [WorkflowController::class, 'moveStep'])->whereUuid(['workflow', 'step'])->name('admin.steps.move');
        Route::post('/admin/{workflow}/steps/{step}/remove', [WorkflowController::class, 'removeStep'])->whereUuid(['workflow', 'step'])->name('admin.steps.destroy');
        // Queues, service levels, overdue tasks, publish gates and the diagram (roadmap phase 10b).
        Route::get('/queues', [WorkflowQueueController::class, 'queues'])->name('queues');
        Route::post('/queues/{queue?}', [WorkflowQueueController::class, 'saveQueue'])->whereUuid('queue')->name('queues.save');
        Route::post('/policies/{policy?}', [WorkflowQueueController::class, 'savePolicy'])->whereUuid('policy')->name('policies.save');
        Route::get('/overdue', [WorkflowQueueController::class, 'overdue'])->name('overdue');
        Route::post('/tasks/{task}/queue', [WorkflowQueueController::class, 'move'])->whereUuid('task')->name('tasks.queue');
        Route::get('/tasks/{task}/diagram', [WorkflowQueueController::class, 'taskDiagram'])->whereUuid('task')->name('tasks.diagram');
        Route::get('/admin/gates', [WorkflowQueueController::class, 'gates'])->name('gates');
        Route::post('/admin/gates/{rule?}', [WorkflowQueueController::class, 'saveGate'])->whereUuid('rule')->name('gates.save');
        Route::post('/admin/gates/{rule}/remove', [WorkflowQueueController::class, 'removeGate'])->whereUuid('rule')->name('gates.destroy');
        Route::post('/admin/publishing', [WorkflowQueueController::class, 'publishing'])->name('publishing');
        Route::get('/admin/{workflow}/diagram', [WorkflowQueueController::class, 'diagram'])->whereUuid('workflow')->name('admin.diagram');
    });
    Route::post('{kind}/{id}/workflow', [WorkflowController::class, 'start'])->where(['kind' => 'records|agents|repositories|accessions', 'id' => '[0-9a-f-]{36}'])->name('workflow.start');

    // Delete a unit of description, a repository or a place, after confirming (EntityDeletion).
    Route::get('{kind}/{id}/delete', [DeletionController::class, 'confirm'])->where(['kind' => 'records|repositories|places|agents|activities|instantiations|containers|accessions|agreements|rules', 'id' => '[0-9a-f-]{36}'])->name('deletion.confirm');
    Route::post('{kind}/{id}/delete', [DeletionController::class, 'destroy'])->where(['kind' => 'records|repositories|places|agents|activities|instantiations|containers|accessions|agreements|rules', 'id' => '[0-9a-f-]{36}'])->name('deletion.destroy');

    // The modification history of an entity and its parts, from the audit log.
    Route::get('{kind}/{id}/history', [HistoryController::class, 'show'])->where(['kind' => 'records|repositories|places|agents|activities|instantiations|containers|concepts|accessions|agreements|rules', 'id' => '[0-9a-f-]{36}'])->name('history');

    // Review, publish and withdraw an entity's description (PublicationService).
    Route::get('{kind}/{id}/publication', [PublicationController::class, 'edit'])->where(['kind' => 'records|repositories|places|agents|activities|instantiations|rules', 'id' => '[0-9a-f-]{36}'])->name('publication.edit');
    Route::post('{kind}/{id}/publication', [PublicationController::class, 'update'])->where(['kind' => 'records|repositories|places|agents|activities|instantiations|rules', 'id' => '[0-9a-f-]{36}'])->name('publication.update');

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
        // Sent mail and the addresses held after a bounce (roadmap phase 7).
        Route::get('/email', [EmailController::class, 'index'])->name('email.index');
        Route::post('/email/held/{user}/clear', [EmailController::class, 'clear'])->whereUuid('user')->name('email.clear');
        // Sign-in settings: identity providers and the two-factor policy (roadmap phase 12b).
        Route::get('/sign-in-settings', [TwoFactorController::class, 'settings'])->name('sign-in-settings.index');
        Route::post('/sign-in-settings/two-factor', [TwoFactorController::class, 'savePolicy'])->name('sign-in-settings.policy');
        Route::post('/sign-in-settings/providers', [TwoFactorController::class, 'saveProvider'])->name('sign-in-settings.providers.store');
        Route::post('/sign-in-settings/providers/{provider}', [TwoFactorController::class, 'saveProvider'])->whereUuid('provider')->name('sign-in-settings.providers.update');
        Route::post('/accounts/{account}/two-factor/reset', [TwoFactorController::class, 'reset'])->whereUuid('account')->name('accounts.two-factor.reset');
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
