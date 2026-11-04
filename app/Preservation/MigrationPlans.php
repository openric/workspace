<?php

// OpenRiC Workspace - migration plans (roadmap phase 15c, ADR 0022), after Heratio's ahg-preservation migration plans: the
// institution's files of one format, or those of a repository or a unit and what it includes, carried into another
// format by a migration pathway. A plan is drawn up, approved by someone other than who drew it up, and run in the
// background, at once or from its scheduled start; each file's migration is a conversion and a PREMIS migration event,
// its result a preservation master beside the master, checked for fixity after where the plan says so. A plan can be
// cancelled while it runs, and its failed files tried again.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Preservation;

use App\Audit\AuditLog;
use App\Fixity\Fixity;
use App\Jobs\RunMigrationPlan;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\Institution;
use App\Models\MigrationPathway;
use App\Models\MigrationPlan;
use App\Models\MigrationPlanFile;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class MigrationPlans
{
    public function __construct(private Conversions $conversions) {}

    /** The files a plan covers: masters and preservation masters of its source format, in its scope, not deleted. */
    public function files(MigrationPlan $plan): Builder
    {
        return DigitalFile::where('institution_id', $plan->institution_id)->whereHas('format', fn ($q) => $q->where('puid', $plan->source_puid))
            ->whereHas('use', fn ($q) => $q->whereIn('notation', ['master', 'preservation']))
            ->whereHas('instantiation', fn ($q) => $q->whereNull('deleted_at')->whereHas('record', function ($r) use ($plan) {
                $r->whereNull('deleted_at');
                if ($plan->record_resource_id) {
                    $r->whereIn('id', [$plan->record_resource_id, ...$plan->record->descendantIds()]);
                } elseif ($plan->repository_id) {
                    $r->whereIn('id', RecordResource::where('repository_id', $plan->repository_id)->get()->flatMap(fn ($top) => [$top->id, ...$top->descendantIds()])->unique()->all());
                }
            }))->orderBy('id');
    }

    public function save(Institution $institution, array $data, User $actor, ?MigrationPlan $plan = null): MigrationPlan
    {
        $this->assertMay($actor);
        ($plan === null || $plan->status->notation === 'draft') || throw new InvalidArgumentException(__('Only a draft plan can be changed.'));
        $name = trim((string) ($data['name'] ?? ''));
        $name !== '' || throw new InvalidArgumentException(__('Give the plan a name.'));
        $pathway = MigrationPathway::find($data['pathway_id'] ?? null) ?? throw new InvalidArgumentException(__('Choose a migration pathway.'));
        $repository = ($data['repository_id'] ?? null) ? DB::table('repositories')->where(['id' => $data['repository_id'], 'institution_id' => $institution->id])->value('id') : null;
        $record = ($data['record_resource_id'] ?? null) ? RecordResource::where(['id' => $data['record_resource_id'], 'institution_id' => $institution->id])->value('id') : null;
        $start = trim((string) ($data['scheduled_start'] ?? '')) ?: null;
        $fields = ['name' => $name, 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'pathway_id' => $pathway->id, 'source_puid' => $pathway->source_puid,
            'target_puid' => $pathway->target_puid, 'repository_id' => $record ? null : $repository, 'record_resource_id' => $record,
            'run_fixity_after' => (bool) ($data['run_fixity_after'] ?? true), 'scheduled_start' => $start ? now()->parse($start) : null];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $plan
            ? tap($plan)->update($fields)
            : MigrationPlan::create([...$fields, 'institution_id' => $institution->id, 'status_id' => $this->status('migration-plan-status', 'draft'), 'created_by_id' => $actor->id])));
    }

    /** Approved by someone other than who drew it up, as Heratio's approved_by. */
    public function approve(MigrationPlan $plan, User $actor): MigrationPlan
    {
        $this->assertMay($actor);
        $plan->status->notation === 'draft' || throw new InvalidArgumentException(__('Only a draft plan can be approved.'));
        $plan->created_by_id !== $actor->id || throw new InvalidArgumentException(__('A plan is approved by someone other than who drew it up.'));
        AuditLog::actingAs($actor, fn () => $plan->update(['status_id' => $this->status('migration-plan-status', 'approved'), 'approved_by_id' => $actor->id, 'approved_at' => now()]));

        return $plan->fresh();
    }

    /** Its files queued and its run begun in the background. */
    public function start(MigrationPlan $plan, ?User $actor = null): MigrationPlan
    {
        $actor && $this->assertMay($actor);
        $plan->status->notation === 'approved' || throw new InvalidArgumentException(__('Only an approved plan can be run.'));
        DB::transaction(function () use ($plan, $actor) {
            $pending = $this->status('conversion-status', 'pending');
            foreach ($this->files($plan)->pluck('id') as $id) {
                MigrationPlanFile::firstOrCreate(['plan_id' => $plan->id, 'digital_file_id' => $id], ['status_id' => $pending, 'queued_at' => now()]);
            }
            AuditLog::actingAs($actor, fn () => $plan->update(['status_id' => $this->status('migration-plan-status', 'in-progress'), 'started_at' => now()]));
        });
        RunMigrationPlan::dispatch($plan->id, $actor?->id)->afterCommit();

        return $plan->fresh();
    }

    /** The plan's queued files, one at a time, until none is left or the plan is cancelled. */
    public function process(MigrationPlan $plan, ?User $actor = null): void
    {
        $pending = $this->status('conversion-status', 'pending');
        while (($item = MigrationPlanFile::where(['plan_id' => $plan->id, 'status_id' => $pending])->orderBy('queued_at')->orderBy('id')->first()) !== null) {
            if ($plan->fresh()->status->notation !== 'in-progress') {
                return;
            }
            $item->update(['status_id' => $this->status('conversion-status', 'processing'), 'started_at' => now()]);
            $file = DigitalFile::find($item->digital_file_id);
            if ($file === null) {
                $item->update(['status_id' => $this->status('conversion-status', 'skipped'), 'completed_at' => now(), 'error_message' => __('The file is gone.')]);

                continue;
            }
            try {
                $conversion = $this->conversions->migrate($file, $plan->pathway, $plan, $actor);
                $done = $conversion->status->notation === 'completed';
                $item->update(['status_id' => $this->status('conversion-status', $done ? 'completed' : 'failed'), 'conversion_id' => $conversion->id,
                    'completed_at' => now(), 'error_message' => $done ? null : $conversion->error_message]);
                if ($done && $plan->run_fixity_after && ($made = DigitalFile::find($conversion->output_file_id))) {
                    app(Fixity::class)->check($made, null, $actor);
                }
            } catch (Throwable $e) {
                $item->update(['status_id' => $this->status('conversion-status', 'failed'), 'completed_at' => now(), 'error_message' => mb_substr($e->getMessage(), 0, 2000)]);
            }
        }
        $failed = MigrationPlanFile::where(['plan_id' => $plan->id, 'status_id' => $this->status('conversion-status', 'failed')])->exists();
        AuditLog::actingAs($actor, fn () => $plan->update(['status_id' => $this->status('migration-plan-status', $failed ? 'failed' : 'completed'), 'completed_at' => now()]));
    }

    public function cancel(MigrationPlan $plan, User $actor): MigrationPlan
    {
        $this->assertMay($actor);
        ! in_array($plan->status->notation, ['completed', 'cancelled'], true) || throw new InvalidArgumentException(__('The plan has ended already.'));
        AuditLog::actingAs($actor, fn () => $plan->update(['status_id' => $this->status('migration-plan-status', 'cancelled'), 'completed_at' => now()]));

        return $plan->fresh();
    }

    /** Its failed files queued again, each with its count of tries. */
    public function retryFailed(MigrationPlan $plan, User $actor): MigrationPlan
    {
        $this->assertMay($actor);
        $plan->status->notation === 'failed' || throw new InvalidArgumentException(__('Only a plan with failed files can try them again.'));
        DB::transaction(function () use ($plan, $actor) {
            MigrationPlanFile::where(['plan_id' => $plan->id, 'status_id' => $this->status('conversion-status', 'failed')])
                ->update(['status_id' => $this->status('conversion-status', 'pending'), 'queued_at' => now(), 'retry_count' => DB::raw('retry_count + 1'), 'error_message' => null]);
            AuditLog::actingAs($actor, fn () => $plan->update(['status_id' => $this->status('migration-plan-status', 'in-progress'), 'completed_at' => null]));
        });
        RunMigrationPlan::dispatch($plan->id, $actor->id)->afterCommit();

        return $plan->fresh();
    }

    /** Start the approved plans whose scheduled start has come. */
    public function runDue(): int
    {
        $due = MigrationPlan::whereHas('status', fn ($q) => $q->where('notation', 'approved'))->whereNotNull('scheduled_start')->where('scheduled_start', '<=', now())->get();
        $due->each(fn ($plan) => $this->start($plan));

        return $due->count();
    }

    /** @return array<string, int> its files by status, and their sizes before and after */
    public function counts(MigrationPlan $plan): array
    {
        $rows = MigrationPlanFile::where('plan_id', $plan->id)->join('concepts', 'concepts.id', '=', 'migration_plan_files.status_id')
            ->leftJoin('format_conversions', 'format_conversions.id', '=', 'migration_plan_files.conversion_id')
            ->selectRaw('concepts.notation, count(*) as n, coalesce(sum(format_conversions.source_size), 0) as before, coalesce(sum(format_conversions.output_size), 0) as after')
            ->groupBy('concepts.notation')->get()->keyBy('notation');

        return ['total' => (int) $rows->sum('n'), ...collect(['pending', 'processing', 'completed', 'failed', 'skipped'])->mapWithKeys(fn ($s) => [$s => (int) ($rows[$s]->n ?? 0)])->all(),
            'original_bytes' => (int) $rows->sum('before'), 'converted_bytes' => (int) $rows->sum('after')];
    }

    private function assertMay(User $actor): void
    {
        $actor->hasPermission(Vocab::permission('manage-institution')) || throw new InvalidArgumentException(__('Only who administers the institution plans migrations.'));
    }

    private function status(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE."$vocabulary/$notation")->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
