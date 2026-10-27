<?php

// OpenRiC Workspace - the text layer of an uploaded PDF, proposed as its instantiation's transcript (roadmap phase 5).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The text is a proposal, reviewed in the queue like a transcript sent through the API
// (ADR 0007), proposed by the account that gave the file. A scan without a text layer gives
// nothing here; OCR is AI work, in phase 17.

namespace App\Jobs;

use App\Description\InstantiationService;
use App\Files\PdfText;
use App\Models\BackgroundJob;
use App\Models\Instantiation;
use App\Proposals\ProposalService;

class ExtractFileText extends TrackedJob
{
    /** What proposes the text, as the proposal's origin label. */
    public const LABEL = 'pdf-text-layer';

    public function __construct(string $jobId, public string $instantiationId)
    {
        parent::__construct($jobId);
    }

    protected function run(BackgroundJob $job): array
    {
        $instantiation = Instantiation::findOrFail($this->instantiationId);
        $master = $instantiation->file('master');
        if ($master === null || $master->mime_type !== 'application/pdf' || $job->user === null) {
            return ['proposed' => false, 'reason' => 'not a PDF given by an account'];
        }
        $pdf = app(PdfText::class);
        $text = $pdf->extractText(app(InstantiationService::class)->path($master));
        if ($text === null) {
            return ['proposed' => false, 'reason' => 'the PDF has no text layer'];
        }
        $proposal = app(ProposalService::class)->proposeTranscript($instantiation, $job->user, $text, self::LABEL,
            'The text layer of '.$master->original_name.', read with '.trim('pdftotext '.$pdf->getPdftotextVersion()).'. Check it against the pages: the layer may come from an earlier OCR.');

        return ['proposed' => $proposal !== null, 'proposal' => $proposal?->id, 'characters' => mb_strlen($text)];
    }
}
