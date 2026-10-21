<?php

// OpenRiC Workspace - import units of description from CSV or EAD: a dry run, then the import itself (WS-015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// A dry run is the import itself, made in a transaction that is rolled back,
// so what it reports is what importing does, not a guess. Each unit is
// matched first by its persistent IRI, then by its key in the same source
// (import_keys), then, when asked, by its identifier under the same parent;
// otherwise it is created. An empty field never removes a value. Creators,
// subjects, places and names are linked to the entity the file names by IRI,
// or to the one entity of that name; two of that name is ambiguous and not
// linked; none is created where the account may and the import was asked
// to, else left out. Relations an import makes are proposed until a person
// accepts them (ADR 0006). A unit that fails is not imported and is listed,
// with every unit inside it; the others go on. Everything is written through
// the services the screens use, so every rule and the audit log apply.
// ponytail: one transaction for the whole file, holding the audit chain lock
// throughout; a file of many thousands of units wants a queued job in batches.

namespace App\Exchange\Import;

use App\Audit\AuditLog;
use App\Description\AgentService;
use App\Description\ContactPointService;
use App\Description\DateService;
use App\Description\DescriptionService;
use App\Description\Edtf;
use App\Description\Elements;
use App\Description\IdentifierService;
use App\Description\InstantiationService;
use App\Description\PlaceService;
use App\Description\RecordService;
use App\Description\RepositoryService;
use App\Description\StorageService;
use App\Exchange\Ead;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\ContactPoint;
use App\Models\Container;
use App\Models\DigitalFile;
use App\Models\Import;
use App\Models\Institution;
use App\Models\Name;
use App\Models\Place;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Support\Vocab;
use App\Vocabulary\ConceptService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ImportService
{
    public const FORMATS = ['csv' => 'Archival descriptions: CSV (AtoM ISAD(G) columns)', 'ead' => 'Archival descriptions: EAD 2002 or EAD3',
        'authority-csv' => 'Authority records: CSV (AtoM ISAAR(CPF) columns)', 'repository-csv' => 'Repositories: CSV (AtoM ISDIAH columns)'];

    /** The table each format's entries become. */
    public const TABLES = ['csv' => 'record_resources', 'ead' => 'record_resources', 'authority-csv' => 'agents', 'repository-csv' => 'repositories'];

    /** The permission kind that governs each table's entries. */
    private const PERMISSIONS = ['record_resources' => 'description', 'agents' => 'authority', 'repositories' => 'repository'];

    private const LEVELS = Vocab::BASE.'level-of-description/';

    private Import $import;

    private User $user;

    private Institution $institution;

    private Report $report;

    /** @var array<string, RecordResource> units by their key in this file */
    private array $byKey = [];

    private bool $commit = false;

    /** @var array<string, bool> keys of units not imported, so the units inside them are not either */
    private array $failed = [];

    /** @var array<string, ?Model> agents, places and concepts already resolved in this file */
    private array $resolved = [];

    /** @var array<string, int> keys of the units the file puts other units inside */
    private array $parents = [];

    /** Entries imported in one transaction; see run(). */
    private const PART = 20;

    public function __construct(private RecordService $records, private IdentifierService $identifiers, private DateService $dates,
        private RelationService $relations, private AgentService $agents, private PlaceService $places, private ConceptService $concepts,
        private RepositoryService $repositories, private ContactPointService $contacts, private InstantiationService $instantiations,
        private DescriptionService $descriptions) {}

    /** The permission kind an import of this format needs: description, authority or repository. */
    public static function permission(string $format): string
    {
        return self::PERMISSIONS[self::TABLES[$format] ?? 'record_resources'];
    }

    /** Read the file and import it; a dry run rolls everything back. The report is stored on the import. */
    public function run(Import $import, bool $commit): Report
    {
        $this->import = $import;
        $this->user = $import->user;
        $this->institution = Institution::findOrFail($import->institution_id);
        $this->report = new Report;
        $this->byKey = $this->failed = $this->resolved = $this->parents = [];
        $this->commit = $commit;
        try {
            $drafts = match ($import->format) {
                'ead' => app(EadReader::class)->read($import->content, $import->language, $this->report),
                'authority-csv' => app(AtomEntityReader::class)->read('authority', $import->content, $import->language, $this->report),
                'repository-csv' => app(AtomEntityReader::class)->read('repository', $import->content, $import->language, $this->report),
                default => app(CsvReader::class)->read($import->content, $import->language, $this->report),
            };
        } catch (InvalidArgumentException $e) {
            $this->report->issue('whole file', 'file', $import->file_name, $e->getMessage(), 'rejected');
            $import->update(['report' => $this->report->toArray()]);

            return $this->report;
        }

        $this->parents = array_flip(array_filter(array_map(fn ($d) => $d->parentKey, $drafts), fn ($k) => $k !== null));
        // In parts of PART entries, each its own transaction. PostgreSQL holds a lock for every savepoint that writes until
        // its transaction ends, in a table every database on the server shares; one transaction for a finding aid of a few
        // hundred units held thousands and stalled the server. Committed, a part stays when a later one fails, and the
        // report names what was refused. A dry run rolls each part back, after first making again, unreported, the
        // units above it that an earlier part made, so a unit is checked inside its real parent.
        $byKey = [];
        foreach ($drafts as $draft) {
            $byKey[$draft->key] = $draft;
        }
        foreach (array_chunk($drafts, self::PART) as $part) {
            try {
                DB::transaction(function () use ($part, $byKey, $commit) {
                    AuditLog::actingAs($this->user, function () use ($part, $byKey, $commit) {
                        if (! $commit) {
                            $this->remakeAncestors($part, $byKey);
                        }
                        foreach ($part as $draft) {
                            $this->unit($draft);
                        }
                    });
                    // The database's deferred checks run now, so a dry run meets them too; then they are deferred
                    // again, as every deferrable constraint of the schema is by default, for whatever the
                    // surrounding transaction does next.
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                    DB::statement('SET CONSTRAINTS ALL DEFERRED');
                    if (! $commit) {
                        throw new DryRun;
                    }
                });
            } catch (DryRun) {
                // Rolled back: what this part made does not exist, so nothing found or made in it is kept for the next.
                $this->resolved = [];
            } catch (QueryException $e) {
                // A deferred check refused the part: none of it was kept.
                $keys = array_flip(array_map(fn ($d) => $d->key, $part));
                $this->report->units = array_values(array_filter($this->report->units, fn ($u) => ! isset($keys[$u['key']])));
                foreach ($part as $draft) {
                    $this->failed[$draft->key] = true;
                    $this->report->unit($draft, 'rejected', null, 'the database refused the part of the file it was in: '.$this->reason($e));
                }
                $this->resolved = [];
            }
        }
        if (! $commit) {
            // Rolled back: units created in a dry run do not exist.
            foreach ($this->report->units as &$unit) {
                if ($unit['action'] === 'created') {
                    $unit['unit_id'] = null;
                }
            }
            unset($unit);
            // A term made again in each part of a dry run is reported once.
            $this->report->issues = array_values(array_intersect_key($this->report->issues,
                array_unique(array_map(fn ($i) => $i['kind'] === 'created' ? $i['kind'].'|'.$i['field'].'|'.$i['value'] : serialize($i), $this->report->issues))));
        }

        $report = $this->report->toArray();
        if ($commit) {
            $report['reconciliation'] = $this->reconcile();
        }
        $import->update(['report' => $report, 'committed_at' => $commit ? now() : null]);

        return $this->report;
    }

    /**
     * In a dry run, make again the units above this part's units that an earlier, rolled-back part made, top down,
     * leaving the report as it was: they were reported in their own part.
     *
     * @param  list<UnitDraft|EntityDraft>  $part
     * @param  array<string, UnitDraft|EntityDraft>  $byKey
     */
    private function remakeAncestors(array $part, array $byKey): void
    {
        $inPart = array_flip(array_map(fn ($d) => $d->key, $part));
        $needed = [];
        foreach ($part as $draft) {
            $chain = [];
            for ($key = $draft->parentKey; $key !== null && isset($byKey[$key]) && ! isset($inPart[$key]) && ! isset($needed[$key]); $key = $byKey[$key]->parentKey) {
                $chain[] = $key;
            }
            foreach (array_reverse($chain) as $key) {
                $needed[$key] = true;
            }
        }
        $this->byKey = array_diff_key($this->byKey, $needed);
        $saved = [$this->report->units, $this->report->counts, $this->report->issues, $this->failed];
        foreach (array_keys($needed) as $key) {
            if (! isset($this->failed[$key])) {
                $this->unit($byKey[$key]);
            }
        }
        [$this->report->units, $this->report->counts, $this->report->issues, $this->failed] = $saved;
    }

    private function unit(UnitDraft|EntityDraft $draft): void
    {
        if ($draft->parentKey !== null && isset($this->failed[$draft->parentKey])) {
            $this->failed[$draft->key] = true;
            $this->report->unit($draft, 'rejected', null, 'the unit it is inside was not imported');

            return;
        }
        $before = $this->seq();
        [$resolved, $counts, $issues] = [$this->resolved, $this->report->counts, count($this->report->issues)];
        try {
            $unit = DB::transaction(fn () => $this->withoutInnerSavepoints(fn () => match (true) {
                $draft instanceof UnitDraft => $this->write($draft),
                $this->import->format === 'authority-csv' => $this->authority($draft),
                default => $this->repository($draft),
            }));
        } catch (InvalidArgumentException|QueryException|RuntimeException $e) {
            // Rolled back to before this unit: what it created does not exist, so forget it.
            $this->resolved = $resolved;
            $this->report->counts = $counts;
            $this->report->issues = [...array_slice($this->report->issues, 0, $issues),
                ...array_values(array_filter(array_slice($this->report->issues, $issues), fn ($i) => $i['kind'] !== 'created'))];
            $this->failed[$draft->key] = true;
            $this->report->unit($draft, 'rejected', null, $e instanceof QueryException ? 'the database refused it: '.$this->reason($e) : $e->getMessage());

            return;
        }
        if ($draft instanceof UnitDraft) {
            $this->byKey[$draft->key] = $unit;
        }
        $this->report->unit($draft, $unit->wasRecentlyCreated ? 'created' : ($this->seq() !== $before ? 'updated' : 'unchanged'), $unit->id);
    }

    /**
     * Run a unit's work inside its one savepoint, with the services it calls opening none of their own. PostgreSQL keeps
     * a lock for every savepoint that writes until the transaction ends, in a table every database on the server shares;
     * an entry with many names and access points opened some 75. An error inside rises to the unit's savepoint, which
     * the caller rolls back with the real grammar restored, so the unit is refused whole and nothing of it is kept.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function withoutInnerSavepoints(callable $work): mixed
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new class($connection) extends PostgresGrammar
        {
            public function supportsSavepoints(): bool
            {
                return false;
            }
        });
        try {
            return $work();
        } finally {
            $connection->setQueryGrammar($grammar);
        }
    }

    private function write(UnitDraft $draft): RecordResource
    {
        $parent = $this->parent($draft);
        $unit = $this->match($draft, $parent);
        $language = $draft->language($this->import->language);
        $values = $draft->values;
        $levelIri = $this->level($draft, $unit === null);

        if ($unit === null) {
            $holder = $parent ? $this->holder($parent) : ($this->namedRepository($draft) ?? Repository::findOrFail($this->import->repository_id));
            if (! $this->user->may('create', 'description', $parent ?? $holder)) {
                throw new InvalidArgumentException('you may not create units of description '.($parent ? 'inside that unit' : 'in that repository'));
            }
            $title = $values[$language]['title'] ?? throw new InvalidArgumentException('it has no title in '.$language.', and a new unit needs one');
            $unit = $this->records->create($this->institution, $levelIri, $title, $language, $parent, $parent ? null : $holder, $draft->identifier, $this->user);
            $unit->wasRecentlyCreated = true;
        } else {
            if (! $this->user->may('update', 'description', $unit)) {
                throw new InvalidArgumentException('it matches '.$unit->iri.', which you may not update');
            }
            $unit->wasRecentlyCreated = false;
            if (($draft->identifier !== null && $draft->identifier !== $unit->identifier) || ($levelIri !== null && $levelIri !== $unit->level->iri)) {
                $this->records->updateIdentity($unit, $draft->identifier ?? $unit->identifier, $levelIri ?? $unit->level->iri, $this->user);
            }
        }
        $holder = $this->holder($unit);
        if ($draft->repositoryName !== null && mb_strtolower($draft->repositoryName) !== mb_strtolower((string) EntityKinds::label($holder))) {
            $this->report->issue($draft->location, 'repository', $draft->repositoryName, $unit->parent_id
                ? 'a unit inside another is held by the holder of the unit at the top, '.EntityKinds::label($holder)
                : 'held by '.EntityKinds::label($holder).', the repository chosen for the import: no one repository here has this name', 'note');
        }
        foreach ($values as $valueLanguage => $byElement) {
            $this->records->describe($unit, $byElement, $valueLanguage, $this->user);
            $this->report->counts['values'] += count($byElement);
        }
        $this->otherIdentifiers($draft, $unit);
        $this->control($draft, $unit);
        $this->unitDates($draft, $unit);
        $this->links($draft, $unit, $language);
        foreach ($draft->files as $file) {
            $this->file($draft, $unit, $file['uri'], $file['sha256'], $language);
        }
        $this->key($draft->key, $unit);

        return $unit;
    }

    /** Tie the entry's key in this source to the entity it became, so importing again updates it. */
    private function key(string $key, Model $entity): void
    {
        DB::table('import_keys')->upsert([['id' => (string) Str::uuid7(), 'institution_id' => $this->institution->id, 'source' => $this->import->source,
            'subject_table' => $entity->getTable(), 'key' => $key, 'subject_id' => $entity->getKey(), 'import_id' => $this->import->id,
            'created_at' => now(), 'updated_at' => now()]],
            ['institution_id', 'source', 'subject_table', 'key'], ['subject_id', 'import_id', 'updated_at']);
    }

    /** The repository that holds a unit: its own, or that of the unit at the top. */
    private function holder(RecordResource $unit): Repository
    {
        return $unit->repository ?? ($unit->ancestors()[0] ?? null)?->repository ?? Repository::findOrFail($this->import->repository_id);
    }

    /** The one repository with the authorised name the file gives, where the account may describe; else null. */
    private function namedRepository(UnitDraft $draft): ?Repository
    {
        if ($draft->repositoryName === null) {
            return null;
        }
        $found = Repository::where('institution_id', $this->institution->id)->whereNull('deleted_at')
            ->whereIn('id', $this->namedQuery('repositories', $draft->repositoryName))->get();

        return $found->count() === 1 && $this->user->may('create', 'description', $found->first()) ? $found->first() : null;
    }

    /**
     * The file AtoM kept for the unit, read from the copy of AtoM's uploads folder an administrator made
     * (WORKSPACE_IMPORT_FILES), where the path of digitalObjectURI after /uploads/ finds it, and checked
     * against AtoM's checksum. A path that climbs out of the folder is refused. A dry run checks the file and keeps
     * nothing; a unit that has the file already (the same SHA-256) does not take it again.
     * ponytail: files are hashed and kept one by one inside the import's transaction; many large files want a queued job.
     */
    private function file(UnitDraft $draft, RecordResource $unit, string $uri, ?string $sha256, string $language): void
    {
        if ($sha256 !== null && DigitalFile::where('sha256', $sha256)->whereHas('use', fn ($q) => $q->where('notation', 'master'))
            ->whereHas('instantiation', fn ($q) => $q->where('record_resource_id', $unit->id)->whereNull('deleted_at'))->exists()) {
            return;
        }
        $root = config('workspace.import_files');
        $root = $root ? realpath($root) : false;
        if ($root === false) {
            $this->report->issue($draft->location, 'digitalObjectURI', $uri, 'no copy of AtoM\'s uploads folder is set up for imports (WORKSPACE_IMPORT_FILES); the file is not kept', 'left-out');

            return;
        }
        // The file's path inside the folder, from the URI: a segment that climbs out (..) is refused. The folder's
        // own symbolic links are the administrator's (AtoM's uploads often link to other storage) and are followed.
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $at = strpos($path, '/uploads/');
        $segments = $at === false ? [] : explode('/', rawurldecode(substr($path, $at + strlen('/uploads/'))));
        $safe = $segments !== [] && ! array_filter($segments, fn ($s) => $s === '' || $s === '.' || $s === '..' || str_contains($s, "\0"));
        $local = $safe ? $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments) : false;
        if ($local === false || ! is_file($local) || ! is_readable($local)) {
            $this->report->issue($draft->location, 'digitalObjectURI', $uri, 'not in the copy of AtoM\'s uploads folder; the file is not kept', 'left-out');

            return;
        }
        if (filesize($local) > config('workspace.max_upload_megabytes') * 1024 * 1024) {
            $this->report->issue($draft->location, 'digitalObjectURI', $uri, 'larger than '.config('workspace.max_upload_megabytes').' MB; the file is not kept', 'left-out');

            return;
        }
        if ($sha256 !== null && ! hash_equals($sha256, hash_file('sha256', $local))) {
            $this->report->issue($draft->location, 'digitalObjectChecksum', $sha256, 'the file in the copy is not the one AtoM kept: its SHA-256 differs; not kept', 'left-out');

            return;
        }
        if (! $this->commit) {
            $this->report->issue($draft->location, 'digitalObjectURI', basename($local), 'found'.($sha256 ? ' and its SHA-256 matches' : ', with no checksum to compare').'; kept as a digital instantiation when imported', 'note');

            return;
        }
        if (! $this->user->may('update', 'description', $unit)) {
            $this->report->issue($draft->location, 'digitalObjectURI', $uri, 'you may not add instantiations to this unit; the file is not kept', 'left-out');

            return;
        }
        // The store takes the file it is given; a copy keeps the uploads folder as it was.
        $copy = tempnam(sys_get_temp_dir(), 'import');
        try {
            copy($local, $copy);
            $this->instantiations->upload($unit, $copy, basename($local), $language, null, $sha256, 'online', $this->user);
            $this->report->counts['files']++;
        } catch (RuntimeException|InvalidArgumentException $e) {
            $this->report->issue($draft->location, 'digitalObjectURI', $uri, 'not kept: '.$e->getMessage(), 'left-out');
        } finally {
            @unlink($copy);
        }
    }

    /** An authority record (ISAAR(CPF)): matched by its key in this source, or, when asked, by its one authorised name. */
    private function authority(EntityDraft $draft): Agent
    {
        $type = $this->agentType($draft);
        $agent = $this->keyed(Agent::class, $draft->key) ?? $this->byName(Agent::class, 'agents', $draft, $type);
        $language = $draft->language($this->import->language);
        if ($agent === null) {
            if (! $this->user->may('create', 'authority')) {
                throw new InvalidArgumentException('you may not create authority records');
            }
            $type ?? throw new InvalidArgumentException('it has no type of entity: choose a kind for authority records with none, or give typeOfEntity');
            $name = $draft->title() ?? throw new InvalidArgumentException('it has no authorised form of name, and a new authority record needs one');
            $agent = $this->agents->create($this->institution, $type, $name, $language, $draft->identifier, $this->user);
            $agent->wasRecentlyCreated = true;
        } else {
            if ($agent->merged_into_id !== null || ! $this->user->may('update', 'authority', $agent)) {
                throw new InvalidArgumentException('it matches '.$agent->iri.', which you may not update');
            }
            $agent->wasRecentlyCreated = false;
            if ($type !== null && $type !== $agent->agentType->notation) {
                $this->report->issue($draft->location, 'typeOfEntity', $draft->type, 'the authority record here is a '.$agent->agentType->label(['en']).'; its type is kept', 'left-out');
            }
            if ($draft->identifier !== null && $draft->identifier !== $agent->identifier) {
                $this->identifiers->setLocal($agent, $draft->identifier, $this->user);
            }
        }
        $this->describeEntity($agent, Elements::ISAAR_CPF, $draft, 'agent-authorised-name');
        [$status, $detail] = [$this->controlConcept($draft, $draft->status, Vocab::DESCRIPTION_STATUSES, 'status'), $this->controlConcept($draft, $draft->detail, Vocab::DESCRIPTION_DETAILS, 'levelOfDetail')];
        if ($draft->descriptionIdentifier !== null || $status !== null || $detail !== null) {
            $this->agents->updateControl($agent, ['type' => $agent->agentType->notation, 'description_identifier' => $draft->descriptionIdentifier ?? $agent->description_identifier,
                'maintainer' => $agent->maintainer_id, 'status' => $status ?? $agent->descriptionStatus?->iri, 'detail' => $detail ?? $agent->descriptionDetail?->iri,
                'embargo_until' => $agent->embargo_until?->format('Y-m-d')], $this->user);
        }
        foreach ($draft->occupations as $occupation) {
            if ($agent->agentType->notation !== 'person') {
                $this->report->issue($draft->location, 'actorOccupations', $occupation['name'], 'in RiC-O an occupation is a person\'s; not linked', 'left-out');
            } elseif ($concept = $this->term($draft, 'occupation-type', 'actorOccupations', $occupation['name'], $language)) {
                $this->relate($draft, $agent, 'occupation', $concept, array_filter(['description' => $occupation['note'], 'language' => $occupation['note'] ? $language : null]));
            }
        }
        foreach ($draft->places as $name) {
            if ($place = $this->place($draft, $name, null, $language)) {
                $this->relate($draft, $place, 'place', $agent);
            }
        }
        $this->key($draft->key, $agent);

        return $agent;
    }

    /** A repository (ISDIAH): matched by its key in this source, or, when asked, by its one authorised name. */
    private function repository(EntityDraft $draft): Repository
    {
        $repository = $this->keyed(Repository::class, $draft->key) ?? $this->byName(Repository::class, 'repositories', $draft);
        $language = $draft->language($this->import->language);
        if ($repository === null) {
            if (! $this->user->may('create', 'repository')) {
                throw new InvalidArgumentException('you may not create repositories');
            }
            $name = $draft->title() ?? throw new InvalidArgumentException('it has no authorised form of name, and a new repository needs one');
            $repository = $this->repositories->create($this->institution, $name, $language, $draft->identifier, $this->user);
            $repository->wasRecentlyCreated = true;
        } else {
            if (! $this->user->may('update', 'repository', $repository)) {
                throw new InvalidArgumentException('it matches '.$repository->iri.', which you may not update');
            }
            $repository->wasRecentlyCreated = false;
            if ($draft->identifier !== null && $draft->identifier !== $repository->identifier) {
                $this->repositories->updateIdentity($repository, $draft->identifier, $this->user);
            }
        }
        $this->describeEntity($repository, Elements::ISDIAH, $draft, 'authorised-name');
        [$status, $detail] = [$this->controlConcept($draft, $draft->status, Vocab::DESCRIPTION_STATUSES, 'descriptionStatus'), $this->controlConcept($draft, $draft->detail, Vocab::DESCRIPTION_DETAILS, 'levelOfDetail')];
        if ($draft->descriptionIdentifier !== null || $status !== null || $detail !== null) {
            $this->repositories->updateControl($repository, $draft->descriptionIdentifier ?? $repository->description_identifier,
                $status ?? $repository->descriptionStatus?->iri, $detail ?? $repository->descriptionDetail?->iri, $this->user);
        }
        if ($draft->contact !== []) {
            // The file's contact fills the primary contact point; an empty field leaves what is there.
            $contact = $repository->contactPoints()->orderByDesc('is_primary')->first();
            $fields = [...array_filter($contact?->only(ContactPoint::FIELDS) ?? [], fn ($v) => $v !== null), ...$draft->contact];
            if (isset($fields['country_code']) && ! preg_match('/^[A-Za-z]{2}$/', $fields['country_code'])) {
                $this->report->issue($draft->location, 'country', $fields['country_code'], 'not an ISO 3166 two-letter country code; left out', 'left-out');
                unset($fields['country_code']);
            }
            if ($contact === null || array_diff_assoc(array_map('strval', $fields), array_map('strval', array_filter($contact->only(ContactPoint::FIELDS), fn ($v) => $v !== null))) !== []) {
                $this->contacts->save($repository, [...$fields, 'is_primary' => true], $contact, $this->user);
            }
        }
        $this->key($draft->key, $repository);

        return $repository;
    }

    /**
     * Write the entry's values in each language. Names of a type other than authorised are added to the
     * names already there, never put in their place, so an import never removes a name.
     */
    private function describeEntity(Model $entity, string $standard, EntityDraft $draft, string $required): void
    {
        foreach ($draft->values as $language => $values) {
            foreach ($values as $notation => $text) {
                $type = Elements::nameType($notation);
                if ($type !== null && $type !== 'authorised') {
                    $have = Name::where(['subject_table' => $entity->getTable(), 'subject_id' => $entity->getKey(), 'language' => $language])
                        ->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.$type))->pluck('value')->all();
                    $values[$notation] = implode("\n", array_unique([...$have, ...explode("\n", $text)]));
                }
            }
            $this->descriptions->describe($entity, $standard, $values, $language, $required, $this->user);
            $this->report->counts['values'] += count($values);
        }
    }

    /** An authority record's type from the file's type of entity, by notation or label, or the kind chosen for the import. */
    private function agentType(EntityDraft $draft): ?string
    {
        if ($draft->type === null) {
            $chosen = ($this->import->options['agent_type'] ?? '') ?: null;
            if ($chosen !== null) {
                $this->report->issue($draft->location, 'typeOfEntity', null, 'no type of entity in the file; a '.str_replace('-', ' ', $chosen).', as chosen for the import', 'note');
            }

            return $chosen;
        }
        $wanted = mb_strtolower($draft->type);
        $type = Concept::choices(Agent::TYPES)->get()->first(fn (Concept $c) => mb_strtolower((string) $c->notation) === $wanted
            || $c->labels->contains(fn ($l) => mb_strtolower($l->pref_label) === $wanted));

        return $type?->notation ?? throw new InvalidArgumentException('"'.$draft->type.'" is not a type of entity the Workspace knows');
    }

    /**
     * The one entity of this class with the entry's authorised name (and type), when the import was asked to match by name.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return ?T
     */
    private function byName(string $class, string $table, EntityDraft $draft, ?string $type = null): ?Model
    {
        if (! ($this->import->options['match_identifier'] ?? false) || $draft->title() === null) {
            return null;
        }
        $same = $class::where('institution_id', $this->institution->id)->whereNull('deleted_at')
            ->when($class === Agent::class, fn ($q) => $q->whereNull('merged_into_id'))
            ->when($type !== null, fn ($q) => $q->whereHas('agentType', fn ($q) => $q->where('iri', Agent::TYPES.$type)))
            ->whereIn('id', $this->namedQuery($table, $draft->title()))->get();
        if ($same->count() > 1) {
            $this->report->issue($draft->location, 'authorizedFormOfName', $draft->title(), $same->count().' entries here have this name; a new one is made', 'ambiguous');
        }

        return $same->count() === 1 ? $same->first() : null;
    }

    /** A status or level of detail by notation or label, as a concept IRI; null when the file gives none or one the Workspace does not know. */
    private function controlConcept(EntityDraft $draft, ?string $label, string $vocabulary, string $field): ?string
    {
        if ($label === null) {
            return null;
        }
        $wanted = mb_strtolower($label);
        $found = Concept::choices($vocabulary)->get()->first(fn (Concept $c) => mb_strtolower((string) $c->notation) === $wanted
            || $c->labels->contains(fn ($l) => mb_strtolower($l->pref_label) === $wanted));
        if ($found === null) {
            $this->report->issue($draft->location, $field, $label, 'not a value the Workspace knows; left as it was', 'left-out');
        }

        return $found?->iri;
    }

    private function parent(UnitDraft $draft): ?RecordResource
    {
        if ($draft->parentKey !== null) {
            return $this->byKey[$draft->parentKey] ?? $this->keyed(RecordResource::class, $draft->parentKey)
                ?? throw new InvalidArgumentException('the unit it is inside ('.$draft->parentKey.') is not earlier in the file nor imported before from this source');
        }
        if ($draft->parentIri !== null && ($found = $this->existing(RecordResource::where('iri', $draft->parentIri)))) {
            return $found;
        }

        return $this->import->parent_id ? RecordResource::find($this->import->parent_id) : null;
    }

    /** The unit this one already is: by persistent IRI, by its key in this source, or by identifier under the same parent. */
    private function match(UnitDraft $draft, ?RecordResource $parent): ?RecordResource
    {
        if ($draft->iri !== null && ($unit = $this->existing(RecordResource::where('iri', $draft->iri)))) {
            return $unit;
        }
        if ($unit = $this->keyed(RecordResource::class, $draft->key)) {
            return $unit;
        }
        if (($this->import->options['match_identifier'] ?? false) && $draft->identifier !== null) {
            $same = RecordResource::where('institution_id', $this->institution->id)->whereNull('deleted_at')->where('parent_id', $parent?->id)
                ->when($parent === null, fn ($q) => $q->where('repository_id', $this->import->repository_id))
                ->whereIn('id', DB::table('identifiers')->where(['subject_table' => 'record_resources', 'value' => $draft->identifier])->select('subject_id'))->get();
            if ($same->count() > 1) {
                $this->report->issue($draft->location, 'identifier', $draft->identifier, $same->count().' units have this identifier there; a new unit is made', 'ambiguous');
            }

            return $same->count() === 1 ? $same->first() : null;
        }

        return null;
    }

    /**
     * The entity a key of this source became, unless it was deleted since.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return ?T
     */
    private function keyed(string $class, string $key): ?Model
    {
        $table = (new $class)->getTable();
        $id = DB::table('import_keys')->where(['institution_id' => $this->institution->id, 'source' => $this->import->source, 'subject_table' => $table, 'key' => $key])->value('subject_id');

        return $id ? $class::whereKey($id)->where('institution_id', $this->institution->id)->whereNull('deleted_at')->first() : null;
    }

    private function existing($query): ?RecordResource
    {
        return $query->where('institution_id', $this->institution->id)->whereNull('deleted_at')->first();
    }

    /** The level the file gives, by EAD level, notation or label in any language; null to keep it, or a failure for a new unit. */
    private function level(UnitDraft $draft, bool $new): ?string
    {
        if ($draft->level === null) {
            if (! $new) {
                return null;
            }
            $default = ($this->import->options['default_level'] ?? '') ?: null;
            $level = $default ? Concept::choices(self::LEVELS)->get()->first(fn (Concept $c) => $c->notation === $default) : null;
            if ($level === null) {
                throw new InvalidArgumentException('it has no level of description, and a new unit needs one: choose a level for units with none, or give one in the file');
            }
            $this->report->issue($draft->location, 'level', null, 'no level of description in the file; '.$level->label(['en']).', as chosen for the import', 'note');

            return $level->iri;
        }
        $wanted = mb_strtolower($draft->level);
        $wanted = array_flip(Ead::LEVEL_NAMES)[$wanted] ?? $wanted;
        // The bundled levels, then the institution's own, by notation or by label.
        $levels = Concept::bundledThenLocal(self::LEVELS, 'level-of-description', $this->institution->id);
        $level = $levels->first(fn (Concept $c) => $c->notation !== null && mb_strtolower((string) $c->notation) === $wanted)
            ?? $levels->first(fn (Concept $c) => $c->labels->contains(fn ($l) => mb_strtolower($l->pref_label) === $wanted))
            ?? $this->newLevel($draft);
        if ($level === null) {
            if ($new) {
                throw new InvalidArgumentException('"'.$draft->level.'" is not a level of description the Workspace knows; let the import make new terms, or add the level to a level-of-description vocabulary');
            }
            $this->report->issue($draft->location, 'level', $draft->level, 'not a level of description the Workspace knows; the level is kept', 'left-out');
        }

        return $level?->iri;
    }

    /**
     * A level the file names and the Workspace does not have (AtoM lets an institution add levels): made in the
     * institution's level-of-description vocabulary where the import may make terms. What its units are exported as
     * is read from the file: a level with units inside it is a rico:RecordSet, one without a rico:Record. It can be
     * changed in the vocabulary afterwards.
     */
    private function newLevel(UnitDraft $draft): ?Concept
    {
        $cacheKey = 'level:'.mb_strtolower((string) $draft->level);
        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }
        if (! ($this->import->options['create_terms'] ?? false) || ! $this->user->may('create', 'vocabulary')) {
            return null;
        }
        $class = isset($this->parents[$draft->key]) ? 'rico:RecordSet' : 'rico:Record';
        $concept = $this->concepts->create($this->concepts->schemeOfKind($this->institution, 'level-of-description', $this->user), ['label' => $draft->level,
            'language' => $this->import->language, 'scope_note' => "A level of description from {$this->import->source}; its units are exported as $class."], $this->user);
        $this->report->counts['concepts']++;
        $this->report->issue($draft->location, 'level', $draft->level, "a new level of description, its units exported as $class", 'created');

        return $this->resolved[$cacheKey] = $concept;
    }

    private function otherIdentifiers(UnitDraft $draft, RecordResource $unit): void
    {
        $types = Concept::choices(Vocab::IDENTIFIER_TYPES)->get();
        foreach ($draft->identifiers as $identifier) {
            $wanted = mb_strtolower($identifier['type']);
            $type = $types->first(fn (Concept $c) => mb_strtolower((string) $c->notation) === $wanted || $c->labels->contains(fn ($l) => mb_strtolower($l->pref_label) === $wanted));
            if ($type === null) {
                $this->report->issue($draft->location, 'identifier type', $identifier['type'], 'not an identifier type the Workspace knows; kept as another identifier', 'note');
            }
            $notation = $type?->notation ?? 'other';
            if ($notation === 'local') {
                continue;
            }
            $unit->load('identifiers.identifierType');
            if (! $unit->identifiers->contains(fn ($i) => $i->identifierType->notation === $notation && $i->value === $identifier['value'])) {
                $this->identifiers->add($unit, ['type' => $notation, 'value' => $identifier['value']], $this->user);
            }
        }
    }

    private function control(UnitDraft $draft, RecordResource $unit): void
    {
        if ($draft->descriptionIdentifier === null && $draft->status === null && $draft->detail === null) {
            return;
        }
        $concept = function (?string $label, string $vocabulary, string $field) use ($draft) {
            if ($label === null) {
                return false;
            }
            $wanted = mb_strtolower($label);
            $found = Concept::choices($vocabulary)->get()->first(fn (Concept $c) => mb_strtolower((string) $c->notation) === $wanted
                || $c->labels->contains(fn ($l) => mb_strtolower($l->pref_label) === $wanted));
            if ($found === null) {
                $this->report->issue($draft->location, $field, $label, 'not a value the Workspace knows; left as it was', 'left-out');

                return false;
            }

            return $found->iri;
        };
        $status = $concept($draft->status, Vocab::DESCRIPTION_STATUSES, 'descriptionStatus');
        $detail = $concept($draft->detail, Vocab::DESCRIPTION_DETAILS, 'levelOfDetail');
        $this->records->updateControl($unit, $draft->descriptionIdentifier ?? $unit->description_identifier,
            $status === false ? $unit->descriptionStatus?->iri : $status, $detail === false ? $unit->descriptionDetail?->iri : $detail, $this->user);
    }

    private function unitDates(UnitDraft $draft, RecordResource $unit): void
    {
        foreach ($draft->dates as $date) {
            $unit->load('dates.property');
            // The same date is the same span, however it is written: 1950-06 and 1950-06-01/1950-06-30 are one.
            $bounds = $date['normalized'] !== null ? Edtf::bounds($date['normalized']) : null;
            $same = $unit->dates->contains(fn ($d) => $d->property->notation === $date['property']
                && ($date['normalized'] === null ? $d->normalized_value === null
                    : ($d->normalized_value === $date['normalized'] || ($bounds !== null && [$d->earliest?->format('Y-m-d'), $d->latest?->format('Y-m-d')] === $bounds)))
                // A date with no written form is exported with its EDTF as the text; that text is not a new written form.
                && ($date['expressed'] === null || $d->expressed === $date['expressed'] || ($d->expressed === null && $date['expressed'] === $d->normalized_value)));
            if ($same) {
                continue;
            }
            try {
                $this->dates->add($unit, ['property' => $date['property'], 'normalized' => $date['normalized'], 'expressed' => $date['expressed'],
                    'language' => $date['expressed'] ? $date['language'] : null], $this->user);
            } catch (InvalidArgumentException $e) {
                $this->report->issue($draft->location, 'date', $date['normalized'] ?? $date['expressed'], $e->getMessage().'; left out', 'left-out');
            }
        }
    }

    /** Creators, access points and associated units, each as a relation the import proposes. */
    private function links(UnitDraft $draft, RecordResource $unit, string $language): void
    {
        foreach ($draft->creators as $creator) {
            if ($agent = $this->agent($draft, $creator['name'], $creator['type'], $creator['iri'], $language, $creator['history'], 'creator')) {
                $this->relate($draft, $unit, $creator['relation'], $agent);
            }
        }
        foreach ($draft->names as $name) {
            if ($agent = $this->agent($draft, $name['name'], $name['type'], $name['iri'], $language, null, 'name access point')) {
                $this->relate($draft, $unit, 'subject', $agent);
            }
        }
        foreach ($draft->subjects as $subject) {
            if ($concept = $this->subject($draft, $subject['name'], $subject['iri'], $language)) {
                $this->relate($draft, $unit, 'subject', $concept);
            }
        }
        foreach ($draft->places as $place) {
            if ($found = $this->place($draft, $place['name'], $place['iri'], $language)) {
                $this->relate($draft, $found, 'place', $unit);
            }
        }
        foreach ($draft->containers as $named) {
            if ($container = $this->container($draft, $named, $language)) {
                $this->relate($draft, $unit, 'storage', $container);
            }
        }
        foreach ($draft->genres as $genre) {
            $concept = $genre['iri'] !== null ? Concept::where(['iri' => $genre['iri'], 'institution_id' => $this->institution->id])->whereNull('deprecated_at')->first() : null;
            if ($concept ??= $this->term($draft, 'documentary-form-type', 'genre', $genre['name'], $language)) {
                $this->relate($draft, $unit, 'documentary-form', $concept);
            }
        }
        foreach ($draft->associated as $iri) {
            $other = $this->existing(RecordResource::where('iri', $iri)) ?? collect($this->byKey)->first(fn ($u) => $u->iri === $iri);
            if ($other === null) {
                // A link to a unit later in the same file is made when that unit is read.
                $this->report->issue($draft->location, 'related unit', $iri, 'no unit of description with this IRI is in the Workspace yet; not linked', 'left-out');
            } elseif ($other->id !== $unit->id) {
                $this->relate($draft, $unit, 'associated-record', $other);
            }
        }
    }

    private function relate(UnitDraft|EntityDraft $draft, Model $source, string $type, Model $target, array $fields = []): void
    {
        $exists = Relation::where(['source_table' => $source->getTable(), 'source_id' => $source->getKey(), 'target_table' => $target->getTable(), 'target_id' => $target->getKey()])
            ->whereHas('relationType', fn ($q) => $q->where('iri', Vocab::BASE.'relation-type/'.$type))->exists()
            || (config('workspace.relation_types.'.$type)['symmetric'] ?? false) && Relation::where(['source_table' => $target->getTable(), 'source_id' => $target->getKey(),
                'target_table' => $source->getTable(), 'target_id' => $source->getKey()])->whereHas('relationType', fn ($q) => $q->where('iri', Vocab::BASE.'relation-type/'.$type))->exists();
        if ($exists) {
            return;
        }
        try {
            $this->relations->create($source, $type, $target, $fields, null, ['origin' => 'import', 'label' => $this->import->source]);
            $this->report->counts['relations']++;
        } catch (InvalidArgumentException $e) {
            $this->report->issue($draft->location, $type, EntityKinds::label($target), $e->getMessage().'; not linked', 'left-out');
        }
    }

    /** An agent by the IRI the file gives, else the one agent of that authorised name, else a new one where allowed. */
    private function agent(UnitDraft|EntityDraft $draft, string $name, ?string $type, ?string $iri, string $language, ?string $history, string $field): ?Model
    {
        if ($iri !== null) {
            $found = Agent::where('iri', $iri)->where('institution_id', $this->institution->id)->whereNull('deleted_at')->first()
                ?? Repository::where('iri', $iri)->where('institution_id', $this->institution->id)->whereNull('deleted_at')->first();
            if ($found && EntityKinds::readable($this->user, $found)) {
                return $found->mergedInto ?? $found;
            }
        }
        $cacheKey = 'agent:'.mb_strtolower($name).':'.$type;
        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }
        $matches = Agent::where('institution_id', $this->institution->id)->whereNull('deleted_at')->whereNull('merged_into_id')
            ->whereIn('id', $this->namedQuery('agents', $name))
            ->when($type !== null, fn ($q) => $q->whereHas('agentType', fn ($q) => $q->where('iri', Agent::TYPES.$type)))->get()
            ->filter(fn (Agent $a) => EntityKinds::readable($this->user, $a));
        if ($matches->count() > 1) {
            $this->report->issue($draft->location, $field, $name, $matches->count().' authority records have this name; not linked', 'ambiguous');

            return $this->resolved[$cacheKey] = null;
        }
        if ($matches->count() === 1) {
            return $this->resolved[$cacheKey] = $matches->first();
        }
        $type ??= ($this->import->options['agent_type'] ?? '') ?: null;
        if ($type === null || ! $this->user->may('create', 'authority')) {
            $this->report->issue($draft->location, $field, $name, $type === null ? 'no authority record has this name, and the file does not say what kind of agent it is; not linked'
                : 'no authority record has this name, and you may not create one; not linked', 'left-out');

            return $this->resolved[$cacheKey] = null;
        }
        $agent = $this->agents->create($this->institution, $type, $name, $language, null, $this->user);
        if ($history !== null) {
            $this->agents->describe($agent, ['agent-history' => $history], $language, $this->user);
        }
        $this->report->counts['agents']++;
        $this->report->issue($draft->location, $field, $name, 'a new authority record ('.str_replace('-', ' ', $type).')', 'created');

        return $this->resolved[$cacheKey] = $agent;
    }

    private function subject(UnitDraft|EntityDraft $draft, string $label, ?string $iri, string $language): ?Concept
    {
        if ($iri !== null && ($found = Concept::where(['iri' => $iri, 'institution_id' => $this->institution->id])->whereNull('deprecated_at')->first())) {
            return $found;
        }

        return $this->term($draft, 'subject', 'subject', $label, $language);
    }

    /** The one concept of this label in the institution's vocabularies of a kind (subject, occupation-type), else a new one where allowed. */
    private function term(UnitDraft|EntityDraft $draft, string $kind, string $field, string $label, string $language): ?Concept
    {
        $cacheKey = $kind.':'.mb_strtolower($label);
        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }
        $what = ['occupation-type' => 'occupation', 'documentary-form-type' => 'documentary form type', 'container-type' => 'container type'][$kind] ?? $kind;
        $matches = Concept::where('institution_id', $this->institution->id)->whereNull('deprecated_at')
            ->whereHas('vocabulary.kind', fn ($q) => $q->where('iri', Vocab::BASE.'concept-scheme-kind/'.$kind))
            ->whereHas('labels', fn ($q) => $q->whereRaw('lower(pref_label) = ?', [mb_strtolower($label)]))->get();
        if ($matches->count() > 1) {
            $this->report->issue($draft->location, $field, $label, $matches->count()." {$what}s have this label; not linked", 'ambiguous');

            return $this->resolved[$cacheKey] = null;
        }
        if ($matches->count() === 1) {
            return $this->resolved[$cacheKey] = $matches->first();
        }
        if (! ($this->import->options['create_terms'] ?? false) || ! $this->user->may('create', 'vocabulary')) {
            $this->report->issue($draft->location, $field, $label, "no $what has this label; not linked", 'left-out');

            return $this->resolved[$cacheKey] = null;
        }
        $concept = $this->concepts->create($this->concepts->schemeOfKind($this->institution, $kind, $this->user), ['label' => $label, 'language' => $language], $this->user);
        $this->report->counts['concepts']++;
        $this->report->issue($draft->location, $field, $label, "a new $what", 'created');

        return $this->resolved[$cacheKey] = $concept;
    }

    /**
     * The container a unit is kept in, as the file names it: the one container of that name (and type), else a new one
     * where the account may make containers. Its type is found by label, or made in the institution's container-type
     * vocabulary where allowed. Where it is, as the file writes it, is kept as written: AtoM's location is free text.
     *
     * @param  array{name: string, type: ?string, location: ?string}  $named
     */
    private function container(UnitDraft $draft, array $named, string $language): ?Container
    {
        $cacheKey = 'container:'.mb_strtolower($named['name']).':'.mb_strtolower((string) $named['type']);
        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }
        $storage = app(StorageService::class);
        $type = null;
        if ($named['type'] !== null) {
            $wanted = mb_strtolower($named['type']);
            $type = $storage->types($this->institution->id)->first(fn (Concept $c) => mb_strtolower((string) $c->notation) === $wanted
                || $c->labels->contains(fn ($l) => mb_strtolower($l->pref_label) === $wanted))
                ?? $this->term($draft, 'container-type', 'physicalObjectType', $named['type'], $language);
        }
        $matches = Container::where('institution_id', $this->institution->id)->whereNull('deleted_at')->whereIn('id', $this->namedQuery('containers', $named['name']))
            ->when($type !== null, fn ($q) => $q->where('container_type_id', $type->id))->get();
        if ($matches->count() > 1) {
            $this->report->issue($draft->location, 'physicalObjectName', $named['name'], $matches->count().' containers have this name; not linked', 'ambiguous');

            return $this->resolved[$cacheKey] = null;
        }
        if ($matches->count() === 1) {
            return $this->resolved[$cacheKey] = $matches->first();
        }
        if (! $this->user->may('create', 'storage')) {
            $this->report->issue($draft->location, 'physicalObjectName', $named['name'], 'no container has this name, and you may not make one; not linked', 'left-out');

            return $this->resolved[$cacheKey] = null;
        }
        $container = $storage->create($this->institution, $named['name'], $language, $type?->id, null, $this->user);
        if ($named['location'] !== null) {
            $storage->describe($container, ['container-location-as-written' => $named['location']], $language, $this->user);
        }
        $this->report->counts['containers']++;
        $this->report->issue($draft->location, 'physicalObjectName', $named['name'], 'a new container'.($named['location'] !== null ? ', its location kept as written: '.$named['location'] : ''), 'created');

        return $this->resolved[$cacheKey] = $container;
    }

    private function place(UnitDraft|EntityDraft $draft, string $name, ?string $iri, string $language): ?Place
    {
        if ($iri !== null && ($found = Place::where(['iri' => $iri, 'institution_id' => $this->institution->id])->whereNull('deleted_at')->first())) {
            return $found;
        }
        $cacheKey = 'place:'.mb_strtolower($name);
        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }
        $matches = Place::where('institution_id', $this->institution->id)->whereNull('deleted_at')->whereIn('id', $this->namedQuery('places', $name))->get();
        if ($matches->count() > 1) {
            $this->report->issue($draft->location, 'place', $name, $matches->count().' places have this name; not linked', 'ambiguous');

            return $this->resolved[$cacheKey] = null;
        }
        if ($matches->count() === 1) {
            return $this->resolved[$cacheKey] = $matches->first();
        }
        if (! ($this->import->options['create_terms'] ?? false) || ! $this->user->may('create', 'vocabulary')) {
            $this->report->issue($draft->location, 'place', $name, 'no place has this name; not linked', 'left-out');

            return $this->resolved[$cacheKey] = null;
        }
        $place = $this->places->create($this->institution, $name, $language, null, $this->user);
        $this->report->counts['places']++;
        $this->report->issue($draft->location, 'place', $name, 'a new place', 'created');

        return $this->resolved[$cacheKey] = $place;
    }

    /** Ids of the entities in a table with this authorised name in any language, ignoring case. */
    private function namedQuery(string $table, string $name)
    {
        return Name::where('subject_table', $table)->whereRaw('lower(value) = ?', [mb_strtolower($name)])
            ->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'))->select('subject_id');
    }

    /**
     * After an import: every entry the report says was imported exists, has its authorised name or title and is
     * keyed to this source; the number of entries in the file is the number imported or matched plus the number not imported.
     */
    private function reconcile(): array
    {
        $table = self::TABLES[$this->import->format] ?? 'record_resources';
        $imported = collect($this->report->units)->where('action', '!=', 'rejected');
        $ids = $imported->pluck('unit_id')->filter()->unique()->values()->all();
        $present = DB::table($table)->whereIn('id', $ids)->whereNull('deleted_at')
            ->whereIn('id', Name::where('subject_table', $table)->whereHas('nameType', fn ($q) => $q->where('iri', Vocab::NAME_TYPES.'authorised'))->select('subject_id'))->count();
        $keyed = DB::table('import_keys')->where(['institution_id' => $this->institution->id, 'source' => $this->import->source, 'subject_table' => $table])
            ->whereIn('subject_id', $ids)->distinct()->count('subject_id');
        $actions = $this->report->actions();

        return [
            'in_file' => count($this->report->units), 'imported_or_matched' => $imported->count(), 'not_imported' => $actions['rejected'],
            'present_with_title' => $present, 'keyed_to_source' => $keyed,
            'holds' => $present === count($ids) && $keyed === count($ids) && $imported->count() + $actions['rejected'] === count($this->report->units),
        ];
    }

    private function seq(): ?int
    {
        return DB::table('audit_log')->max('seq');
    }

    private function reason(QueryException $e): string
    {
        return preg_match('/ERROR:\s+([^\n]+)/', $e->getMessage(), $m) ? $m[1] : 'a database rule';
    }
}
