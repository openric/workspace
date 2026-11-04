<?php

// OpenRiC Workspace - preservation planning (roadmap phase 15c, ADR 0022), after Heratio's ahg-preservation pages: the
// normalisation rules and migration pathways, set by the installation's operators as file formats are; the institution's
// conversions and its migration plans; and the replication targets the object store is copied to, which are the
// installation's too. Who may manage the institution sees all of it.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Jobs\NormaliseFile;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\FormatConversion;
use App\Models\Instantiation;
use App\Models\MigrationPathway;
use App\Models\MigrationPlan;
use App\Models\NormalisationRule;
use App\Models\ReplicationRun;
use App\Models\ReplicationTarget;
use App\Models\Repository;
use App\Operations\Health;
use App\Preservation\Conversions;
use App\Preservation\MigrationPlans;
use App\Preservation\Replication;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class PreservationController extends Controller
{
    private const TOOLS = ['imagemagick', 'ffmpeg', 'ghostscript', 'libreoffice'];

    public function index(Request $request): View
    {
        $institution = $request->user()->institution_id;
        $conversions = FormatConversion::where('institution_id', $institution);

        return view('preservation.index', ['rules' => NormalisationRule::with('purpose.labels', 'tool.labels')->orderBy('source_mime')->orderBy('source_puid')->orderBy('priority')->get(),
            'pathways' => MigrationPathway::with('tool.labels', 'qualityImpact.labels')->orderBy('source_puid')->orderBy('priority')->get(),
            'plans' => MigrationPlan::where('institution_id', $institution)->with('status.labels', 'pathway')->orderByDesc('created_at')->limit(50)->get(),
            'conversions' => (clone $conversions)->with('status.labels', 'tool.labels', 'purpose.labels', 'source', 'output')->orderByDesc('created_at')->limit(50)->get(),
            'failed' => (clone $conversions)->whereHas('status', fn ($q) => $q->where('notation', 'failed'))->count(),
            'targets' => ReplicationTarget::with('type.labels', 'frequency.labels', 'lastStatus.labels')->orderBy('name')->get(),
            'runs' => ReplicationRun::with('target', 'operation.labels', 'status.labels')->orderByDesc('started_at')->limit(20)->get(),
            'installed' => collect(self::TOOLS)->mapWithKeys(fn ($t) => [$t => Conversions::installed($t)]),
            'normaliseOnIngest' => (bool) $request->user()->institution->normalise_on_ingest,
            'without' => $this->mastersWithout($institution)->count(),
            'choices' => $this->choices(), 'operator' => Health::operator($request->user()),
            'repositories' => Repository::where('institution_id', $institution)->get()->sortBy(fn ($r) => EntityKinds::label($r))]);
    }

    public function saveRule(Request $request, ?NormalisationRule $rule = null): RedirectResponse
    {
        abort_unless(Health::operator($request->user()), 403);
        $data = $request->validate(['source_puid' => ['nullable', 'regex:#^(x-)?fmt/\d+$#'], 'source_mime' => ['nullable', 'string', 'max:255', 'regex:#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i'],
            'purpose' => ['required', 'in:preservation,access'], 'target_format' => ['required', 'string', 'max:64'], 'target_ext' => ['required', 'regex:/^[a-z0-9]{1,8}$/'],
            'target_mime' => ['required', 'string', 'max:255'], 'tool' => ['required', 'in:'.implode(',', self::TOOLS)], 'priority' => ['nullable', 'integer', 'between:0,10000'],
            'options' => ['nullable', 'json'], 'is_active' => ['nullable', 'boolean']]);
        if (! ($data['source_puid'] ?? null) && ! ($data['source_mime'] ?? null)) {
            return back()->withErrors(['preservation' => __('Give the PRONOM format or the MIME type the rule is for.')]);
        }
        $fields = ['source_puid' => $data['source_puid'] ?? null, 'source_mime' => $data['source_mime'] ?? null, 'purpose_id' => $this->concept('normalisation-purpose', $data['purpose']),
            'target_format' => $data['target_format'], 'target_ext' => $data['target_ext'], 'target_mime' => $data['target_mime'], 'tool_id' => $this->concept('conversion-tool', $data['tool']),
            'priority' => (int) ($data['priority'] ?? 100), 'options' => ($data['options'] ?? null) ? json_decode($data['options'], true) : null, 'is_active' => $request->boolean('is_active')];
        $rule ? $rule->update($fields) : NormalisationRule::create($fields);

        return back()->with('status', __('Saved.'));
    }

    public function deleteRule(Request $request, NormalisationRule $rule): RedirectResponse
    {
        abort_unless(Health::operator($request->user()), 403);
        $rule->delete();

        return back()->with('status', __('The rule was removed; the conversions it made are kept.'));
    }

    public function savePathway(Request $request, ?MigrationPathway $pathway = null): RedirectResponse
    {
        abort_unless(Health::operator($request->user()), 403);
        $data = $request->validate(['source_puid' => ['required', 'regex:#^(x-)?fmt/\d+$#'], 'target_puid' => ['required', 'regex:#^(x-)?fmt/\d+$#', 'different:source_puid'],
            'tool' => ['required', 'in:'.implode(',', self::TOOLS)], 'target_ext' => ['required', 'regex:/^[a-z0-9]{1,8}$/'], 'target_mime' => ['required', 'string', 'max:255'],
            'quality_impact' => ['required', 'string'], 'fidelity_score' => ['nullable', 'integer', 'between:0,100'], 'priority' => ['nullable', 'integer', 'between:0,10000'],
            'options' => ['nullable', 'json'], 'notes' => ['nullable', 'string', 'max:4000']]);
        $fields = ['source_puid' => $data['source_puid'], 'target_puid' => $data['target_puid'], 'tool_id' => $this->concept('conversion-tool', $data['tool']),
            'target_ext' => $data['target_ext'], 'target_mime' => $data['target_mime'], 'quality_impact_id' => $this->concept('migration-quality-impact', $data['quality_impact']),
            'fidelity_score' => $data['fidelity_score'] ?? null, 'is_recommended' => $request->boolean('is_recommended'), 'is_automated' => $request->boolean('is_automated'),
            'priority' => (int) ($data['priority'] ?? 100), 'options' => ($data['options'] ?? null) ? json_decode($data['options'], true) : null, 'notes' => trim((string) ($data['notes'] ?? '')) ?: null];
        $pathway ? $pathway->update($fields) : MigrationPathway::create($fields);

        return back()->with('status', __('Saved.'));
    }

    /** The institution's choice to normalise each master as it is kept (Heratio normalises at ingest). */
    public function saveSettings(Request $request): RedirectResponse
    {
        $request->user()->institution->update(['normalise_on_ingest' => $request->boolean('normalise_on_ingest')]);

        return back()->with('status', __('Saved.'));
    }

    /** Normalise, in the background, the institution's masters that have neither a preservation master nor an access copy (Heratio's NormalizeExistingCommand). */
    public function normaliseAll(Request $request): RedirectResponse
    {
        $ids = $this->mastersWithout($request->user()->institution_id)->pluck('id');
        $ids->each(fn ($id) => NormaliseFile::dispatch($id, $request->user()->id));

        return back()->with('status', trans_choice(':count master is being normalised.|:count masters are being normalised.', $ids->count(), ['count' => $ids->count()]));
    }

    /** Normalise one instantiation's master now (Heratio's retryNormalization, for a failed conversion or a new rule). */
    public function normalise(Request $request, Instantiation $instantiation): RedirectResponse
    {
        abort_unless($instantiation->institution_id === $request->user()->institution_id, 404);
        $master = $instantiation->file('master') ?? abort(404);
        NormaliseFile::dispatch($master->id, $request->user()->id);

        return back()->with('status', __('The master is being normalised by the rules.'));
    }

    public function plan(Request $request, MigrationPlan $plan): View
    {
        abort_unless($plan->institution_id === $request->user()->institution_id, 404);

        return view('preservation.plan', ['plan' => $plan->load('status.labels', 'pathway.tool.labels', 'pathway.qualityImpact.labels', 'repository', 'record', 'createdBy', 'approvedBy'),
            'counts' => app(MigrationPlans::class)->counts($plan), 'inScope' => app(MigrationPlans::class)->files($plan)->count(),
            'items' => $plan->files()->with('status.labels', 'file.instantiation', 'conversion')->orderBy('queued_at')->limit(500)->get(),
            'pathways' => MigrationPathway::orderBy('source_puid')->get(), 'repositories' => Repository::where('institution_id', $plan->institution_id)->get()]);
    }

    public function savePlan(Request $request, ?MigrationPlan $plan = null): RedirectResponse
    {
        abort_unless($plan === null || $plan->institution_id === $request->user()->institution_id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:4000'], 'pathway_id' => ['required', 'uuid'],
            'repository_id' => ['nullable', 'uuid'], 'record_resource_id' => ['nullable', 'uuid'], 'scheduled_start' => ['nullable', 'date'], 'run_fixity_after' => ['nullable', 'boolean']]);
        try {
            $plan = app(MigrationPlans::class)->save($request->user()->institution, [...$data, 'run_fixity_after' => $request->boolean('run_fixity_after')], $request->user(), $plan);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['preservation' => $e->getMessage()]);
        }

        return redirect()->route('preservation.plans.show', $plan)->with('status', __('Saved.'));
    }

    /** Approve, run, cancel or try again a plan's failed files. */
    public function planAction(Request $request, MigrationPlan $plan, string $action): RedirectResponse
    {
        abort_unless($plan->institution_id === $request->user()->institution_id, 404);
        $plans = app(MigrationPlans::class);
        try {
            match ($action) {
                'approve' => $plans->approve($plan, $request->user()),
                'start' => $plans->start($plan, $request->user()),
                'cancel' => $plans->cancel($plan, $request->user()),
                'retry' => $plans->retryFailed($plan, $request->user()),
            };
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['preservation' => $e->getMessage()]);
        }

        return back()->with('status', ['approve' => __('The plan is approved.'), 'start' => __('The plan is running.'), 'cancel' => __('The plan is cancelled.'),
            'retry' => __('Its failed files are being tried again.')][$action]);
    }

    public function saveTarget(Request $request, ?ReplicationTarget $target = null): RedirectResponse
    {
        abort_unless(Health::operator($request->user()), 403);
        try {
            app(Replication::class)->save([...$request->only('name', 'description', 'type', 'path', 'host', 'username', 'port', 'ssh_key_path', 'frequency'),
                'is_active' => $request->boolean('is_active')], $target);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['preservation' => $e->getMessage()]);
        }

        return back()->with('status', __('Saved.'));
    }

    /** Copy the object store to the target, or compare the copy with it, now. */
    public function runTarget(Request $request, ReplicationTarget $target, string $operation): RedirectResponse
    {
        abort_unless(Health::operator($request->user()), 403);
        $run = app(Replication::class)->run($target, $operation, $request->user());
        $lang = Vocab::languages();

        return back()->with('status', __(':operation: :status, :files files.', ['operation' => $run->operation->label($lang), 'status' => $run->status->label($lang), 'files' => $run->files_synced])
            .($run->error_message ? ' '.$run->error_message : ''));
    }

    /** Masters with neither a preservation master nor an access copy. */
    private function mastersWithout(string $institution)
    {
        return DigitalFile::where('institution_id', $institution)->whereHas('use', fn ($q) => $q->where('notation', 'master'))
            ->whereHas('instantiation', fn ($q) => $q->whereNull('deleted_at')->whereDoesntHave('files', fn ($f) => $f->whereHas('use', fn ($u) => $u->whereIn('notation', ['preservation', 'access']))));
    }

    private function choices(): array
    {
        return collect(['normalisation-purpose', 'conversion-tool', 'migration-quality-impact', 'replication-target-type', 'fixity-frequency'])
            ->mapWithKeys(fn ($v) => [$v => Concept::choices(Vocab::BASE.$v.'/')->get()])->all();
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->where('notation', $notation)->value('id') ?? abort(422, __(':value is not in the list', ['value' => $notation]));
    }
}
