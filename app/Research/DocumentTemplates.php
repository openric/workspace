<?php

// OpenRiC Workspace - research document templates and the documents filled in from them, and print templates for
// call slips, paging slips, receipts, badges and labels (roadmap phase 19j, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ResearchDocumentTemplatesController and research_print_template. A document template names its
// fields (a label, a kind: a line, a paragraph or a date, and whether it is needed); who runs the research services
// keeps them, and a researcher fills one in as a document of theirs, for a project or not, and prints it. Heratio kept
// the templates and filled in nothing. A print template is text with {placeholders}, the page size and orientation,
// margin and copies; the reading room's call slips use the institution's default call slip template when there is
// one. Changed: Heratio's print template was HTML with CSS, run as markup; here the text is escaped when printed, so a
// template cannot carry script, and the page is set from its size, orientation and margin.

namespace App\Research;

use App\Audit\AuditLog;
use App\Models\Concept;
use App\Models\ResearchDocument;
use App\Models\ResearchDocumentTemplate;
use App\Models\ResearchPrintTemplate;
use App\Models\ResearchProject;
use App\Models\User;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DocumentTemplates
{
    public const FIELD_KINDS = ['text', 'textarea', 'date'];

    /** The placeholders a call slip or paging slip may hold. */
    public const SLIP_PLACEHOLDERS = ['record', 'reference_code', 'location', 'researcher', 'card_number', 'room', 'date', 'time', 'priority', 'handling'];

    public function __construct(private Projects $projects) {}

    // Document templates.

    /** Fields from the form's lines: "Label | kind | required", kind text, textarea or date. */
    public static function parseFields(string $lines): array
    {
        $fields = [];
        foreach (preg_split('/\R/', $lines) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (($parts[0] ?? '') === '') {
                continue;
            }
            $kind = strtolower($parts[1] ?? 'text') ?: 'text';
            if (! in_array($kind, self::FIELD_KINDS, true)) {
                throw new InvalidArgumentException(__('A field is a text, textarea or date: :line', ['line' => $line]));
            }
            $key = Str::slug($parts[0], '_') ?: 'field_'.(count($fields) + 1);
            $fields[] = ['key' => isset(array_column($fields, null, 'key')[$key]) ? $key.'_'.(count($fields) + 1) : $key, 'label' => mb_substr($parts[0], 0, 255), 'type' => $kind,
                'required' => in_array(strtolower($parts[2] ?? ''), ['required', 'yes', 'true', '1'], true)];
        }

        return $fields;
    }

    public function saveTemplate(User $actor, array $data, ?ResearchDocumentTemplate $template = null): ResearchDocumentTemplate
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the template a name.'));
        }
        $fields = self::parseFields((string) ($data['fields'] ?? ''));
        if (! $fields) {
            throw new InvalidArgumentException(__('Give the template at least one field.'));
        }
        $values = ['name' => mb_substr(trim($data['name']), 0, 255), 'document_type_id' => $this->concept('document-type', (string) ($data['document_type'] ?? 'other')),
            'description' => trim((string) ($data['description'] ?? '')) ?: null, 'fields' => $fields];

        return DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $template ? tap($template)->update($values)
            : ResearchDocumentTemplate::create($values + ['institution_id' => $actor->institution_id, 'created_by_id' => $actor->id])));
    }

    public function saveDocument(User $user, ResearchDocumentTemplate $template, array $data, ?ResearchDocument $document = null): ResearchDocument
    {
        if ($template->institution_id !== $user->institution_id || ($document && $document->user_id !== $user->id)) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
        $values = [];
        foreach ($template->fields as $f) {
            $v = trim((string) ($data['values'][$f['key']] ?? ''));
            if ($f['required'] && $v === '') {
                throw new InvalidArgumentException(__(':field is needed.', ['field' => $f['label']]));
            }
            if ($f['type'] === 'date' && $v !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                throw new InvalidArgumentException(__('Give :field as a date.', ['field' => $f['label']]));
            }
            $values[$f['key']] = $v;
        }
        $project = ($data['project_id'] ?? '') ? ResearchProject::find($data['project_id']) : null;
        if (($data['project_id'] ?? '') && (! $project || $this->projects->level($user, $project) < Projects::LEVELS['contributor'])) {
            throw new InvalidArgumentException(__('Choose one of your projects.'));
        }
        $fields = ['title' => mb_substr(trim((string) ($data['title'] ?? '')) ?: $template->name, 0, 255), 'values' => $values, 'project_id' => $project?->id];

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => $document ? tap($document)->update($fields)
            : ResearchDocument::create($fields + ['template_id' => $template->id, 'user_id' => $user->id, 'created_by_id' => $user->id])));
    }

    // Print templates.

    public function savePrintTemplate(User $actor, array $data, ?ResearchPrintTemplate $template = null): ResearchPrintTemplate
    {
        foreach (['name' => __('Give the template a name.'), 'code' => __('Give the template a code.'), 'body' => __('Give the template its text.')] as $k => $message) {
            if (trim((string) ($data[$k] ?? '')) === '') {
                throw new InvalidArgumentException($message);
            }
        }
        $code = Str::slug((string) $data['code']);
        if (ResearchPrintTemplate::where(['institution_id' => $actor->institution_id, 'code' => $code])->when($template, fn ($q) => $q->whereKeyNot($template->id))->exists()) {
            throw new InvalidArgumentException(__('Another template has that code.'));
        }
        $type = $this->concept('print-template-type', (string) ($data['template_type'] ?? 'call-slip'));
        $values = ['name' => mb_substr(trim($data['name']), 0, 100), 'code' => mb_substr($code, 0, 50), 'template_type_id' => $type, 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'body' => (string) $data['body'], 'page_size_id' => $this->concept('print-page-size', (string) ($data['page_size'] ?? '') ?: 'a4'),
            'orientation_id' => $this->concept('page-orientation', (string) ($data['orientation'] ?? '') ?: 'portrait'), 'margin_mm' => max(0, min(50, (int) ($data['margin_mm'] ?? 10))),
            'copies_default' => max(1, min(10, (int) ($data['copies_default'] ?? 1))), 'is_default' => ! empty($data['is_default']), 'is_active' => ! empty($data['is_active'])];

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $template, $values, $type) {
            if ($values['is_default']) {
                ResearchPrintTemplate::where(['institution_id' => $actor->institution_id, 'template_type_id' => $type])->when($template, fn ($q) => $q->whereKeyNot($template->id))->update(['is_default' => false]);
            }

            return $template ? tap($template)->update($values) : ResearchPrintTemplate::create($values + ['institution_id' => $actor->institution_id, 'created_by_id' => $actor->id]);
        }));
    }

    /** The institution's default template of the kind, when it has an active one. */
    public function default(string $institutionId, string $type): ?ResearchPrintTemplate
    {
        return ResearchPrintTemplate::where(['institution_id' => $institutionId, 'is_default' => true, 'is_active' => true])
            ->whereHas('templateType', fn ($q) => $q->where('iri', Vocab::BASE.'print-template-type/'.$type))->first();
    }

    /** The template's text with its placeholders filled, escaped; unknown placeholders are left as they are. */
    public static function fill(string $body, array $values): string
    {
        return nl2br(e(preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0], $body)), false);
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
