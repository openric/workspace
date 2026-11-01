<?php

// OpenRiC Workspace - reproductions asked for by researchers (roadmap phase 19c, ADR 0017).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ReproductionService and ResearchReproductionsController (ahg-research): an approved researcher
// whose kind may ask for reproductions makes a request, numbered REP-YYYY-NNNNN, with its purpose, use and delivery,
// and adds copies of records (a kind, format, resolution, colour, how many, which pages); a request with no copy is
// not sent; sending it estimates its cost from the institution's prices, as Heratio's calculateCosts does (a base
// and so much a page, image, file or document; colour half as much again; 600 dpi and more a quarter again). Staff
// take it on, invoice it (INV-YYYYMM-NNNNN), record its payment (which puts it in production), finish each copy
// with its files, and complete or cancel it; every step is logged and told to the researcher. A delivered file is
// downloaded from the request for 30 days, as Heratio's are; its token is kept as a hash.

namespace App\Research;

use App\Audit\AuditLog;
use App\Mail\Outbox;
use App\Mail\ResearchMail;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\RecordResource;
use App\Models\ReproductionFile;
use App\Models\ReproductionItem;
use App\Models\ReproductionPrice;
use App\Models\ReproductionRequest;
use App\Models\ReproductionStatusChange;
use App\Models\User;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Reproductions
{
    public const STATUSES = Vocab::BASE.'reproduction-status/';

    public const DOWNLOAD_DAYS = 30;

    /** The steps staff take, from each status. */
    private const NEXT = ['submitted' => ['processing', 'cancelled'], 'processing' => ['awaiting-payment', 'in-production', 'cancelled'],
        'awaiting-payment' => ['in-production', 'cancelled'], 'in-production' => ['completed', 'cancelled']];

    public function __construct(private Researchers $researchers, private ResearchFiles $files, private Outbox $outbox) {}

    public function create(User $user, array $data): ReproductionRequest
    {
        $researcher = $this->researchers->of($user);
        if (! $this->researchers->active($researcher)) {
            throw new InvalidArgumentException(__('Only an approved researcher may ask for reproductions.'));
        }
        if ($researcher->type && ! $researcher->type->can_request_reproductions) {
            throw new InvalidArgumentException(__('Your kind of researcher may not ask for reproductions.'));
        }
        $delivery = $this->concept('delivery-method', $data['delivery_method'] ?? 'download');
        if (in_array($data['delivery_method'] ?? 'download', ['post', 'courier'], true) && trim((string) ($data['delivery_address'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Give the address to send them to.'));
        }
        $settings = app(Shop::class)->settings($user->institution_id);

        return DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $researcher, $data, $delivery, $settings) {
            $year = now(config('workspace.timezone'))->format('Y');
            $n = ReproductionRequest::where('institution_id', $user->institution_id)->where('reference', 'like', "REP-$year-%")->lockForUpdate()->get(['id'])->count() + 1;
            $request = ReproductionRequest::create(['institution_id' => $user->institution_id, 'researcher_id' => $researcher->id, 'reference' => sprintf('REP-%s-%05d', $year, $n),
                'purpose' => $this->text($data, 'purpose'), 'intended_use' => $this->text($data, 'intended_use'), 'publication_details' => $this->text($data, 'publication_details'),
                'status_id' => $this->concept('reproduction-status', 'draft'), 'currency' => $settings->currency, 'delivery_method_id' => $delivery,
                'delivery_address' => $this->text($data, 'delivery_address'), 'delivery_email' => $this->text($data, 'delivery_email') ?? $user->email, 'notes' => $this->text($data, 'notes')]);
            $this->log($request, null, 'draft', $user, null);

            return $request;
        }));
    }

    public function addItem(User $user, ReproductionRequest $request, RecordResource $unit, array $data): ReproductionItem
    {
        $this->mustOwn($user, $request);
        if ($request->status->notation !== 'draft') {
            throw new InvalidArgumentException(__('Copies can be added only before the request is sent.'));
        }
        if ($unit->institution_id !== $request->institution_id || ! EntityKinds::readable($user, $unit)) {
            throw new InvalidArgumentException(__('A record you asked for cannot be found.'));
        }
        $file = ($data['digital_file_id'] ?? '') !== '' ? DigitalFile::whereHas('instantiation', fn ($q) => $q->where('record_resource_id', $unit->id))->find($data['digital_file_id']) : null;

        return DB::transaction(fn () => AuditLog::actingAs($user, fn () => ReproductionItem::create(['reproduction_request_id' => $request->id, 'record_resource_id' => $unit->id,
            'digital_file_id' => $file?->id, 'reproduction_type_id' => $this->concept('reproduction-type', $data['reproduction_type'] ?? 'scan'), 'format' => $this->text($data, 'format'),
            'resolution' => $this->text($data, 'resolution'), 'colour_mode_id' => ($data['colour_mode'] ?? '') !== '' ? $this->concept('colour-mode', $data['colour_mode']) : null,
            'quantity' => max(1, min(10000, (int) ($data['quantity'] ?? 1))), 'page_range' => $this->text($data, 'page_range'),
            'special_instructions' => $this->text($data, 'special_instructions'), 'status_id' => $this->concept('reproduction-item-status', 'pending')])));
    }

    public function removeItem(User $user, ReproductionItem $item): void
    {
        $this->mustOwn($user, $item->request);
        if ($item->request->status->notation !== 'draft') {
            throw new InvalidArgumentException(__('Copies can be removed only before the request is sent.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, fn () => $item->delete()));
    }

    /** Send it: it must have a copy; its cost is estimated (Heratio's submitRequest). */
    public function submit(User $user, ReproductionRequest $request): void
    {
        $this->mustOwn($user, $request);
        if ($request->status->notation !== 'draft') {
            throw new InvalidArgumentException(__('The request has been sent already.'));
        }
        if (! $request->items()->exists()) {
            throw new InvalidArgumentException(__('Add at least one copy before sending the request.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($user, function () use ($user, $request) {
            $request->update(['estimated_cost' => $this->estimate($request), 'status_id' => $this->concept('reproduction-status', 'submitted')]);
            $this->log($request, 'draft', 'submitted', $user, __('Sent by the researcher.'));
        }));
    }

    /** Heratio's calculateCosts: each copy's base and units at the institution's prices, colour and high resolution dearer. */
    public function estimate(ReproductionRequest $request): float
    {
        // The institution's prices, seeded with Heratio's the first time they are needed.
        if (! ReproductionPrice::where('institution_id', $request->institution_id)->exists()) {
            (require database_path('migrations/2026_11_01_000300_create_research_services_19c.php'))::seedPrices($request->institution_id);
        }
        $prices = ReproductionPrice::where('institution_id', $request->institution_id)->get()->keyBy('reproduction_type_id');
        $total = 0.0;
        foreach ($request->items()->with('colourMode', 'reproductionType')->get() as $item) {
            $price = $prices[$item->reproduction_type_id] ?? $prices->first();
            $cost = (float) ($price?->base_price ?? 0) + (float) ($price?->unit_price ?? 0) * $item->quantity;
            if ($item->colourMode?->notation === 'colour') {
                $cost *= 1.5;
            }
            if (preg_match('/(\d+)/', (string) $item->resolution, $m) && (int) $m[1] >= 600) {
                $cost *= 1.25;
            }
            $item->update(['unit_price' => $price?->unit_price, 'total_price' => round($cost, 2)]);
            $total += $cost;
        }

        return round($total, 2);
    }

    /** A step by staff: take it on, ask for payment (with an invoice), produce, complete or cancel. */
    public function setStatus(User $actor, ReproductionRequest $request, string $status, ?string $notes = null): void
    {
        $this->mustManage($actor, $request);
        $from = $request->status->notation;
        if (! in_array($status, self::NEXT[$from] ?? [], true)) {
            throw new InvalidArgumentException(__('A request that is :from cannot become :to.', ['from' => __($from), 'to' => __($status)]));
        }
        if ($status === 'completed' && $request->items()->whereHas('status', fn ($q) => $q->whereIn('iri', [Vocab::BASE.'reproduction-item-status/pending', Vocab::BASE.'reproduction-item-status/in-progress']))->exists()) {
            throw new InvalidArgumentException(__('Finish or cancel every copy first.'));
        }
        if ($status === 'cancelled' && trim((string) $notes) === '') {
            throw new InvalidArgumentException(__('Say why it is cancelled.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $request, $status, $from, $notes) {
            $request->update(array_filter(['status_id' => $this->concept('reproduction-status', $status), 'processed_by_id' => $actor->id,
                'invoice_number' => $status === 'awaiting-payment' ? ($request->invoice_number ?? $this->invoiceNumber($request)) : null,
                'invoice_date' => $status === 'awaiting-payment' ? now()->toDateString() : null, 'completed_at' => $status === 'completed' ? now() : null,
                'closed_at' => in_array($status, ['completed', 'cancelled'], true) ? now() : null, 'closed_by_id' => in_array($status, ['completed', 'cancelled'], true) ? $actor->id : null,
                'closure_reason' => $status === 'cancelled' ? trim((string) $notes) : null], fn ($v) => $v !== null));
            $this->log($request, $from, $status, $actor, $notes);
        }));
        $this->tell($request, $status);
    }

    /** Heratio's recordPayment: the payment's reference, date, method and amount; it goes into production. */
    public function recordPayment(User $actor, ReproductionRequest $request, array $data): void
    {
        $this->mustManage($actor, $request);
        if (! in_array($request->status->notation, ['processing', 'awaiting-payment'], true)) {
            throw new InvalidArgumentException(__('A payment can be recorded only while the request waits for it.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $request->update(['payment_reference' => $this->text($data, 'reference'), 'payment_date' => ($data['date'] ?? '') ?: now()->toDateString(),
            'payment_method' => $this->text($data, 'method'), 'final_cost' => ($data['amount'] ?? '') !== '' ? round((float) $data['amount'], 2) : $request->estimated_cost])));
        $this->setStatus($actor, $request->fresh(), 'in-production', __('Payment recorded.'));
    }

    /** Staff change how it is handled. */
    public function handle(User $actor, ReproductionRequest $request, array $data): void
    {
        $this->mustManage($actor, $request);
        DB::transaction(fn () => AuditLog::actingAs($actor, fn () => $request->update(['admin_notes' => $this->text($data, 'admin_notes'), 'sla_due_on' => ($data['sla_due_on'] ?? '') ?: null,
            'final_cost' => ($data['final_cost'] ?? '') !== '' ? round((float) $data['final_cost'], 2) : $request->final_cost,
            'assigned_to_id' => ($data['assigned_to_id'] ?? '') !== '' ? User::where(['id' => $data['assigned_to_id'], 'institution_id' => $actor->institution_id])->value('id') : null])));
    }

    /** A copy made: its files kept and opened to the researcher for 30 days; or cancelled. */
    public function finishItem(User $actor, ReproductionItem $item, string $status, array $uploads = []): void
    {
        $this->mustManage($actor, $item->request);
        if (! in_array($status, ['in-progress', 'completed', 'cancelled'], true)) {
            throw new InvalidArgumentException(__('Choose a status from the list.'));
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($actor, $item, $status, $uploads) {
            foreach ($uploads as $upload) {
                $file = $this->files->keep($upload, $item->request->institution_id, $actor);
                ReproductionFile::create(['reproduction_item_id' => $item->id, 'research_file_id' => $file->id,
                    'download_token_hash' => hash('sha256', $this->downloadToken($item, $file->id)), 'download_expires_at' => now()->addDays(self::DOWNLOAD_DAYS)]);
            }
            $item->update(['status_id' => $this->concept('reproduction-item-status', $status), 'completed_at' => $status === 'completed' ? now() : null]);
        }));
    }

    /** A delivered file's token, derived from the item and the app's key so the researcher's page can show it; only its hash is stored. */
    public function downloadToken(ReproductionItem $item, string $researchFileId): string
    {
        return hash_hmac('sha256', 'reproduction:'.$item->id.':'.$researchFileId, (string) config('app.key'));
    }

    /** The file a researcher downloads, while it lasts; counted. */
    public function download(User $user, string $token): ?ReproductionFile
    {
        $file = ReproductionFile::where('download_token_hash', hash('sha256', $token))->with('item.request.researcher', 'file')->first();
        if ($file === null || $file->item->request->researcher->user_id !== $user->id || ($file->download_expires_at && $file->download_expires_at->isPast())) {
            return null;
        }
        $file->increment('download_count');

        return $file;
    }

    public function manages(User $user, ReproductionRequest $request): bool
    {
        return $user->institution_id === $request->institution_id && $user->hasPermission(Vocab::permission('manage-reading-room'));
    }

    private function invoiceNumber(ReproductionRequest $request): string
    {
        $month = now(config('workspace.timezone'))->format('Ym');
        $n = ReproductionRequest::where('institution_id', $request->institution_id)->where('invoice_number', 'like', "INV-$month-%")->count() + 1;

        return sprintf('INV-%s-%05d', $month, $n);
    }

    private function log(ReproductionRequest $request, ?string $from, string $to, User $actor, ?string $notes): void
    {
        ReproductionStatusChange::create(['reproduction_request_id' => $request->id, 'old_status' => $from, 'new_status' => $to, 'changed_by_id' => $actor->id, 'notes' => $notes, 'created_at' => now()]);
    }

    private function tell(ReproductionRequest $request, string $status): void
    {
        $user = $request->researcher->user;
        if ($user && $user->disabled_at === null) {
            $label = Concept::where('iri', self::STATUSES.$status)->first()?->label([$user->preferredLocale(), 'en']) ?? $status;
            $this->outbox->send($user, new ResearchMail($user, 'Your reproduction request :reference is :status', ['reference' => $request->reference, 'status' => mb_strtolower($label)],
                array_filter([$status === 'awaiting-payment' ? ['Invoice :invoice is for :currency :amount.', ['invoice' => $request->fresh()->invoice_number, 'currency' => $request->currency,
                    'amount' => number_format((float) ($request->fresh()->final_cost ?? $request->estimated_cost), 2)]] : null,
                    $status === 'completed' ? ['Your reproductions are ready on the request\'s page for :days days.', ['days' => self::DOWNLOAD_DAYS]] : null]),
                route('research.reproductions.show', $request), 'Open the request'));
        }
    }

    private function mustOwn(User $user, ReproductionRequest $request): void
    {
        if ($request->researcher->user_id !== $user->id) {
            throw new InvalidArgumentException(__('That is not yours.'));
        }
    }

    private function mustManage(User $actor, ReproductionRequest $request): void
    {
        if (! $this->manages($actor, $request)) {
            throw new InvalidArgumentException(__('Only who may manage the reading room may do that.'));
        }
    }

    private function text(array $data, string $key): ?string
    {
        return ($v = trim((string) ($data[$key] ?? ''))) === '' ? null : Str::limit($v, 5000, '');
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
