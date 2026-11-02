<?php

// OpenRiC Workspace - business e-mail captured as records (roadmap phase 13c, ADR 0015).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's EmailCaptureService. Decided by Dr Johan Pieterse on 2 November 2026: e-mail comes in as uploaded
// .eml and Outlook .msg files; no mailbox password is kept. The file is kept unaltered in the object store with its
// SHA-256; its sender, recipients, dates, subject, bodies and attachments are read from it; a message is captured once
// (by its message ID, or, with none, by its checksum). Classification rules for capture place it in the file plan,
// or it is placed by hand. Declared, it becomes a unit of description (an item, under a record set of choice, or at
// the top): titled by its subject, its scope and content the sender, recipients and an excerpt of the body, dated by
// when it was sent, the original file its instantiation, placed in the file plan with its disposal class, and
// declared a record. Changed: Heratio made the description at the root with the identifier EMAIL-n, and kept the
// .eml outside its object store.

namespace App\Retention;

use App\Audit\AuditLog;
use App\Description\DateService;
use App\Description\InstantiationService;
use App\Description\RecordService;
use App\Files\FileStore;
use App\Models\Concept;
use App\Models\EmailCapture;
use App\Models\FilePlanNode;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmailCaptures
{
    public function __construct(private MailParser $parser, private FileStore $store, private ClassificationRules $rules, private FilePlan $plan) {}

    public function capture(User $actor, UploadedFile $file): EmailCapture
    {
        $raw = (string) file_get_contents($file->getRealPath());
        $msg = str_starts_with($raw, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        $mail = $msg ? $this->parser->msg($raw) : $this->parser->eml($raw);
        $id = $mail['message_id'] !== '' ? mb_substr($mail['message_id'], 0, 255) : 'sha256:'.hash('sha256', $raw);
        if (EmailCapture::where(['institution_id' => $actor->institution_id, 'message_id' => $id])->exists()) {
            throw new InvalidArgumentException(__('That message is captured already.'));
        }
        $kept = $this->store->ingest($actor->institution_id, $file->getRealPath());

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $file, $mail, $id, $kept, $msg) {
            $capture = EmailCapture::create(['institution_id' => $actor->institution_id, 'message_id' => $id, 'from_address' => mb_substr($mail['from'] ?: '-', 0, 255),
                'to_addresses' => $mail['to'] ?: null, 'cc_addresses' => $mail['cc'] ?: null, 'subject' => $mail['subject'] !== '' ? mb_substr($mail['subject'], 0, 500) : null,
                'sent_at' => $mail['sent_at'], 'received_at' => $mail['received_at'], 'body_text' => $mail['body_text'], 'body_html' => $mail['body_html'],
                'attachment_count' => count($mail['attachments']), 'attachments' => $mail['attachments'] ?: null, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'storage_path' => $kept['storage_path'], 'sha256' => $kept['sha256'], 'size_bytes' => $kept['size_bytes'], 'source_id' => $this->concept('email-capture-source', $msg ? 'msg-upload' : 'eml-upload'),
                'status_id' => $this->concept('email-capture-status', 'captured'), 'captured_by_id' => $actor->id]);
            if ($match = $this->rules->match($actor->institution_id, $this->rules->metaForEmail($capture), 'capture')) {
                $capture->update(['file_plan_node_id' => $match['rule']->file_plan_node_id, 'disposal_class_id' => $match['rule']->disposal_class_id,
                    'status_id' => $this->concept('email-capture-status', 'classified')]);
                $this->rules->log($match, null, $capture);
            }

            return $capture;
        }));
    }

    public function classify(User $actor, EmailCapture $capture, string $nodeId, ?string $disposalClassId): void
    {
        $node = FilePlanNode::where('institution_id', $capture->institution_id)->findOrFail($nodeId);
        if ($capture->record_resource_id) {
            throw new InvalidArgumentException(__('It is declared already; place the record instead.'));
        }
        $class = $disposalClassId ? DB::table('disposal_classes as c')->join('retention_schedules as s', 's.id', '=', 'c.retention_schedule_id')
            ->where(['c.id' => $disposalClassId, 's.institution_id' => $capture->institution_id])->value('c.id') ?? throw new InvalidArgumentException(__('Choose a disposal class of this institution.')) : null;
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $capture->update(['file_plan_node_id' => $node->id, 'disposal_class_id' => $class ?? $node->disposal_class_id,
            'status_id' => $this->concept('email-capture-status', 'classified')])));
    }

    /** Make it a unit of description, with its original as its instantiation, placed and declared. */
    public function declare(User $actor, EmailCapture $capture, string $language, ?RecordResource $parent = null): RecordResource
    {
        if ($capture->record_resource_id) {
            throw new InvalidArgumentException(__('It is declared already.'));
        }
        if (! $capture->file_plan_node_id) {
            throw new InvalidArgumentException(__('Place it in the file plan first.'));
        }
        if ($parent && ($parent->institution_id !== $capture->institution_id || ! $actor->may('update', 'description', $parent))) {
            throw new InvalidArgumentException(__('Choose a record set you may add to.'));
        }

        return DB::transaction(function () use ($actor, $capture, $language, $parent) {
            $records = app(RecordService::class);
            $record = $records->create(Institution::findOrFail($capture->institution_id), Vocab::BASE.'level-of-description/item', $capture->subject ?: __('[No subject]'), $language, $parent, null, null, $actor);
            $records->describe($record, ['scope-and-content' => $this->summary($capture)], $language, $actor);
            if ($capture->sent_at) {
                app(DateService::class)->add($record, ['property' => 'creation', 'normalized' => $capture->sent_at->toDateString()], $actor);
            }
            app(InstantiationService::class)->upload($record, $this->store->path($capture->storage_path), $capture->original_name, $language, $capture->original_name, $capture->sha256, 'online', $actor);
            FilePlan::giveClass($actor, $record, $capture->disposalClass, $capture->sent_at?->toDateString());
            $this->plan->place($actor, $record, $capture->node);
            app(Declarations::class)->declareNow($actor, $record, __('Captured e-mail :id', ['id' => $capture->message_id]));
            AuditLog::actingAs($actor, fn () => $capture->update(['record_resource_id' => $record->id, 'status_id' => $this->concept('email-capture-status', 'declared')]));

            return $record;
        });
    }

    /** Heratio's summariseForScope: sender, recipients, when, and the first 1,500 characters of the body. */
    public function summary(EmailCapture $c): string
    {
        $body = trim((string) ($c->body_text ?? html_entity_decode(strip_tags((string) $c->body_html))));
        $lines = array_filter([__('From: :who', ['who' => $c->from_address]), $c->to_addresses ? __('To: :who', ['who' => $c->to_addresses]) : null,
            $c->cc_addresses ? __('Cc: :who', ['who' => $c->cc_addresses]) : null, $c->sent_at ? __('Sent: :when', ['when' => $c->sent_at->toDateTimeString()]) : null,
            $c->attachment_count ? __('Attachments: :names', ['names' => collect($c->attachments)->pluck('name')->implode(', ')]) : null,
            $body !== '' ? "\n".(mb_strlen($body) > 1500 ? mb_substr($body, 0, 1500).' […]' : $body) : null]);

        return implode("\n", $lines);
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
