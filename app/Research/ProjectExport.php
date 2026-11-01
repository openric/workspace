<?php

// OpenRiC Workspace - a research project exported (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ProjectExportService and ProjectExportController: the project as Markdown or JSON, its sources as
// BibTeX, RIS or CSL-JSON, or everything as a ZIP file (README.md, manifest.json, project.md, project.json,
// sources.bib, sources.ris, sources.json), each export logged. The sources are the records of the project (its
// record resources, its clipboard and the items of its collections), cited as the exporter may read them, and the
// entries of its bibliographies. As Heratio's, the bundle carries the research brief, claims, decision log, argument,
// method protocols and the exporter's research memory of the project; sections() lets later parts add theirs.

namespace App\Research;

use App\Exchange\ExportView;
use App\Models\Bibliography;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\ResearchCollection;
use App\Models\ResearchMemoryItem;
use App\Models\ResearchProject;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProjectExport
{
    public const FORMATS = ['zip' => 'ZIP bundle', 'markdown' => 'Markdown', 'json' => 'JSON', 'bibtex' => 'BibTeX', 'ris' => 'RIS', 'csl' => 'CSL-JSON'];

    /** @var array<string, callable(ResearchProject, User): ?array> sections later parts of phase 19 add: name => what it holds */
    private static array $sections = [];

    public function __construct(private Projects $projects, private Citations $citations, private Bibliographies $bibliographies) {}

    public static function section(string $name, callable $assemble): void
    {
        self::$sections[$name] = $assemble;
    }

    /** Heratio's assemble: everything the project holds, as the exporter may read it. */
    public function assemble(User $user, ResearchProject $project): array
    {
        $this->projects->must($user, $project, 'viewer');
        $project->load('owner', 'projectType', 'status', 'visibility', 'members.user', 'members.role', 'members.status', 'resources.resourceType', 'resources.linkType',
            'milestones.milestoneType', 'milestones.status', 'teamMembers.role', 'teamMembers.status', 'outputs.outputType', 'outputs.status', 'outputs.identifierType');
        $lang = Vocab::languages();
        $label = fn (?Concept $c) => $c?->label($lang);
        $bundle = [
            'project' => ['id' => $project->id, 'title' => $project->title, 'description' => $project->description, 'type' => $label($project->projectType), 'status' => $label($project->status),
                'visibility' => $label($project->visibility), 'owner' => $project->owner->name, 'affiliation' => $project->affiliation, 'supervisor' => $project->supervisor,
                'funding_source' => $project->funding_source, 'grant_number' => $project->grant_number, 'ethics_approval' => $project->ethics_approval,
                'start_date' => $project->start_date?->toDateString(), 'expected_end_date' => $project->expected_end_date?->toDateString(), 'actual_end_date' => $project->actual_end_date?->toDateString()],
            'collaborators' => $project->members->filter(fn ($m) => $m->status->notation === 'accepted')->map(fn ($m) => ['name' => $m->user->name, 'role' => $label($m->role)])->values()->all(),
            'team' => $project->teamMembers->map(fn ($m) => array_filter(['name' => $m->person_name, 'role' => $label($m->role), 'affiliation' => $m->affiliation, 'orcid' => $m->orcid,
                'lead' => $m->is_lead, 'contribution' => $m->contribution_note, 'status' => $label($m->status), 'from' => $m->start_date?->toDateString(), 'to' => $m->end_date?->toDateString()],
                fn ($v) => $v !== null && $v !== false))->values()->all(),
            'milestones' => $project->milestones->map(fn ($m) => array_filter(['title' => $m->title, 'type' => $label($m->milestoneType), 'status' => $label($m->status), 'due' => $m->due_date?->toDateString(),
                'completed' => $m->completed_date?->toDateString(), 'progress' => $m->progress_pct, 'deliverable' => $m->deliverable, 'description' => $m->description], fn ($v) => $v !== null))->values()->all(),
            'outputs' => $project->outputs->map(fn ($o) => array_filter(['title' => $o->title, 'type' => $label($o->outputType), 'status' => $label($o->status), 'authors' => $o->authors, 'venue' => $o->venue,
                'identifier' => $o->identifier ? trim($label($o->identifierType).' '.$o->identifier) : null, 'url' => Projects::outputUrl($o), 'date' => $o->output_date?->toDateString(), 'notes' => $o->notes],
                fn ($v) => $v !== null && $v !== ''))->values()->all(),
            'resources' => $project->resources->map(fn ($r) => array_filter(['kind' => $label($r->resourceType), 'title' => $r->title, 'url' => $r->external_url, 'link_type' => $label($r->linkType),
                'description' => $r->description, 'notes' => $r->notes, 'tags' => $r->tags], fn ($v) => $v !== null))->values()->all(),
        ];
        // Heratio's bundle: the question brief, claims, decision log, argument, method protocols and research memory.
        $brief = $project->questionBrief?->versions()->first();
        $bundle['questionBrief'] = $brief ? array_filter($brief->only(Studios::BRIEF) + ['version' => $brief->version_no]) : null;
        $bundle['claims'] = $project->claims()->with('status', 'claimType')->withCount('evidence')->get()->map(fn ($c) => array_filter(['claim' => $c->object_value, 'type' => $label($c->claimType),
            'status' => $label($c->status), 'confidence' => $c->confidence, 'evidence' => $c->evidence_count, 'subject' => $c->subject_label, 'predicate' => $c->predicate, 'object' => $c->object_label],
            fn ($v) => $v !== null && $v !== ''))->values()->all();
        $bundle['decisionLog'] = $project->decisions()->with('decisionType')->get()->map(fn ($d) => array_filter(['decision' => $d->summary, 'type' => $label($d->decisionType), 'reason' => $d->reason,
            'by' => $d->decided_by, 'on' => $d->decided_at?->toDateString()]))->values()->all();
        $bundle['argument'] = $project->argument ? ['title' => $project->argument->title, 'thesis' => $project->argument->central_thesis,
            'steps' => $project->argument->steps()->with('slot', 'claim')->get()->map(fn ($s) => array_filter(['step' => $label($s->slot), 'claim' => $s->claim?->object_value, 'note' => $s->note]))->values()->all()] : null;
        $bundle['methodProtocols'] = $project->methodProtocols()->with('template', 'status')->get()->map(fn ($m) => ['title' => $m->title, 'method' => $m->template->name, 'status' => $label($m->status),
            'fields' => collect($m->fields)->mapWithKeys(fn ($v, $k) => [Studios::AREAS[$k] ?? $k => $v])->all()])->values()->all();
        $bundle['researchMemory'] = ResearchMemoryItem::where(['project_id' => $project->id, 'user_id' => $user->id])->with('kind', 'status')->get()
            ->map(fn ($m) => array_filter(['title' => $m->title, 'kind' => $label($m->kind), 'status' => $label($m->status), 'body' => $m->body]))->values()->all();
        $bundle = array_filter($bundle, fn ($v) => $v !== null);
        foreach (self::$sections as $name => $assemble) {
            if (($section = $assemble($project, $user)) !== null) {
                $bundle[$name] = $section;
            }
        }
        $bundle['sources'] = array_map(fn (RecordResource $r) => ['title' => EntityKinds::label($r, $lang), 'reference' => $r->referenceCode(), 'iri' => $r->iri], $this->records($user, $project));
        $bundle['manifest'] = ['generator' => 'OpenRiC Workspace', 'exported_at' => now()->toIso8601String(), 'exported_by' => $user->name, 'project' => $project->id,
            'sections' => array_keys(array_diff_key($bundle, ['manifest' => true]))];

        return $bundle;
    }

    /** The records of the project the exporter may read: its record resources, its clipboard and the items of its collections. @return list<RecordResource> */
    public function records(User $user, ResearchProject $project): array
    {
        $ids = $project->resources()->whereNotNull('record_id')->pluck('record_id')
            ->merge($project->clipboard()->pluck('record_id'))
            ->merge(ResearchCollection::where('project_id', $project->id)->with('items')->get()->flatMap(fn ($c) => $c->items->where('subject_table', 'record_resources')->pluck('subject_id')))
            ->merge($project->resources()->whereHas('resourceType', fn ($t) => $t->where('iri', Projects::RESOURCES.'collection'))->pluck('resource_id')
                ->flatMap(fn ($id) => ResearchCollection::find($id)?->items()->where('subject_table', 'record_resources')->pluck('subject_id') ?? []))
            ->unique()->values();

        return RecordResource::whereIn('id', $ids)->whereNull('deleted_at')->get()->filter(fn ($r) => EntityKinds::readable($user, $r))->sortBy(fn ($r) => $r->referenceCode())->values()->all();
    }

    /** The sources in a citation format: the records, then the entries of the project's bibliographies. */
    public function sources(User $user, ResearchProject $project, string $format): string
    {
        $records = $this->records($user, $project);
        $view = new ExportView($user, $user->mayAnywhere('view-draft', 'description'), null, Institution::find($project->institution_id));
        $parts = [];
        if ($records !== []) {
            $parts[] = $this->citations->many($view, $records, $format);
        }
        foreach (Bibliography::where('project_id', $project->id)->get() as $bibliography) {
            $parts[] = $this->bibliographies->export($bibliography, $format);
        }
        if ($format === 'csl') {
            return json_encode(collect($parts)->flatMap(fn ($p) => json_decode($p, true) ?: [])->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return implode($format === 'ris' ? "\r\n\r\n" : "\n\n", array_filter($parts));
    }

    public function markdown(array $bundle): string
    {
        $p = $bundle['project'];
        $out = ['# '.$p['title'], ''];
        foreach (['type' => 'Type', 'status' => 'Status', 'owner' => 'Owner', 'affiliation' => 'Institution', 'supervisor' => 'Supervisor', 'funding_source' => 'Funding source',
            'grant_number' => 'Grant number', 'ethics_approval' => 'Ethics approval', 'start_date' => 'Start date', 'expected_end_date' => 'Expected end date', 'actual_end_date' => 'Actual end date'] as $k => $l) {
            if ($p[$k] ?? null) {
                $out[] = '- **'.__($l).':** '.$p[$k];
            }
        }
        if ($p['description']) {
            array_push($out, '', $p['description']);
        }
        $list = function (string $title, array $items, callable $line) use (&$out) {
            if ($items !== []) {
                array_push($out, '', '## '.__($title), '', ...array_map(fn ($i) => '- '.$line($i), $items));
            }
        };
        $list('Collaborators', $bundle['collaborators'], fn ($c) => $c['name'].' ('.$c['role'].')');
        $list('Team', $bundle['team'], fn ($m) => $m['name'].(isset($m['role']) ? ', '.$m['role'] : '').(isset($m['affiliation']) ? ', '.$m['affiliation'] : '').(isset($m['orcid']) ? ', https://orcid.org/'.$m['orcid'] : ''));
        $list('Milestones', $bundle['milestones'], fn ($m) => $m['title'].' - '.$m['status'].(isset($m['due']) ? ', '.__('due :date', ['date' => $m['due']]) : '').' ('.$m['progress'].'%)');
        $list('Outputs', $bundle['outputs'], fn ($o) => $o['title'].(isset($o['venue']) ? ', '.$o['venue'] : '').(isset($o['date']) ? ' ('.$o['date'].')' : '').(isset($o['url']) ? ' '.$o['url'] : ''));
        $list('Resources', $bundle['resources'], fn ($r) => $r['kind'].': '.$r['title'].(isset($r['url']) ? ' '.$r['url'] : ''));
        if (isset($bundle['questionBrief'])) {
            array_push($out, '', '## '.__('Research brief'), '', ...array_map(fn ($k, $v) => '- **'.__(Studios::BRIEF_LABELS[$k] ?? 'Version').':** '.$v, array_keys($bundle['questionBrief']), $bundle['questionBrief']));
        }
        $list('Claims', $bundle['claims'] ?? [], fn ($c) => $c['claim'].' ('.$c['status'].', '.trans_choice(':count piece of evidence|:count pieces of evidence', $c['evidence'] ?? 0, ['count' => $c['evidence'] ?? 0]).')');
        if (isset($bundle['argument'])) {
            array_push($out, '', '## '.__('Argument'), '', $bundle['argument']['thesis'] ?? '', ...array_map(fn ($st) => '- **'.$st['step'].':** '.($st['claim'] ?? $st['note'] ?? ''), $bundle['argument']['steps']));
        }
        $list('Decision log', $bundle['decisionLog'] ?? [], fn ($d) => ($d['on'] ?? '').' '.$d['decision'].(isset($d['reason']) ? ': '.$d['reason'] : ''));
        foreach ($bundle['methodProtocols'] ?? [] as $m) {
            array_push($out, '', '## '.$m['title'].' ('.$m['method'].')', '', ...array_map(fn ($k, $v) => '- **'.$k.':** '.$v, array_keys($m['fields']), $m['fields']));
        }
        $list('Research memory', $bundle['researchMemory'] ?? [], fn ($m) => $m['kind'].': '.$m['title']);
        foreach (self::$sections as $name => $unused) {
            if (isset($bundle[$name]['markdown'])) {
                array_push($out, '', $bundle[$name]['markdown']);
            }
        }
        $list('Sources', $bundle['sources'], fn ($s) => trim(($s['reference'] ?? '').' '.$s['title']).' <'.$s['iri'].'>');

        return implode("\n", $out)."\n";
    }

    /** One format, as a download: [body, file name, mime type]. Each export is logged. @return array{0: string, 1: string, 2: string} */
    public function export(User $user, ResearchProject $project, string $format): array
    {
        isset(self::FORMATS[$format]) || throw new InvalidArgumentException(__('No such format.'));
        $bundle = $this->assemble($user, $project);
        $base = Str::slug($project->title) ?: 'project';
        $json = fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $result = match ($format) {
            'markdown' => [$this->markdown($bundle), "$base.md", 'text/markdown; charset=utf-8'],
            'json' => [$json(array_diff_key($bundle, ['manifest' => true])), "$base.json", 'application/json'],
            'bibtex' => [$this->sources($user, $project, 'bibtex'), "$base-sources.bib", 'application/x-bibtex; charset=utf-8'],
            'ris' => [$this->sources($user, $project, 'ris'), "$base-sources.ris", 'application/x-research-info-systems; charset=utf-8'],
            'csl' => [$this->sources($user, $project, 'csl'), "$base-sources.json", 'application/vnd.citationstyles.csl+json'],
            'zip' => [$this->zip($user, $project, $bundle, $base), "$base.zip", 'application/zip'],
        };
        $project->exports()->create(['format_id' => Concept::where('iri', Vocab::BASE.'project-export-format/'.$format)->value('id'), 'exported_by_id' => $user->id, 'exported_at' => now()]);
        $this->projects->log($project, $user, 'export', null, ['format' => $format]);

        return $result;
    }

    private function zip(User $user, ResearchProject $project, array $bundle, string $base): string
    {
        $path = tempnam(sys_get_temp_dir(), 'project-export');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $json = fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $zip->addFromString("$base/README.md", '# '.$project->title."\n\n".__('Exported from the OpenRiC Workspace by :name on :date.', ['name' => $user->name, 'date' => now(config('workspace.timezone'))->format('j F Y H:i')])
            ."\n\n- project.md, project.json: ".__('the project')."\n- sources.bib, sources.ris, sources.json: ".__('its sources')."\n- manifest.json: ".__('what the bundle holds')."\n");
        $zip->addFromString("$base/manifest.json", $json($bundle['manifest']));
        $zip->addFromString("$base/project.md", $this->markdown($bundle));
        $zip->addFromString("$base/project.json", $json(array_diff_key($bundle, ['manifest' => true])));
        $zip->addFromString("$base/sources.bib", $this->sources($user, $project, 'bibtex'));
        $zip->addFromString("$base/sources.ris", $this->sources($user, $project, 'ris'));
        $zip->addFromString("$base/sources.json", $this->sources($user, $project, 'csl'));
        $zip->close();
        $body = (string) file_get_contents($path);
        @unlink($path);

        return $body;
    }
}
