<?php

// OpenRiC Workspace - a research project (roadmap phase 19f, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Models;

use App\Audit\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['institution_id', 'owner_id', 'title', 'description', 'project_type_id', 'affiliation', 'supervisor', 'funding_source', 'grant_number', 'ethics_approval', 'start_date', 'expected_end_date', 'actual_end_date', 'status_id', 'visibility_id', 'share_token_hash', 'metadata'])]
class ResearchProject extends Model
{
    use Audited, HasUuids;

    protected $table = 'research_projects';

    protected function casts(): array
    {
        return ['start_date' => 'date', 'expected_end_date' => 'date', 'actual_end_date' => 'date', 'metadata' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function projectType(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function visibility(): BelongsTo
    {
        return $this->belongsTo(Concept::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ResearchProjectMember::class, 'project_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(ResearchProjectResource::class, 'project_id')->orderBy('sort_order')->orderBy('added_at');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ResearchProjectMilestone::class, 'project_id')->orderBy('sort_order')->orderByRaw('due_date nulls last');
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(ResearchProjectTeamMember::class, 'project_id')->orderByDesc('is_lead')->orderBy('person_name');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(ResearchProjectOutput::class, 'project_id')->orderByRaw('output_date desc nulls last');
    }

    public function activity(): HasMany
    {
        return $this->hasMany(ResearchProjectActivity::class, 'project_id')->orderByDesc('created_at');
    }

    public function discussions(): HasMany
    {
        return $this->hasMany(ResearchDiscussion::class, 'project_id');
    }

    public function evidenceComments(): HasMany
    {
        return $this->hasMany(ResearchEvidenceComment::class, 'project_id')->orderBy('created_at');
    }

    public function clipboard(): HasMany
    {
        return $this->hasMany(ResearchProjectClipboardItem::class, 'project_id')->orderByDesc('is_pinned')->orderByDesc('created_at');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(ResearchProjectExport::class, 'project_id')->orderByDesc('exported_at');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(ResearchClaim::class, 'project_id')->orderByDesc('updated_at');
    }

    public function hypotheses(): HasMany
    {
        return $this->hasMany(ResearchHypothesis::class, 'project_id')->orderByDesc('created_at');
    }

    public function contradictions(): HasMany
    {
        return $this->hasMany(ResearchContradiction::class, 'project_id')->orderByDesc('created_at');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ResearchDecision::class, 'project_id')->orderByRaw('coalesce(decided_at, created_at) desc');
    }

    public function analysisResults(): HasMany
    {
        return $this->hasMany(ResearchAnalysisResult::class, 'project_id')->orderByDesc('created_at');
    }

    public function analysisCodes(): HasMany
    {
        return $this->hasMany(ResearchAnalysisCode::class, 'project_id')->orderBy('label');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ResearchSnapshot::class, 'project_id')->orderByDesc('created_at');
    }

    public function timelineEvents(): HasMany
    {
        return $this->hasMany(ResearchTimelineEvent::class, 'project_id')->orderBy('date_start')->orderBy('position');
    }

    public function mapPoints(): HasMany
    {
        return $this->hasMany(ResearchMapPoint::class, 'project_id')->orderBy('label');
    }

    public function fieldWatches(): HasMany
    {
        return $this->hasMany(ResearchFieldWatch::class, 'project_id')->orderBy('doi');
    }

    public function fieldAlerts(): HasMany
    {
        return $this->hasMany(ResearchFieldAlert::class, 'project_id')->orderByDesc('detected_at');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ResearchReport::class, 'project_id')->orderByDesc('updated_at');
    }

    public function writingDocs(): HasMany
    {
        return $this->hasMany(ResearchWritingDoc::class, 'project_id')->orderByDesc('updated_at');
    }

    public function reviewComments(): HasMany
    {
        return $this->hasMany(ResearchReviewComment::class, 'project_id')->orderBy('created_at');
    }

    public function publicationSubmissions(): HasMany
    {
        return $this->hasMany(ResearchPublicationSubmission::class, 'project_id')->orderByDesc('updated_at');
    }

    public function methodProtocols(): HasMany
    {
        return $this->hasMany(ResearchMethodProtocol::class, 'project_id')->orderByDesc('updated_at');
    }

    public function sourceTriage(): HasMany
    {
        return $this->hasMany(ResearchSourceTriage::class, 'project_id');
    }

    public function questionBrief(): HasOne
    {
        return $this->hasOne(ResearchQuestionBrief::class, 'project_id');
    }

    public function argument(): HasOne
    {
        return $this->hasOne(ResearchArgument::class, 'project_id');
    }
}
