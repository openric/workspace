<?php

// OpenRiC Workspace - the access-control matrix (roadmap phase 23): every signed-in route that names an object in its
// address is tried, with that object taken from one institution, by an administrator of another, and must be refused;
// a write that is refused leaves no audit row behind. Routes whose objects the matrix does not build yet are listed,
// so a new route cannot slip past unnoticed.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Access\RoleService;
use App\AccessRequests\AccessRequestService;
use App\Accounts\AccountService;
use App\Accounts\ApiKeyService;
use App\Accounts\RegistrationService;
use App\Description\AccessionService;
use App\Description\ActivityService;
use App\Description\AgentService;
use App\Description\AgreementService;
use App\Description\ContactPointService;
use App\Description\DateService;
use App\Description\IdentifierService;
use App\Description\InstantiationService;
use App\Description\NameService;
use App\Description\PlaceService;
use App\Description\PublicationService;
use App\Description\RecordMemberships;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Description\RuleService;
use App\Description\WorkflowService;
use App\Description\WorkflowSlaService;
use App\Iiif\CuratedCollections;
use App\Models\Concept;
use App\Models\Container;
use App\Models\Feedback;
use App\Models\Institution;
use App\Models\ReadingRoom;
use App\Models\ReadingRoomSeat;
use App\Models\ResearchDoi;
use App\Models\Researcher;
use App\Models\ResearcherType;
use App\Models\ResearchNotification;
use App\Models\RetrievalSchedule;
use App\Models\User;
use App\Proposals\ProposalService;
use App\Relations\RelationService;
use App\Reports\ReportBuilder;
use App\Research\Annotations;
use App\Research\Bibliographies;
use App\Research\Claims;
use App\Research\Collaboration;
use App\Research\Collections;
use App\Research\DataPlans;
use App\Research\Diary;
use App\Research\DocumentTemplates;
use App\Research\Favourites;
use App\Research\Funding;
use App\Research\Impact;
use App\Research\Inbox;
use App\Research\Journals;
use App\Research\Notebooks;
use App\Research\ProjectAnalysis;
use App\Research\ProjectReasoning;
use App\Research\Projects;
use App\Research\Quotas;
use App\Research\Reports;
use App\Research\Reproductions;
use App\Research\Researchers;
use App\Research\ResearchWorkspaces;
use App\Research\RightsPolicies;
use App\Research\SavedSearches;
use App\Research\ShareLinks;
use App\Research\Sources;
use App\Research\Studios;
use App\Research\Submissions;
use App\Research\Teaching;
use App\Retention\DisposalService;
use App\Retention\RetentionService;
use App\Rights\Embargoes;
use App\Rights\RightsService;
use App\Support\Vocab;
use App\Vocabulary\ConceptService;
use App\Vocabulary\SkosImporter;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Imagick;
use ImagickPixel;
use Tests\TestCase;
use Throwable;

class AccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const LEVEL = Vocab::BASE.'level-of-description/';

    /** What a {kind} in an address names. */
    private const KINDS = ['records' => 'RecordResource', 'repositories' => 'Repository', 'places' => 'Place', 'agents' => 'Agent', 'activities' => 'Activity', 'rules' => 'Rule',
        'instantiations' => 'Instantiation', 'containers' => 'Container', 'accessions' => 'Accession', 'agreements' => 'Agreement',
        'record' => 'RecordResource', 'agent' => 'Agent', 'repository' => 'Repository', 'place' => 'Place', 'activity' => 'Activity', 'concepts' => 'Concept',
        'milestones' => 'ResearchProjectMilestone', 'team' => 'ResearchProjectTeamMember', 'outputs' => 'ResearchProjectOutput', 'annotation' => 'ResearchAnnotation',
        'diary' => 'ResearchDiaryEntry', 'collection' => 'ResearchCollection', 'report' => 'ResearchReport', 'report-section' => 'ResearchReportSection',
        'bibliography' => 'Bibliography', 'submission' => 'ResearcherSubmission', 'workspace-file' => 'ResearchWorkspaceFile', 'search' => 'SavedSearch',
        'booking' => 'ReadingRoomBooking', 'issues' => 'ResearchJournalIssue', 'articles' => 'ResearchJournalArticle', 'events' => 'ResearchTimelineEvent',
        'points' => 'ResearchMapPoint', 'from-collection' => 'ResearchCollection', 'funding' => 'ResearchFunding', 'grants' => 'ResearchGrantDraft', 'plans' => 'ResearchDmp',
        'ethics' => 'ResearchEthicsApproval', 'impact' => 'ResearchImpactSignal'];

    /**
     * What any account of the institution reads, whatever its roles. The public role, which every account inherits,
     * reads repositories, descriptions and authority records (config workspace.default_roles, ADR 0005): the fixtures'
     * descriptions are published. Places and local vocabularies are read by any account of the institution (ADR 0006).
     */
    private const ANY_ACCOUNT_READS = ['repositories.show', 'records.show', 'records.tree', 'records.tree-branch', 'records.inventory', 'records.finding-aid',
        'records.print', 'records.export', 'records.publish-readiness', 'records.provenance', 'records.provenance.csv', 'instantiations.show',
        'agents.show', 'agents.print', 'agents.eac', 'activities.show', 'activities.eac', 'places.show',
        'vocabularies.show', 'vocabularies.export', 'concepts.show', 'records.export.loss', 'rules.show'];

    private string $store;

    private User $theirs;

    private User $ours;

    /** @var array<string, Model> one object of each kind, all of the first institution */
    private array $objects = [];

    /** @var list<string> fixtures that could not be built, and why */
    private array $unbuilt = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/workspace-objects-'.bin2hex(random_bytes(6));
        config(['workspace.object_store' => $this->store]);
        Mail::fake();
        Http::fake();
        $this->loadBundledVocabularies();
        app(SkosImporter::class)->import(file_get_contents(base_path('tests/Fixtures/role-type.ttl')));
        $accounts = app(AccountService::class);
        $river = Institution::create(['name' => 'Riverside Archives', 'base_iri' => 'https://archives.example.org']);
        app(RoleService::class)->seedDefaults($river);
        $this->theirs = $accounts->create($river, 'Admin', 'admin@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->theirs, 'administrator');
        $this->build($river, $this->theirs);
        $hill = Institution::create(['name' => 'Hillside Archives', 'base_iri' => 'https://hills.example.org']);
        app(RoleService::class)->seedDefaults($hill);
        $this->ours = $accounts->create($hill, 'Other admin', 'other@example.org', 'correct horse battery staple');
        $accounts->grantTemplateRole($this->ours, 'administrator');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->store)) {
            exec('rm -rf '.escapeshellarg($this->store));
        }
        parent::tearDown();
    }

    /** One of everything the routes name, all in the first institution, made as the application makes them. */
    private function build(Institution $institution, User $admin): void
    {
        $make = function (string $what, callable $do) {
            try {
                DB::transaction(function () use ($do) {
                    $made = $do();
                    foreach (is_array($made) ? Arr::flatten($made, 1) : [$made] as $object) {
                        if ($object instanceof Model) {
                            $this->objects[class_basename($object)] ??= $object;
                        }
                    }
                });
            } catch (Throwable $e) {
                $this->unbuilt[] = "$what: ".$e->getMessage();
            }
        };
        $holder = app(RepositoryService::class)->create($institution, 'Riverside Archives', 'en', 'RA');
        $fonds = app(RecordService::class)->create($institution, self::LEVEL.'fonds', 'Parish council records', 'en', null, $holder, 'PC');
        $letter = app(RecordService::class)->create($institution, self::LEVEL.'item', 'Letter about the parish bridge', 'en', $fonds, null, '1');
        foreach ([$holder, $fonds, $letter] as $entity) {
            app(PublicationService::class)->apply($entity, 'publish', null, false, $admin);
        }
        $this->objects += ['Repository' => $holder, 'RecordResource' => $letter, 'User' => $admin, 'Institution' => $institution];
        $image = new Imagick;
        $image->newImage(60, 40, new ImagickPixel('#d8c9a3'));
        $image->setImageFormat('png');
        $path = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($path, $image->getImageBlob());

        $make('instantiation', fn () => [$scan = app(InstantiationService::class)->upload($letter, $path, 'letter.png', 'en', 'Scan of the letter', null, 'online', $admin), $scan->files()->first()]);
        $make('agent', fn () => app(AgentService::class)->create($institution, 'person', 'Botha, Anna', 'en'));
        $make('place', fn () => app(PlaceService::class)->create($institution, 'Riverside', 'en'));
        $make('activity', fn () => app(ActivityService::class)->create($institution, 'Council meetings', 'en'));
        $make('rule', fn () => app(RuleService::class)->create($institution, 'Local Government Ordinance 17 of 1939', 'en'));
        $make('accession', fn () => app(AccessionService::class)->create($institution, '2026-014', 'Papers of the Botha family', 'en', $admin));
        $make('agreement', fn () => app(AgreementService::class)->create($institution, 'DA-1', 'deed-of-deposit', 'Deposit agreement', 'en', $admin));
        $make('membership', fn () => app(RecordMemberships::class)->add($letter, app(RecordService::class)->create($institution, self::LEVEL.'fonds', 'Bridge files', 'en', null, $holder, 'BF'), $admin));
        $make('relation', fn () => app(RelationService::class)->create($fonds, 'creation', $this->objects['Agent'], [], $admin));
        $make('contact point', fn () => app(ContactPointService::class)->save($holder, ['contact_person' => 'Reading room', 'email' => 'room@example.org'], null, $admin));
        $make('vocabulary', fn () => [$scheme = app(ConceptService::class)->createScheme($institution, 'subject', 'Local subjects', 'en', null, $admin),
            app(ConceptService::class)->create($scheme, ['label' => 'Water', 'language' => 'en'], $admin)]);
        $make('role', fn () => app(RoleService::class)->create($institution, ['en' => ['name' => 'Volunteers']], null, null, $admin));
        $make('api key', fn () => app(ApiKeyService::class)->issue($admin, 'Harvester', ['read'], null, null, $admin)[0]);
        $make('embargo', fn () => app(Embargoes::class)->impose($fonds, ['type' => 'full', 'reason' => 'privacy'], false, $admin));
        $make('report', fn () => [$report = app(ReportBuilder::class)->create($admin, ['name' => 'Holdings', 'source' => 'record']), app(ReportBuilder::class)->addSection($report, 'text', ['title' => 'Notes'])]);
        $make('workflow', fn () => [$flow = app(WorkflowService::class)->saveWorkflow($institution, null, ['name' => 'Description review'], $admin),
            app(WorkflowService::class)->saveStep($flow, null, ['name' => 'Check'], $admin), app(WorkflowService::class)->start($letter, $admin, $flow)]);
        $make('retention', fn () => [$schedule = app(RetentionService::class)->saveSchedule($institution, ['reference' => 'RS-1', 'title' => 'Council schedule', 'authority' => 'NARSSA'], null, $admin),
            app(RetentionService::class)->saveClass($schedule, ['reference' => 'C1', 'title' => 'Minutes', 'retention_years' => 5, 'trigger' => 'creation-end', 'action' => 'destroy'], null, $admin)]);
        $make('iiif collection', fn () => app(CuratedCollections::class)->save($institution, ['label' => 'Bridges'], null, $admin));

        $make('project', function () use ($admin) {
            $project = app(Projects::class)->create($admin, ['title' => 'The parish bridge']);

            return [$project, app(Projects::class)->saveMilestone($admin, $project, ['title' => 'Draft']), app(Projects::class)->saveOutput($admin, $project, ['title' => 'Article']),
                app(Claims::class)->save($admin, $project, ['text' => 'The bridge was built in 1902.']), app(Claims::class)->saveHypothesis($admin, $project, ['statement' => 'The clerk wrote it.'])];
        });
        $make('collection', fn () => [$c = app(Collections::class)->save($admin, ['name' => 'Bridge sources']), app(Collections::class)->add($admin, $c, $letter)]);
        $make('notebook', fn () => [$n = app(Notebooks::class)->save($admin, ['title' => 'Field notes']), app(Notebooks::class)->addItem($admin, $n, ['title' => 'A note', 'content' => 'Seen'])]);
        $make('bibliography', fn () => [$b = app(Bibliographies::class)->save($admin, ['name' => 'Sources']), app(Bibliographies::class)->addRecord($admin, $b, $letter)]);
        $make('diary', fn () => app(Diary::class)->save($admin, ['title' => 'Visit', 'content' => 'Read the letter']));
        $make('saved search', fn () => app(SavedSearches::class)->save($admin, ['name' => 'Bridges', 'search_query' => 'bridge']));
        $make('workspace', fn () => app(ResearchWorkspaces::class)->create($admin, ['name' => 'Bridge team']));
        $post = function (string $route, array $data, $parameters = []) use ($admin) {
            $this->flushSession();
            $response = $this->actingAs($admin)->post(route($route, $parameters), $data);
            if (session()->has('errors')) {
                throw new \RuntimeException(implode(' ', session('errors')->all()));
            }

            return $response;
        };
        $make('researcher', function () use ($post, $admin) {
            $post('research.register', ['first_name' => 'Ada', 'last_name' => 'Researcher', 'affiliation' => 'academic']);
            app(Researchers::class)->approve($researcher = Researcher::sole(), $admin);

            return $researcher;
        });
        $make('reading room', function () use ($post) {
            $post('reading-room.rooms.store', ['name' => 'Main reading room', 'capacity' => 2, 'has_seat_management' => '1', 'walk_ins_allowed' => '1', 'walk_in_capacity' => 1,
                'advance_booking_days' => 14, 'max_booking_hours' => 4, 'cancellation_hours' => 24, 'opening_time' => '09:00', 'closing_time' => '16:30', 'days_open' => [1, 2, 3, 4, 5]]);
            $room = ReadingRoom::sole();
            $post('reading-room.seats.store', ['count' => 1, 'prefix' => 'A', 'seat_type' => 'standard'], $room);
            $post('reading-room.schedules.store', ['name' => 'Morning retrieval', 'retrieval_time' => '08:30', 'cutoff_minutes_before' => 15], $room);
            $post('reading-room.types.store', ['name' => 'Postgraduate', 'expiry_months' => 6, 'max_materials_per_booking' => 1, 'is_active' => '1']);

            return [$room, ReadingRoomSeat::first(), RetrievalSchedule::first(), ResearcherType::first()];
        });
        $make('container', function () use ($post) {
            $post('containers.store', ['name' => 'Box 1', 'language' => 'en', 'type' => Concept::where('iri', Container::TYPES.'box')->value('id')]);

            return Container::first();
        });
        $make('feedback', function () use ($post, $letter) {
            $post('feedback.store', ['type' => 'correction', 'subject' => 'Wrong date', 'remarks' => 'Dated 13 May.', 'name' => 'Anna', 'surname' => 'Smit', 'email' => 'anna@example.org', 'record' => $letter->id]);

            return Feedback::first();
        });
        $make('reproduction', fn () => [$r = app(Reproductions::class)->create($admin, ['purpose' => 'Study']), app(Reproductions::class)->addItem($admin, $r, $letter, [])]);
        $make('submission', fn () => [$s = app(Submissions::class)->create($admin, ['title' => 'Family papers']), app(Submissions::class)->saveItem($admin, $s, ['title' => 'A letter'])]);
        $make('journal', fn () => [app(Journals::class)->saveJournal($admin, ['title' => 'Riverside notes']), app(Journals::class)->saveTarget($admin, ['title' => 'South African Archives Journal'])]);
        $make('favourite', fn () => [app(Favourites::class)->saveFolder($admin, ['name' => 'Keep']), app(Favourites::class)->add($admin, $letter)]);
        $make('share link', fn () => app(ShareLinks::class)->issue($admin, $letter, ['expires_in_days' => 7])[0] ?? null);
        $make('name', fn () => app(NameService::class)->add($this->objects['Agent'], ['type' => 'other', 'value' => 'Engelbrecht, Anna', 'language' => 'en'], $admin));
        $make('date', fn () => app(DateService::class)->add($fonds, ['property' => 'creation', 'normalized' => '1900/1950', 'expressed' => 'c. 1900-1950', 'language' => 'en'], $admin));
        $make('identifier', fn () => app(IdentifierService::class)->add($fonds, ['type' => 'other', 'value' => 'OLD-7'], $admin));
        $make('right', fn () => app(RightsService::class)->add($this->objects['Instantiation'], ['basis' => 'copyright', 'statement' => 'InC-EDU', 'copyright_status' => 'copyrighted'], $admin));
        $make('relation evidence', fn () => app(RelationService::class)->addEvidence($this->objects['Relation'], $letter, $admin));
        $make('proposal', fn () => app(ProposalService::class)->propose($letter, $admin, null, ['source' => 'test', 'entities' => [['type' => 'DATE', 'value' => '3 May 1901']]]));
        $make('embargo exception', fn () => app(Embargoes::class)->except($this->objects['Embargo'], ['ip_from' => '10.0.0.1'], $admin));
        $make('disposal', fn () => app(DisposalService::class)->initiate($letter, 'destroy', 'Duplicate', $admin));
        $make('retention review', fn () => app(RetentionService::class)->scheduleReview($letter, $this->objects['DisposalClass'] ?? null, '2027-01-01', null, $admin));
        $make('workflow queue', fn () => [$queue = app(WorkflowSlaService::class)->saveQueue($institution, null, ['name' => 'Urgent', 'slug' => 'urgent', 'sla_days' => '5'], $admin),
            app(WorkflowSlaService::class)->savePolicy($institution, null, ['name' => 'Urgent work', 'queue' => $queue->id, 'due_days' => '5', 'warning_days' => '1', 'escalation_days' => '2'], $admin)]);
        $make('registration', fn () => app(RegistrationService::class)->request(tap($institution)->update(['registration_open' => true]), 'Applicant', 'applicant@example.org', 'correct horse battery staple', 'Research'));
        $project = fn () => $this->objects['ResearchProject'];
        $make('team member', fn () => app(Projects::class)->saveTeamMember($admin, $project(), ['person_name' => 'Ada Lovelace', 'role' => 'researcher']));
        $make('project resource', fn () => app(Projects::class)->addResource($admin, $project(), ['title' => 'Council website', 'external_url' => 'https://example.org', 'resource_type' => 'external-link']));
        $make('claim evidence', fn () => app(Claims::class)->attach($admin, $this->objects['ResearchClaim'], ['source_type' => 'record', 'iri' => $letter->iri]));
        $make('decision', fn () => app(ProjectReasoning::class)->saveDecision($admin, $project(), ['summary' => 'Use the minutes', 'reason' => 'They are complete.']));
        $make('memory', fn () => app(ProjectReasoning::class)->saveMemory($admin, ['title' => 'Bridge built 1902', 'body' => 'From the minutes.', 'project_id' => $project()->id]));
        $make('analysis result', fn () => [$result = app(ProjectReasoning::class)->saveResult($admin, $project(), ['title' => 'Count of letters']),
            app(ProjectReasoning::class)->linkClaim($admin, $result, $this->objects['ResearchClaim']->id, 'supports')]);
        $make('analysis code', fn () => app(ProjectReasoning::class)->addCode($admin, $project(), 'theme-tag', 'Bridges'));
        $make('snapshot', fn () => app(ProjectAnalysis::class)->snapshot($admin, $project(), ['title' => 'Before review']));
        $make('timeline event', fn () => app(ProjectAnalysis::class)->saveEvent($admin, $project(), ['label' => 'Bridge opened', 'date_start' => '1902-05-03']));
        $make('map point', fn () => app(ProjectAnalysis::class)->savePoint($admin, $project(), ['label' => 'Bridge', 'latitude' => -33.9, 'longitude' => 18.4]));
        $make('discussion', fn () => app(Collaboration::class)->discuss($admin, $project(), ['subject' => 'Dates', 'content' => 'Which date?']));
        $make('evidence comment', fn () => app(Collaboration::class)->comment($admin, $project(), ['body' => 'Check this']));
        $make('comment', fn () => app(Collaboration::class)->addComment($admin, $this->objects['ResearchCollection'], 'Good set'));
        $make('studios', fn () => [
            $doc = app(Studios::class)->saveDoc($admin, $project(), ['title' => 'Article draft']),
            app(Studios::class)->saveSection($admin, $doc, ['heading' => 'Introduction', 'body' => 'The bridge.']),
            app(Studios::class)->comment($admin, $project(), ['body' => 'Tighten this']),
            app(Studios::class)->submit($admin, $project(), ['venue_name' => 'South African Archives Journal', 'title' => 'The bridge']),
            app(Studios::class)->saveProtocol($admin, $project(), ['template' => 'design-science', 'title' => 'Close reading']),
        ]);
        $make('research report', fn () => [$report = app(Reports::class)->create($admin, ['title' => 'Interim report', 'project_id' => $project()->id]),
            app(Reports::class)->saveSection($admin, $report, ['title' => 'Findings', 'content' => 'Twelve letters']), app(Reports::class)->requestReview($admin, $report, app(AccountService::class)->create($institution, 'Reviewer', 'reviewer@example.org', 'correct horse battery staple')->email)]);
        $make('annotation', fn () => app(Annotations::class)->save($admin, $letter, ['content' => 'The clerk signed it.']));
        $make('resolution', fn () => app(Sources::class)->propose($admin, $this->objects['Agent'], app(AgentService::class)->create($institution, 'person', 'Botha, A.', 'en')));
        $make('journal parts', fn () => [app(Journals::class)->saveIssue($admin, $this->objects['ResearchJournal'], ['volume' => '1', 'number' => '1']),
            app(Journals::class)->saveArticle($admin, $this->objects['ResearchJournal'], ['title' => 'On bridges'])]);
        $make('workspace parts', fn () => [app(ResearchWorkspaces::class)->addResource($admin, $this->objects['ResearchWorkspace'], ['resource_type' => 'link', 'title' => 'Notes', 'external_url' => 'https://example.org'])]);
        $make('access request', fn () => app(AccessRequestService::class)->createGeneralRequest($admin, ['scope' => 'item', 'request_type' => 'research', 'subject' => 'Restricted minutes', 'description' => 'Research'], $letter));
        $make('iiif parts', fn () => app(CuratedCollections::class)->add($this->objects['IiifCollection'], ['target' => $letter->iri], $admin));
        // Phase 19h: funding, grant applications and calls, plans, ethics, impact and DOIs.
        $make('funding', fn () => [$plan = app(DataPlans::class)->createPlan($admin, $project(), ['title' => 'Data plan']),
            app(Funding::class)->save($admin, $project(), ['title' => 'Bursary', 'funder_name' => 'NRF', 'dmp_id' => $plan->id]), app(Funding::class)->startDraft($admin, $project(), 'generic'),
            app(Funding::class)->saveCall($admin, ['funder' => 'NRF', 'title' => 'Thuthuka']), app(DataPlans::class)->saveApproval($admin, $project(), ['title' => 'Interviews']),
            app(Impact::class)->record($admin, $project(), ['title' => 'Cited in a report']),
            ResearchDoi::create(['project_id' => $project()->id, 'doi' => '10.80000/matrix', 'state_id' => Concept::where('iri', Vocab::BASE.'doi-state/draft')->value('id'),
                'url' => 'https://example.org/doi', 'metadata' => [], 'test' => true])]);
        // Phase 19j: lectures, training, notices, the inbox, documents, print templates, quotas and rights policies.
        $make('teaching', fn () => [$lecture = app(Teaching::class)->saveLecture($admin, ['title' => 'Minutes', 'institutional' => 1]),
            $course = app(Teaching::class)->saveCourse($admin, ['title' => 'Beginners', 'institutional' => 1]),
            app(Teaching::class)->saveModule($admin, $course, ['title' => 'Minutes', 'lecture_id' => $lecture->id]), app(Teaching::class)->enrol($admin, $course, ['learner_name' => 'Anna Botha'])]);
        $make('desk', fn () => [ResearchNotification::create(['user_id' => $admin->id, 'type_id' => Concept::where('iri', Vocab::BASE.'notification-type/system')->value('id'), 'title' => 'Hello', 'created_at' => now()]),
            app(Inbox::class)->capture($admin, ['body' => 'An idea']), $template = app(DocumentTemplates::class)->saveTemplate($admin, ['name' => 'Consent', 'fields' => 'Name | text']),
            app(DocumentTemplates::class)->saveDocument($admin, $template, ['values' => ['name' => 'Anna']]),
            app(DocumentTemplates::class)->savePrintTemplate($admin, ['name' => 'Slip', 'code' => 'slip', 'body' => '{record}', 'is_active' => 1]),
            app(Quotas::class)->save($admin, ['scope' => 'global', 'max_downloads' => 100, 'is_active' => 1]), app(RightsPolicies::class)->save($admin, $letter, ['policy_type' => 'prohibition', 'action' => 'distribute'])]);
    }

    public function test_another_institutions_administrator_is_refused_every_object_by_its_address(): void
    {
        [$leaks, $missing, $written] = $this->probe($this->ours, 'other-institution');
        $this->assertSame([], $this->unbuilt, 'fixtures that could not be built');
        $this->assertSame([], $leaks, 'answered another institution');
        $this->assertSame(0, $written, 'audit rows written on another institution\'s objects');
        $this->assertSame([], array_values(array_diff($missing, $this->uncovered())), 'routes the matrix does not cover: build their objects, or list them in tests/Fixtures/access-matrix-uncovered.txt');
    }

    public function test_an_account_with_no_role_is_refused_every_object_of_its_own_institution(): void
    {
        $nobody = app(AccountService::class)->create($this->theirs->institution, 'Nobody', 'nobody@example.org', 'correct horse battery staple');
        [$leaks, , $written] = $this->probe($nobody, 'no-role', self::ANY_ACCOUNT_READS);
        $this->assertSame([], $this->unbuilt, 'fixtures that could not be built');
        $this->assertSame([], $leaks, 'answered an account with no role');
        $this->assertSame(0, $written, 'audit rows written by an account with no role');
    }

    /** @return list<string> */
    private function uncovered(): array
    {
        return array_values(array_filter(array_map('trim', file(base_path('tests/Fixtures/access-matrix-uncovered.txt'))), fn ($l) => $l !== '' && ! str_starts_with($l, '#')));
    }

    /** Every address naming an object, as this account; what answered, which routes lack an object, and the audit rows it wrote on them. */
    private function probe(User $actor, string $name, array $allowed = []): array
    {
        $tried = $leaks = $missing = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! in_array('auth', $route->gatherMiddleware(), true) || ! $route->parameterNames()) {
                continue;
            }
            [$addresses, $lacking] = $this->addresses($route);
            if ($lacking) {
                $missing[$route->getName() ?? $route->uri()] = $lacking;

                continue;
            }
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
            foreach ($addresses as $address) {
                $this->flushSession();
                $status = $this->actingAs($actor)->call($method, $address, [])->getStatusCode();
                $refused = in_array($status, [403, 404, 410], true) || ($status === 302 && session()->has('errors'))
                    || ($method !== 'GET' && $status === 422);
                $tried[] = "$method $address";
                if (! $refused && ! in_array($route->getName(), $allowed, true)) {
                    $leaks[] = "$method $address -> $status";
                }
            }
        }
        $written = DB::table('audit_log')->where('actor_id', $actor->id)->whereIn('subject_id', collect($this->objects)->map->getKey()->values())->count();
        file_put_contents(storage_path("framework/testing/access-matrix-$name.txt"), sprintf("%d addresses tried\nanswered:\n%s\nnot covered:\n%s\n", count($tried), implode("\n", $leaks),
            collect($missing)->map(fn ($c, $r) => "$r: ".implode(', ', $c))->implode("\n")));

        return [$leaks, array_keys($missing), $written];
    }

    public function test_a_permission_never_reaches_another_institutions_object(): void
    {
        foreach (['RecordResource' => 'description', 'Agent' => 'authority', 'Instantiation' => 'description'] as $class => $kind) {
            $object = $this->objects[$class];
            $this->assertTrue($this->theirs->may('update', $kind, $object), "$class: its own administrator");
            $this->assertFalse($this->ours->may('update', $kind, $object), "$class: another institution's administrator");
        }
    }

    /** Each address the route can take with the first institution's objects, and the kinds of object it lacks. */
    private function addresses(Route $route): array
    {
        $typed = collect($route->signatureParameters(['subClass' => UrlRoutable::class]))->mapWithKeys(fn ($p) => [$p->getName() => class_basename($p->getType()->getName())]);
        $choices = $lacking = [];
        $names = false;
        foreach ($route->parameterNames() as $name) {
            $pattern = $route->wheres[$name] ?? null;
            $kind = $typed[$name] ?? null;
            if (! $kind && in_array($name, ['id', 'record'], true)) {
                $kind = isset($route->wheres['kind']) ? null : 'RecordResource';
            }
            if ($kind || ($name === 'id' && isset($route->wheres['kind']))) {
                $names = true;
            }
            if ($kind) {
                isset($this->objects[$kind]) ? $choices[$name] = [$this->objects[$kind]->getRouteKey()] : $lacking[] = $kind;
            } elseif ($pattern && preg_match('/^[a-z|_-]+$/i', $pattern)) {
                $choices[$name] = explode('|', $pattern);
            } elseif ($name !== 'id') {
                $lacking[] = '{'.$name.'}';
            }
        }
        if ($lacking || ! $names) {
            return [[], array_unique($lacking)];
        }
        $addresses = [[]];
        foreach ($choices as $name => $values) {
            $addresses = array_merge(...array_map(fn ($a) => array_map(fn ($v) => $a + [$name => $v], $values), $addresses));
        }
        $out = [];
        foreach ($addresses as $values) {
            if (isset($values['kind']) && ! isset($values['id']) && in_array('id', $route->parameterNames(), true)) {
                $kind = self::KINDS[$values['kind']] ?? null;
                if (! $kind || ! isset($this->objects[$kind])) {
                    $lacking[] = $kind ?? '{kind='.$values['kind'].'}';

                    continue;
                }
                $values['id'] = $this->objects[$kind]->getKey();
            }
            $uri = $route->uri();
            foreach ($values as $name => $value) {
                $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], $value, $uri);
            }
            $out[] = '/'.ltrim(preg_replace('/\{[^}]+\?\}/', '', $uri), '/');
        }

        return [$out, array_unique($lacking)];
    }
}
