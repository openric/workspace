<?php

// OpenRiC Workspace - accessions (Heratio's ahg-accession-manage AccessionService and CaaisProfileService, AtoM's
// accession; roadmap phase 9a, ADR 0010).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// An accession keeps its number (its local identifier, unique in the institution), its texts in each language
// (accession elements), its acquisition and material dates, and its type, status and priority. Its donors, the
// creators of its material and the descriptions it resulted in are relations (RelationService); the CAAIS rows,
// confidentiality and deaccessions are kept here. A description is made from it as Heratio's createInformationObject
// does, with the accession's title, number and scope and content, and the accession resulting in it.

namespace App\Description;

use App\Audit\AuditLog;
use App\Models\Accession;
use App\Models\AccessionEvent;
use App\Models\AccessionExtent;
use App\Models\AccessionLanguage;
use App\Models\AccessionPreservationRequirement;
use App\Models\AccessionSourceConfidentiality;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Deaccession;
use App\Models\Identifier;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\Relation;
use App\Models\Repository;
use App\Models\User;
use App\Relations\RelationService;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AccessionService
{
    /** The columns of an accession and the vocabulary each takes its concept from. */
    public const TERMS = ['acquisition_type' => 'acquisition-type', 'resource_type' => 'accession-resource-type',
        'processing_status' => 'processing-status', 'processing_priority' => 'processing-priority'];

    /** The repeatable CAAIS rows: model, and each concept field's vocabulary. */
    private const ROWS = [
        'events' => [AccessionEvent::class, ['event_type' => 'accession-event-type']],
        'extents' => [AccessionExtent::class, ['extent_type' => 'caais-extent-type', 'unit' => 'caais-extent-unit', 'content_type' => 'caais-content-type', 'carrier_type' => 'caais-carrier-type']],
        'languages' => [AccessionLanguage::class, []],
        'preservation' => [AccessionPreservationRequirement::class, ['requirement_type' => 'caais-preservation-type']],
    ];

    public function __construct(private DescriptionService $descriptions, private IdentifierService $identifiers, private RelationService $relations) {}

    /**
     * A new accession with its number (unique in the institution; with none, the next of the institution's numbering) and,
     * optionally, a title. Its intake starts in draft, with the institution's default checklist.
     */
    public function create(Institution $institution, ?string $number, ?string $title, string $language, ?User $actor = null): Accession
    {
        $intake = app(AccessionIntakeService::class);

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($institution, $number, $title, $language, $actor, $intake) {
            $number = trim((string) $number) === '' ? $this->nextNumber($institution) : $this->freeNumber($institution->id, (string) $number);
            $id = (string) Str::uuid7();
            $accession = new Accession(['institution_id' => $institution->id, 'iri' => $institution->base_iri.'/'.Accession::IRI_SEGMENT.'/'.$id, ...$intake->defaults($institution, $actor)]);
            $accession->id = $id;
            $accession->save();
            $this->identifiers->setLocal($accession, $number);
            $intake->start($accession, $actor);
            if (trim((string) $title) !== '') {
                $this->describe($accession, ['accession-title' => $title], $language);
            }

            return $accession;
        }));
    }

    /** @param  array<string, ?string>  $values  accession element notation => text */
    public function describe(Accession $accession, array $values, string $language, ?User $actor = null): void
    {
        $this->descriptions->describe($accession, Elements::ACCESSION, $values, $language, null, $actor);
    }

    /**
     * The accession number, the repository (CAAIS 1.1) and the type, status and priority. Fields: number,
     * repository (an id), acquisition_type, resource_type, processing_status, processing_priority (notations).
     */
    public function update(Accession $accession, array $fields, ?User $actor = null): void
    {
        $number = $this->freeNumber($accession->institution_id, (string) ($fields['number'] ?? ''), $accession);
        $repository = ($fields['repository'] ?? '') === '' ? null
            : (Repository::where('institution_id', $accession->institution_id)->whereNull('deleted_at')->find($fields['repository'])
                ?? throw new InvalidArgumentException(__('the repository is one of the institution\'s')));
        $columns = ['repository_id' => $repository?->id];
        foreach (self::TERMS as $field => $vocabulary) {
            $columns[$field.'_id'] = $this->term($vocabulary, $fields[$field] ?? null)?->id;
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($accession, $number, $columns) {
            $accession->update($columns);
            $this->identifiers->setLocal($accession, $number);
        }));
    }

    /** Add a CAAIS row: events (event_type, date, agent, note), extents, languages (language, note) or preservation. */
    public function addRow(Accession $accession, string $kind, array $fields, ?User $actor = null): Model
    {
        [$class, $terms] = self::ROWS[$kind] ?? throw new InvalidArgumentException("$kind is not a kind of accession row");
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $values = [];
        foreach ($terms as $field => $vocabulary) {
            $values[$field.'_id'] = $this->term($vocabulary, $fields[$field] ?? null)?->id;
        }
        $values += match ($kind) {
            'events' => ['date' => $this->day($text('date')), 'agent' => $text('agent'), 'note' => $text('note')],
            'extents' => ['quantity' => $this->quantity($text('quantity')), 'is_estimate' => (bool) ($fields['is_estimate'] ?? false),
                'digital_file_formats' => $text('digital_file_formats'), 'note' => $text('note')],
            'languages' => ['language' => $this->language($text('language')), 'note' => $text('note')],
            'preservation' => ['requirement' => $text('requirement') ?? throw new InvalidArgumentException(__('give the requirement')), 'note' => $text('note')],
        };
        if (array_key_exists('event_type_id', $values) && $values['event_type_id'] === null) {
            throw new InvalidArgumentException(__('choose the event type'));
        }
        if (array_key_exists('extent_type_id', $values) && $values['extent_type_id'] === null) {
            throw new InvalidArgumentException(__('choose the extent type'));
        }
        if (array_key_exists('requirement_type_id', $values) && $values['requirement_type_id'] === null) {
            throw new InvalidArgumentException(__('choose the requirement type'));
        }
        if ($kind === 'languages' && $values['language'] === null && $values['note'] === null) {
            throw new InvalidArgumentException(__('give a language or a statement'));
        }

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $class::create($values + ['institution_id' => $accession->institution_id,
            'accession_id' => $accession->id, 'position' => $class::where('accession_id', $accession->id)->max('position') + 1])));
    }

    public function removeRow(Accession $accession, string $kind, string $id, ?User $actor = null): void
    {
        [$class] = self::ROWS[$kind] ?? throw new InvalidArgumentException("$kind is not a kind of accession row");
        $row = $class::where('accession_id', $accession->id)->findOrFail($id);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $row->delete()));
    }

    /** CAAIS 2.1.6: how a donor of the accession is to be handled, or none (may be shared). */
    public function setConfidentiality(Accession $accession, Agent $donor, ?string $notation, ?User $actor = null): void
    {
        $this->donors($accession)->contains(fn (Agent $a) => $a->id === $donor->id) || throw new InvalidArgumentException(__('link the donor first'));
        $confidentiality = $this->term('caais-source-confidentiality', $notation);
        $current = AccessionSourceConfidentiality::where(['accession_id' => $accession->id, 'agent_id' => $donor->id])->first();
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => match (true) {
            $confidentiality === null => $current?->delete(),
            $current !== null => $current->update(['confidentiality_id' => $confidentiality->id]),
            default => AccessionSourceConfidentiality::create(['institution_id' => $accession->institution_id, 'accession_id' => $accession->id,
                'agent_id' => $donor->id, 'confidentiality_id' => $confidentiality->id]),
        }));
    }

    /** AtoM's deaccession. Fields: identifier, scope (whole or part), date, description, extent, reason, language. */
    public function deaccession(Accession $accession, array $fields, ?User $actor = null): Deaccession
    {
        $text = fn (string $key) => ($v = trim((string) ($fields[$key] ?? ''))) === '' ? null : $v;
        $identifier = $text('identifier') ?? throw new InvalidArgumentException(__('give the deaccession an identifier'));
        $scope = $this->term('deaccession-scope', $fields['scope'] ?? null) ?? throw new InvalidArgumentException(__('choose whether the whole accession or part of it goes'));
        $language = (string) ($fields['language'] ?? 'en');
        Bcp47::valid($language) || throw new InvalidArgumentException(__('give the language as a BCP 47 tag, such as en or af'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($accession, $identifier, $scope, $text, $language, $actor) {
            $deaccession = Deaccession::create(['institution_id' => $accession->institution_id,
                'accession_id' => $accession->id, 'identifier' => $identifier, 'scope_id' => $scope->id, 'date' => $this->day($text('date')),
                'description' => $text('description'), 'extent' => $text('extent'), 'reason' => $text('reason'), 'language' => $language,
                'position' => Deaccession::where('accession_id', $accession->id)->count()]);
            app(AccessionIntakeService::class)->record($accession, 'deaccessioned', $actor, __('Deaccessioned: :identifier', ['identifier' => $identifier]));

            return $deaccession;
        }));
    }

    public function removeDeaccession(Accession $accession, string $id, ?User $actor = null): void
    {
        $deaccession = Deaccession::where('accession_id', $accession->id)->findOrFail($id);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $deaccession->delete()));
    }

    /**
     * A description made from the accession, as Heratio's createInformationObject: its title (or else its number),
     * its number as the reference code and its scope and content, held by the accession's repository or the one
     * given, with the accession resulting in it.
     */
    public function createDescription(Accession $accession, string $levelIri, ?Repository $repository, string $language, User $actor): RecordResource
    {
        $repository ??= $accession->repository ?? throw new InvalidArgumentException(__('choose the repository that holds it'));

        return DB::transaction(function () use ($accession, $levelIri, $repository, $language, $actor) {
            $records = app(RecordService::class);
            $title = $accession->described('accession-title', [$language, 'en']) ?? (string) $accession->number();
            $record = $records->create(Institution::findOrFail($accession->institution_id), $levelIri, $title, $language, null, $repository, $accession->number(), $actor);
            if ($scope = $accession->described('accession-scope-and-content', [$language, 'en'])) {
                $records->describe($record, ['scope-and-content' => $scope], $language, $actor);
            }
            $this->relations->create($accession, 'result', $record, [], $actor);

            return $record;
        });
    }

    /** The donors of the accession: the agents taking part in it in the donor role. */
    public function donors(Accession $accession): Collection
    {
        $ids = Relation::where(['source_table' => 'accessions', 'source_id' => $accession->id, 'target_table' => 'agents'])
            ->whereHas('relationType', fn ($q) => $q->where('iri', Vocab::BASE.'relation-type/participation'))
            ->whereHas('role', fn ($q) => $q->where('iri', 'https://openric.org/vocab/role-type/donor'))->pluck('target_id');

        return Agent::whereIn('id', $ids)->whereNull('deleted_at')->with('names.nameType', 'descriptionValues.element')->get();
    }

    /**
     * CAAIS 7.2: when the accession record was created and revised, and by whom, from the audit log.
     *
     * @return list<array{type: string, date: string, agent: ?string}>
     */
    public function revisions(Accession $accession): array
    {
        return DB::table('audit_log as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->join('concepts as c', 'c.id', '=', 'a.action_id')
            ->where('a.subject_table', 'accessions')->where('a.subject_id', $accession->id)->orderBy('a.occurred_at')
            ->get(['c.notation', 'a.occurred_at', 'u.name'])
            ->map(fn ($row) => ['type' => $row->notation === 'create' ? 'created' : 'revised', 'date' => (string) $row->occurred_at, 'agent' => $row->name])->values()->all();
    }

    /**
     * One accession as a CAAIS 1.0 record, in Heratio's JSON serialisation (heratio-caais-json/1): keys follow the
     * element names, grouped by section. With $external, sources carrying a 2.1.6 confidentiality are withheld.
     */
    public function caais(Accession $accession, bool $external = false): array
    {
        $accession->loadMissing('identifiers.identifierType.labels', 'repository.names.nameType', 'acquisitionType.labels', 'processingStatus.labels', 'dates.property',
            'events.eventType.labels', 'extents.extentType.labels', 'extents.unit.labels', 'extents.contentType.labels', 'extents.carrierType.labels', 'languages',
            'preservationRequirements.requirementType.labels', 'sourceConfidentiality.confidentiality.labels', 'descriptionValues.element');
        $en = ['en', 'und'];
        $text = fn (string $element) => ($v = $accession->described($element, $en)) === null ? [] : [$v];
        $label = fn (?Concept $c) => $c?->label($en);
        $join = fn (array $parts, string $glue = '; ') => ($s = implode($glue, array_filter($parts, fn ($p) => $p !== null && $p !== ''))) === '' ? null : $s;

        $sources = [];
        $donors = $this->donors($accession);
        foreach ($donors as $donor) {
            $confidentiality = $accession->sourceConfidentiality->firstWhere('agent_id', $donor->id)?->confidentiality;
            if ($external && $confidentiality) {
                continue;
            }
            $contact = $donor->contactPoints()->orderByDesc('is_primary')->first();
            $sources[] = ['source_type' => null, 'source_name' => $donor->described('agent-authorised-name', $en),
                'source_contact_information' => $contact ? $join([$contact->contact_person, $contact->street_address, $contact->city, $contact->region,
                    $contact->postal_code, $contact->country_code, $contact->telephone, $contact->email]) : null,
                'source_role' => 'Donor', 'source_note' => null, 'source_confidentiality' => $label($confidentiality)];
        }
        $immediate = $accession->described('accession-source-of-acquisition', $en);
        if ($donors->isEmpty() && $immediate !== null) {
            $sources[] = ['source_type' => null, 'source_name' => $immediate, 'source_contact_information' => null,
                'source_role' => 'Immediate source of acquisition', 'source_note' => null, 'source_confidentiality' => null];
        }
        $materialDates = $accession->dates->filter(fn ($d) => $d->property->notation !== 'acquisition')->map(fn ($d) => $d->display())->filter()->values()->all();
        $extents = $accession->extents->map(fn ($e) => ['extent_type' => $label($e->extentType),
            'quantity_and_unit_of_measure' => $join([$e->is_estimate ? 'ca.' : null, $e->quantity === null ? null : rtrim(rtrim((string) $e->quantity, '0'), '.'), $label($e->unit)], ' '),
            'content_type' => $label($e->contentType), 'carrier_type' => $label($e->carrierType), 'digital_file_formats' => $e->digital_file_formats, 'extent_note' => $e->note])->all();
        if ($extents === [] && ($received = $accession->described('accession-received-extent-units', $en)) !== null) {
            $extents[] = ['extent_type' => 'Extent received', 'quantity_and_unit_of_measure' => null, 'content_type' => null, 'carrier_type' => null,
                'digital_file_formats' => null, 'extent_note' => $received];
        }
        $preservation = $accession->preservationRequirements->map(fn ($r) => ['preservation_requirement_type' => $label($r->requirementType),
            'preservation_requirement_value' => $r->requirement, 'preservation_requirement_note' => $r->note])->all();
        if ($preservation === [] && ($condition = $accession->described('accession-physical-characteristics', $en)) !== null) {
            $preservation[] = ['preservation_requirement_type' => 'Physical condition', 'preservation_requirement_value' => $condition, 'preservation_requirement_note' => null];
        }
        $events = $accession->events->map(fn ($e) => ['event_type' => $label($e->eventType), 'event_date' => $e->date?->format('Y-m-d'), 'event_agent' => $e->agent, 'event_note' => $e->note])->all();
        foreach ($accession->dates->filter(fn ($d) => $d->property->notation === 'acquisition') as $date) {
            // The acquisition date is not the date of the material (3.1), so it travels as an event, as Heratio sends it.
            $events[] = ['event_type' => 'Accession date', 'event_date' => $date->display(), 'event_agent' => null, 'event_note' => null];
        }
        $revisions = $this->revisions($accession);
        $missing = array_values(array_filter([
            $accession->number() === null ? '1.2 - '.__('Identifiers') : null,
            $sources === [] && $donors->isEmpty() && $immediate === null ? '2.1 - '.__('Source of material') : null,
            $materialDates === [] ? '3.1 - '.__('Date of material') : null,
            $extents === [] ? '3.2 - '.__('Extent statement') : null,
            ! $accession->events->contains(fn ($e) => $e->eventType?->notation === 'physical-transfer') ? '5.1 - '.__('Event: physical transfer') : null,
        ]));

        return [
            'identity' => [
                'repository' => $accession->repository?->described('authorised-name', $en),
                'identifiers' => $accession->identifiers->map(fn ($i) => ['type' => $i->identifierType->notation === 'local' ? 'Accession number' : $label($i->identifierType),
                    'value' => $i->value, 'note' => null])->values()->all(),
                'accession_title' => $accession->described('accession-title', $en),
                'archival_unit' => [], 'acquisition_method' => $label($accession->acquisitionType), 'disposition_authority' => [], 'status' => $label($accession->processingStatus),
            ],
            'source' => ['source_of_material' => $sources, 'preliminary_custodial_history' => $text('accession-archival-history')],
            'materials' => ['date_of_material' => $materialDates === [] ? null : implode('; ', $materialDates), 'extent_statement' => $extents,
                'preliminary_scope_and_content' => $text('accession-scope-and-content'),
                'language_of_material' => $accession->languages->map(fn ($l) => $join([$l->language ? IsoCodes::names('language', $l->language) : null, $l->note], ' - '))->filter()->values()->all()],
            'management' => ['storage_location' => $text('accession-location-information'), 'rights' => [], 'preservation_requirements' => $preservation,
                'appraisal' => array_map(fn ($v) => ['appraisal_type' => null, 'appraisal_value' => $v, 'appraisal_note' => null], $text('accession-appraisal')), 'associated_documentation' => []],
            'events' => $events,
            'general' => ['general_note' => $text('accession-processing-notes')],
            'control' => ['rules_or_conventions' => $accession->described('accession-rules-or-conventions', $en),
                'date_of_creation_or_revision' => array_map(fn ($r) => ['creation_or_revision_type' => ucfirst($r['type']), 'creation_or_revision_date' => $r['date'],
                    'creation_or_revision_agent' => $r['agent'], 'creation_or_revision_note' => null], $revisions),
                'language_of_accession_record' => $accession->descriptionValues->first()?->language],
            'conformance' => ['missing_mandatory' => $missing],
        ];
    }

    /** Records in Heratio's CAAIS export envelope. */
    public function caaisEnvelope(array $records): array
    {
        return ['standard' => 'Canadian Archival Accession Information Standard (CAAIS)', 'standard_version' => '1.0', 'standard_publisher' => 'Canadian Council of Archives',
            'serialisation' => 'heratio-caais-json/1', 'generated_at' => now()->toIso8601String(), 'records' => $records];
    }

    /** The next number of the institution's accession numbering (Heratio's nextAccessionNumber), or of a repository's. */
    public function nextNumber(Institution $institution, ?Repository $repository = null): string
    {
        return app(NumberingService::class)->next($institution, 'accession', $repository, null, function (string $number) use ($institution) {
            try {
                return $this->freeNumber($institution->id, $number) !== '';
            } catch (InvalidArgumentException) {
                return false;
            }
        });
    }

    private function freeNumber(string $institutionId, string $number, ?Accession $except = null): string
    {
        $number = trim($number);
        $number !== '' || throw new InvalidArgumentException(__('an accession has a number'));
        $taken = Identifier::where(['institution_id' => $institutionId, 'subject_table' => 'accessions', 'value' => $number])
            ->whereHas('identifierType', fn ($q) => $q->where('iri', Vocab::IDENTIFIER_TYPES.'local'))
            ->when($except, fn ($q) => $q->where('subject_id', '<>', $except->id))
            ->whereIn('subject_id', Accession::whereNull('deleted_at')->select('id'))->exists();
        ! $taken || throw new InvalidArgumentException(__('another accession has the number :number', ['number' => $number]));

        return $number;
    }

    private function term(string $vocabulary, ?string $notation): ?Concept
    {
        if ($notation === null || $notation === '') {
            return null;
        }

        return Concept::choices(Vocab::BASE.$vocabulary.'/')->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }

    private function day(?string $day): ?string
    {
        if ($day === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return $date && $date->format('Y-m-d') === $day ? $day : throw new InvalidArgumentException(__('give the date as YYYY-MM-DD'));
    }

    private function quantity(?string $quantity): ?string
    {
        return $quantity === null ? null : (is_numeric($quantity) && $quantity >= 0 ? $quantity : throw new InvalidArgumentException(__('give the quantity as a number')));
    }

    private function language(?string $code): ?string
    {
        return $code === null ? null : (IsoCodes::valid('language', $code) ? $code : throw new InvalidArgumentException(__(':code is not an ISO :standard code', ['code' => $code, 'standard' => '639'])));
    }
}
