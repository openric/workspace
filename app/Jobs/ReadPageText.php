<?php

// OpenRiC Workspace - the text of a scanned page, read by a vision model through the AI gateway and proposed as its
// instantiation's transcript (roadmap phase 17a), after Heratio's OCR (heratio#1522): the page is read in strips by
// the vision model, each checked against Tesseract's words, a strip that disagrees read again by the fallback model,
// one that still disagrees kept and flagged. Each strip's call is a receipt in the log of inferences; the proposal
// names the first, with the share of strips that passed as its confidence, and is reviewed in the queue (ADR 0007).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Jobs;

use App\Ai\Gateway;
use App\Ai\Ocr\Tesseract;
use App\Ai\Ocr\VisionReader;
use App\Description\InstantiationService;
use App\Models\AiInference;
use App\Models\BackgroundJob;
use App\Models\DigitalFile;
use App\Models\Instantiation;
use App\Models\Institution;
use App\Proposals\ProposalService;
use Illuminate\Support\Str;

class ReadPageText extends TrackedJob
{
    /** What proposes the text, as the proposal's origin label. */
    public const LABEL = 'ai-ocr';

    public function __construct(string $jobId, public string $instantiationId)
    {
        parent::__construct($jobId);
    }

    protected function run(BackgroundJob $job): array
    {
        $instantiation = Instantiation::with('record')->findOrFail($this->instantiationId);
        $institution = Institution::findOrFail($instantiation->institution_id);
        if ($reason = app(Gateway::class)->refusal($institution->id)) {
            return ['proposed' => false, 'reason' => $reason];
        }
        $file = self::image($instantiation);
        if ($file === null || $job->user === null) {
            return ['proposed' => false, 'reason' => 'no page image given by an account'];
        }
        $path = app(InstantiationService::class)->path($file);
        $language = (string) ($instantiation->record->originalLanguage('title') ?: 'en');
        $tesseract = app(Tesseract::class);
        $words = $tesseract->words($path, [$language, 'en']);
        $reading = (string) Str::uuid7();
        $result = app(VisionReader::class)->for($institution, $instantiation, $job->user, $reading)->read($path, $words['words']);
        $first = AiInference::where('details->reading', $reading)->where('succeeded', true)->orderBy('seq')->first();
        if (trim($result['text']) === '' || $first === null) {
            return ['proposed' => false, 'reason' => $result['error'] ?? 'the model read no text', 'strips' => count($result['strips'])];
        }
        $strips = collect($result['strips']);
        $flagged = $strips->where('flagged', true);
        $confidence = $strips->isEmpty() ? null : round(1 - $flagged->count() / $strips->count(), 5);
        $evidence = __('Read from :file by :model through the AI gateway, in :n strips checked against :tesseract.', ['file' => $file->original_name,
            'model' => $strips->pluck('model')->unique()->join(', '), 'n' => $strips->count(), 'tesseract' => $tesseract->version() ?: 'Tesseract'])
            .($flagged->isNotEmpty() ? ' '.__('Check these strips against the page, where the reading disagreed with Tesseract: :strips.', ['strips' => $flagged->map(fn ($s) => $s['y0'].'-'.$s['y1'].' px ('.implode(', ', $s['reasons']).')')->join('; ')]) : '');
        $proposal = app(ProposalService::class)->proposeTranscript($instantiation, $job->user, $result['text'], self::LABEL, $evidence,
            ['inference_id' => $first->id, 'confidence' => $confidence, 'language' => $language]);

        return ['proposed' => $proposal !== null, 'proposal' => $proposal?->id, 'characters' => mb_strlen($result['text']), 'strips' => $strips->count(), 'flagged' => $flagged->count()];
    }

    /** The page image to read: the reference copy, else a master that is an image. */
    public static function image(Instantiation $instantiation): ?DigitalFile
    {
        foreach (['reference', 'master'] as $use) {
            $file = $instantiation->file($use);
            if ($file !== null && str_starts_with((string) $file->mime_type, 'image/')) {
                return $file;
            }
        }

        return null;
    }
}
