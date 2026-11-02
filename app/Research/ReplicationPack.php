<?php

// OpenRiC Workspace - a research project's replication pack, an RO-Crate (roadmap phase 19h, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ReplicationPackService, roCrate and reproducibilityPack, made one: a ZIP that is an RO-Crate 1.1
// (ro-crate-metadata.json at its root describing every file in it), holding what another researcher needs to follow
// the work: the method protocols, analysis results with their provenance and the claims they bear on, the decision
// log (JSON and CSV), the claims with their evidence, references to data and code (where they are, not the files:
// they may hold personal information, as Heratio withholds them), the project export and its sources. What the
// project has none of is named as omitted. Each build is logged with its SHA-256, so a reader can check the pack they
// were given is the one built.

namespace App\Research;

use App\Models\ResearchProject;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReplicationPack
{
    public function __construct(private Projects $projects, private ProjectExport $export) {}

    /** The pack's files [name => contents], what it includes, and what it omits. */
    public function assemble(User $user, ResearchProject $project): array
    {
        $this->projects->must($user, $project, 'viewer');
        $json = fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $files = $included = $omitted = [];
        $part = function (string $name, string $what, $rows, callable $map, ?callable $csv = null) use (&$files, &$included, &$omitted, $json) {
            if ($rows->isEmpty()) {
                $omitted[] = __(':what - none recorded.', ['what' => $what]);

                return;
            }
            $files["$name.json"] = $json($rows->map($map)->values()->all());
            if ($csv) {
                $files["$name.csv"] = $csv($rows);
            }
            $included[$name] = ['what' => $what, 'count' => $rows->count()];
        };
        $label = fn ($c) => $c?->label([app()->getLocale(), 'en']);

        $part('method-protocols', __('Method protocols'), $project->methodProtocols()->with('template', 'status')->get(),
            fn ($p) => ['title' => $p->title, 'template' => $p->template->name ?? null, 'status' => $label($p->status), 'fields' => $p->fields, 'updated' => $p->updated_at?->toIso8601String()]);
        $part('analysis-results', __('Analysis results, their provenance and linked claims'), $project->analysisResults()->with('resultType', 'claimLinks')->get(),
            fn ($r) => ['title' => $r->title, 'type' => $label($r->resultType), 'source_data' => $r->source_data_ref, 'source_data_version' => $r->source_data_version, 'method' => $r->method,
                'code' => $r->code_ref, 'generated_at' => $r->generated_at?->toIso8601String(), 'researcher_decision' => $r->researcher_decision, 'claims' => $r->claimLinks->pluck('claim_id')->all()]);
        $part('decision-log', __('Decision log'), $project->decisions()->with('decisionType')->orderBy('decided_at')->get(),
            fn ($d) => ['decided_at' => $d->decided_at?->toIso8601String(), 'type' => $label($d->decisionType), 'summary' => $d->summary, 'reason' => $d->reason, 'related' => $d->related_ref, 'decided_by' => $d->decided_by],
            fn ($rows) => $this->csv(['decided_at', 'type', 'summary', 'reason', 'related', 'decided_by'], $rows->map(fn ($d) => [$d->decided_at?->toIso8601String(), $label($d->decisionType), $d->summary, $d->reason, $d->related_ref, $d->decided_by])));
        $part('claims-and-evidence', __('Claims and their evidence'), $project->claims()->with('claimType', 'status', 'confidenceLevel', 'evidence')->get(),
            fn ($c) => ['id' => $c->id, 'type' => $label($c->claimType), 'subject' => $c->subject_label, 'predicate' => $c->predicate, 'object' => $c->object_label ?? $c->object_value, 'status' => $label($c->status),
                'confidence' => $label($c->confidenceLevel), 'evidence' => $c->evidence->map(fn ($e) => ['source' => $e->source_id, 'selector' => $e->selector, 'note' => $e->note])->all()]);
        $refs = $project->analysisResults()->get()->flatMap(fn ($r) => array_filter(['data' => $r->source_data_ref, 'code' => $r->code_ref]) ? [array_filter(['result' => $r->title, 'data' => $r->source_data_ref, 'code' => $r->code_ref])] : [])
            ->concat($project->analysisCodes()->with('kind')->get()->map(fn ($c) => ['code' => $c->label, 'kind' => $label($c->kind)]));
        $part('data-code-references', __('Where the data and code are (the files themselves are not in the pack)'), $refs, fn ($r) => $r);

        $bundle = $this->export->assemble($user, $project);
        $files['project.json'] = $json(array_diff_key($bundle, ['manifest' => true]));
        $files['sources.bib'] = $this->export->sources($user, $project, 'bibtex');
        $included['project'] = ['what' => __('The project, its milestones, team and outputs, and its sources'), 'count' => 1];
        $files['README.md'] = '# '.$project->title."\n\n".__('Replication pack, built from the OpenRiC Workspace by :name on :date.', ['name' => $user->name, 'date' => now(config('workspace.timezone'))->format('j F Y H:i')])
            ."\n\n".collect($included)->map(fn ($i, $name) => "- $name: {$i['what']}")->implode("\n")
            .($omitted ? "\n\n".__('Not included:')."\n\n".collect($omitted)->map(fn ($o) => "- $o")->implode("\n") : '')."\n";
        $files = ['ro-crate-metadata.json' => $json($this->crate($project, $files))] + $files;

        return ['files' => $files, 'included' => $included, 'omitted' => $omitted];
    }

    /** Build the pack, log it, and give its body. */
    public function build(User $user, ResearchProject $project): array
    {
        $pack = $this->assemble($user, $project);
        $path = tempnam(sys_get_temp_dir(), 'replication');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($pack['files'] as $name => $body) {
            $zip->addFromString($name, $body);
            $zip->setMtimeName($name, $project->updated_at->getTimestamp());
        }
        $zip->close();
        $body = (string) file_get_contents($path);
        @unlink($path);
        DB::transaction(function () use ($user, $project, $body, $pack) {
            $project->replicationBuilds()->create(['built_by_id' => $user->id, 'built_at' => now(), 'sha256' => hash('sha256', $body), 'size_bytes' => strlen($body),
                'included' => array_map(fn ($i) => $i['count'], $pack['included'])]);
            $this->projects->log($project, $user, 'replication', null, ['sha256' => hash('sha256', $body)]);
        });

        return [$body, 'replication-pack-'.str($project->title)->slug()->limit(60, '').'.zip'];
    }

    /** The RO-Crate 1.1 metadata: the root dataset, its authors with their ORCID iDs, and each file. */
    private function crate(ResearchProject $project, array $files): array
    {
        $project->loadMissing('teamMembers', 'dois.state');
        $people = $project->teamMembers->map(fn ($t) => array_filter(['@id' => $t->orcid ? 'https://orcid.org/'.$t->orcid : '#'.$t->id, '@type' => 'Person', 'name' => $t->person_name,
            'affiliation' => $t->affiliation]))->values();
        $doi = $project->dois->first(fn ($d) => ! $d->output_id && ! $d->test);
        $types = ['json' => 'application/json', 'csv' => 'text/csv', 'md' => 'text/markdown', 'bib' => 'application/x-bibtex'];
        $parts = collect(array_keys($files))->map(fn ($name) => ['@id' => $name, '@type' => 'File', 'name' => $name, 'encodingFormat' => $types[pathinfo($name, PATHINFO_EXTENSION)] ?? 'application/octet-stream',
            'contentSize' => (string) strlen($files[$name])]);

        return ['@context' => 'https://w3id.org/ro/crate/1.1/context', '@graph' => array_merge([
            ['@id' => 'ro-crate-metadata.json', '@type' => 'CreativeWork', 'conformsTo' => ['@id' => 'https://w3id.org/ro/crate/1.1'], 'about' => ['@id' => './']],
            array_filter(['@id' => './', '@type' => 'Dataset', 'name' => $project->title, 'description' => (string) $project->description ?: $project->title,
                'datePublished' => now()->toDateString(), 'identifier' => $doi ? 'https://doi.org/'.$doi->doi : null,
                'author' => $people->map(fn ($p) => ['@id' => $p['@id']])->all() ?: null, 'hasPart' => $parts->map(fn ($p) => ['@id' => $p['@id']])->all()]),
        ], $people->all(), $parts->all())];
    }

    private function csv(array $head, $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $head, escape: '');
        foreach ($rows as $row) {
            fputcsv($out, $row, escape: '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
