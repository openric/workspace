<?php

// OpenRiC Workspace - vendors and the work they do on the institution's records (roadmap phase 22d, ADR 0020).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ahg-vendor VendorController and VendorService: vendor codes VND<yy><nnnn> and transaction numbers
// TXN-<yyyymm>-<nnnn>, each status change stamping its date and who did it and kept in the history, the dashboard's
// counts, overdue and monthly figures, and a vendor's own figures. Changed: a vendor is an authority record, so its
// names, contact points and relations are the agent's; a record already out with a vendor is not sent again until it
// is back; a service that needs insurance is not sent without current cover or insurance arranged for it, nor one
// that needs a valuation without a declared value for every record; and work goes only to an active vendor.

namespace App\Vendors;

use App\Audit\AuditLog;
use App\Files\FileStore;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorService;
use App\Models\VendorServiceSetting;
use App\Models\VendorTransaction;
use App\Models\VendorTransactionAttachment;
use App\Models\VendorTransactionHistory;
use App\Models\VendorTransactionItem;
use App\Support\Vocab;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Vendors
{
    /** Heratio's service types: needs insurance, needs a valuation, typical days. */
    public const SERVICE_DEFAULTS = ['conservation' => [true, true, 30], 'restoration' => [true, true, 45], 'framing' => [true, true, 14], 'digitisation' => [true, false, 7],
        'photography' => [true, false, 3], 'binding' => [false, false, 21], 'cleaning' => [false, false, 5], 'pest-treatment' => [false, false, 7],
        'storage-materials' => [false, false, 3], 'transport' => [true, true, 1], 'valuation' => [false, false, 14], 'insurance' => [false, false, 7],
        'mounting' => [true, true, 7], 'deacidification' => [false, false, 14], 'encapsulation' => [false, false, 7], 'other' => [false, false, null]];

    /** Statuses after which the work is over. */
    public const CLOSED = ['returned', 'cancelled'];

    /** The vendor profile's own fields, as the form sends them. */
    private const PROFILE = ['registration_number', 'vat_number', ...Vendor::BANK, 'insurance_provider', 'insurance_policy_number', 'insurance_expiry_date',
        'insurance_coverage_amount', 'quality_rating', 'reliability_rating', 'price_rating', 'notes'];

    /** A transaction's own fields, as the form sends them. */
    private const TRANSACTION = ['request_date', 'expected_return_date', 'completion_date', 'invoice_date', 'payment_date', 'estimated_cost', 'actual_cost',
        'total_insured_value', 'quote_reference', 'invoice_reference', 'insurance_reference', 'shipping_method', 'tracking_number', 'courier_company',
        'dispatch_notes', 'vendor_notes', 'return_notes', 'internal_notes'];

    public function __construct(private FileStore $store) {}

    /** Each service with what it needs at this institution: the institution's own settings, else Heratio's. */
    public function services(string $institutionId): Collection
    {
        $own = VendorServiceSetting::where('institution_id', $institutionId)->get()->keyBy('service_type_id');

        return Concept::choices(Vocab::BASE.'vendor-service-type/')->get()->map(function (Concept $c) use ($own) {
            [$insurance, $valuation, $days] = self::SERVICE_DEFAULTS[$c->notation] ?? [false, false, null];
            $set = $own->get($c->id);

            return (object) ['concept' => $c, 'requires_insurance' => $set ? $set->requires_insurance : $insurance, 'requires_valuation' => $set ? $set->requires_valuation : $valuation,
                'typical_duration_days' => $set ? $set->typical_duration_days : $days, 'is_active' => $set ? $set->is_active : true];
        });
    }

    public function saveService(string $institutionId, string $notation, array $data): void
    {
        $concept = $this->term('vendor-service-type', $notation);
        $days = trim((string) ($data['typical_duration_days'] ?? ''));
        ($days === '' || (ctype_digit($days) && (int) $days <= 3650)) || throw new InvalidArgumentException(__('Give the usual number of days as a whole number.'));
        VendorServiceSetting::updateOrCreate(['institution_id' => $institutionId, 'service_type_id' => $concept->id], ['requires_insurance' => ! empty($data['requires_insurance']),
            'requires_valuation' => ! empty($data['requires_valuation']), 'typical_duration_days' => $days === '' ? null : (int) $days, 'is_active' => ! empty($data['is_active'])]);
    }

    /** Make an authority record a vendor (Heratio's add), with the next vendor code. */
    public function create(Agent $agent, array $data, User $actor): Vendor
    {
        $agent->deleted_at === null || throw new InvalidArgumentException(__('That authority record was deleted.'));
        Vendor::where('agent_id', $agent->id)->exists() && throw new InvalidArgumentException(__('That authority record is a vendor already.'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($agent, $data, $actor) {
            // Numbered in turn per institution and year; the lock keeps two at once from taking the same code.
            DB::table('institutions')->where('id', $agent->institution_id)->lockForUpdate()->first();
            $prefix = 'VND'.now()->format('y');
            $last = Vendor::where('institution_id', $agent->institution_id)->where('vendor_code', 'like', $prefix.'%')->max('vendor_code');
            $code = trim((string) ($data['vendor_code'] ?? '')) ?: $prefix.str_pad((string) ((int) substr((string) $last, -4) + 1), 4, '0', STR_PAD_LEFT);
            Vendor::where(['institution_id' => $agent->institution_id, 'vendor_code' => $code])->exists() && throw new InvalidArgumentException(__('Another vendor has the code :code.', ['code' => $code]));
            $vendor = Vendor::create(['institution_id' => $agent->institution_id, 'agent_id' => $agent->id, 'vendor_code' => $code, 'created_by_id' => $actor->id,
                ...$this->profile($data)]);
            $this->syncServices($vendor, (array) ($data['services'] ?? []));

            return $vendor;
        }));
    }

    public function update(Vendor $vendor, array $data, User $actor): void
    {
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($vendor, $data, $actor) {
            $fields = $this->profile($data, $vendor);
            // Approved when it first becomes active, by whom (Heratio's approved_by).
            if ($vendor->status->notation !== 'active' && Concept::find($fields['status_id'])->notation === 'active') {
                $fields += ['approved_by_id' => $actor->id, 'approved_at' => now()];
            }
            $vendor->update($fields);
            $this->syncServices($vendor, (array) ($data['services'] ?? []));
        }));
    }

    /** A vendor that has done work keeps its history: it is made inactive, not deleted. */
    public function delete(Vendor $vendor, User $actor): void
    {
        $vendor->transactions()->exists() && throw new InvalidArgumentException(__('This vendor has transactions; make it inactive instead.'));
        AuditLog::actingAs($actor, fn () => DB::transaction(function () use ($vendor) {
            $vendor->services()->delete();
            $vendor->delete();
        }));
    }

    /** @param  array<string, array>  $services  service notation => rates, for each service offered */
    private function syncServices(Vendor $vendor, array $services): void
    {
        $keep = [];
        foreach ($services as $notation => $rates) {
            if (! is_array($rates) || empty($rates['offered'])) {
                continue;
            }
            $concept = $this->term('vendor-service-type', (string) $notation);
            $money = fn ($v) => ($v = trim((string) $v)) === '' ? null : (is_numeric($v) && $v >= 0 ? $v : throw new InvalidArgumentException(__('A rate is a positive amount.')));
            VendorService::updateOrCreate(['vendor_id' => $vendor->id, 'service_type_id' => $concept->id], ['hourly_rate' => $money($rates['hourly_rate'] ?? ''),
                'fixed_rate' => $money($rates['fixed_rate'] ?? ''), 'currency_id' => $this->term('currency', (string) ($rates['currency'] ?? 'ZAR'))->id,
                'is_preferred' => ! empty($rates['is_preferred']), 'notes' => ($n = trim((string) ($rates['notes'] ?? ''))) === '' ? null : $n]);
            $keep[] = $concept->id;
        }
        $vendor->services()->whereNotIn('service_type_id', $keep)->get()->each->delete();
    }

    private function profile(array $data, ?Vendor $vendor = null): array
    {
        $out = [];
        foreach (self::PROFILE as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $out[$field] = $value === '' ? null : $value;
        }
        foreach (['quality_rating', 'reliability_rating', 'price_rating'] as $rating) {
            $out[$rating] === null || in_array($out[$rating], ['1', '2', '3', '4', '5'], true) || throw new InvalidArgumentException(__('A rating is from 1 to 5 stars.'));
        }
        $out['insurance_coverage_amount'] === null || is_numeric($out['insurance_coverage_amount']) || throw new InvalidArgumentException(__('The cover is an amount.'));

        return $out + ['vendor_type_id' => $this->term('vendor-type', (string) ($data['vendor_type'] ?? $vendor?->vendorType->notation ?? 'company'))->id,
            'status_id' => $this->term('vendor-status', (string) ($data['status'] ?? $vendor?->status->notation ?? 'active'))->id,
            'has_insurance' => ! empty($data['has_insurance']), 'is_preferred' => ! empty($data['is_preferred']), 'is_bbbee_compliant' => ! empty($data['is_bbbee_compliant'])];
    }

    /** Is the vendor's own insurance in force today? */
    public function insured(Vendor $vendor): bool
    {
        return $vendor->has_insurance && ($vendor->insurance_expiry_date === null || ! $vendor->insurance_expiry_date->isPast());
    }

    /** Request work of a vendor (Heratio's addTransaction): numbered, pending approval, its first history entry. */
    public function request(Vendor $vendor, array $data, User $actor): VendorTransaction
    {
        $vendor->status->notation === 'active' || throw new InvalidArgumentException(__('Work goes only to an active vendor.'));
        $service = $this->term('vendor-service-type', (string) ($data['service_type'] ?? ''));
        $vendor->services()->where('service_type_id', $service->id)->exists() || throw new InvalidArgumentException(__('This vendor does not offer that service.'));

        return DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($vendor, $data, $actor, $service) {
            DB::table('institutions')->where('id', $vendor->institution_id)->lockForUpdate()->first();
            $prefix = 'TXN-'.now()->format('Ym').'-';
            $last = VendorTransaction::where('institution_id', $vendor->institution_id)->where('transaction_number', 'like', $prefix.'%')->max('transaction_number');
            $status = $this->term('vendor-transaction-status', 'pending-approval');
            $fields = $this->transaction($data);
            // Due back after the service's usual days, unless a date was given.
            $days = $this->services($vendor->institution_id)->first(fn ($s) => $s->concept->id === $service->id)?->typical_duration_days;
            $fields['expected_return_date'] ??= $days === null ? null : CarbonImmutable::parse($fields['request_date'])->addDays($days)->toDateString();
            $transaction = VendorTransaction::create(['institution_id' => $vendor->institution_id, 'vendor_id' => $vendor->id, 'service_type_id' => $service->id,
                'transaction_number' => $prefix.str_pad((string) ((int) substr((string) $last, -4) + 1), 4, '0', STR_PAD_LEFT), 'status_id' => $status->id,
                'requested_by_id' => $actor->id, ...$fields]);
            VendorTransactionHistory::create(['transaction_id' => $transaction->id, 'status_to_id' => $status->id, 'changed_by_id' => $actor->id, 'notes' => __('Requested'), 'created_at' => now()]);

            return $transaction;
        }));
    }

    public function updateTransaction(VendorTransaction $transaction, array $data, User $actor): void
    {
        AuditLog::actingAs($actor, fn () => $transaction->update($this->transaction($data, $transaction)));
    }

    private function transaction(array $data, ?VendorTransaction $transaction = null): array
    {
        $out = [];
        foreach (self::TRANSACTION as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $out[$field] = $value === '' ? null : $value;
        }
        $out['request_date'] ??= $transaction?->request_date?->toDateString() ?? now()->toDateString();
        foreach (VendorTransaction::DATES as $date) {
            if (isset($out[$date]) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $out[$date])) {
                throw new InvalidArgumentException(__('Give dates as YYYY-MM-DD.'));
            }
        }
        foreach (['estimated_cost', 'actual_cost', 'total_insured_value'] as $money) {
            $out[$money] === null || (is_numeric($out[$money]) && $out[$money] >= 0) || throw new InvalidArgumentException(__('An amount is a positive number.'));
        }

        return $out + ['currency_id' => $this->term('currency', (string) ($data['currency'] ?? $transaction?->currency?->notation ?? 'ZAR'))->id,
            'payment_status_id' => $this->term('vendor-payment-status', (string) ($data['payment_status'] ?? $transaction?->paymentStatus?->notation ?? 'pending'))->id,
            'insurance_arranged' => ! empty($data['insurance_arranged'])];
    }

    /** Move the work on (Heratio's updateTransactionStatus), stamping what Heratio stamps and keeping the change. */
    public function setStatus(VendorTransaction $transaction, string $notation, ?string $notes, User $actor): void
    {
        $to = $this->term('vendor-transaction-status', $notation);
        $from = $transaction->status;
        $from->id === $to->id && throw new InvalidArgumentException(__('It is :status already.', ['status' => $notation]));
        in_array($from->notation, self::CLOSED, true) && throw new InvalidArgumentException(__('The work is over; request it again instead.'));
        if ($notation === 'dispatched') {
            $this->readyToSend($transaction);
        }
        DB::transaction(fn () => AuditLog::actingAs($actor, function () use ($transaction, $to, $from, $notes, $actor, $notation) {
            $fields = ['status_id' => $to->id] + match ($notation) {
                'approved' => ['approval_date' => now()->toDateString(), 'approved_by_id' => $actor->id],
                'dispatched' => ['dispatch_date' => now()->toDateString(), 'dispatched_by_id' => $actor->id],
                'completed' => ['completion_date' => $transaction->completion_date?->toDateString() ?? now()->toDateString()],
                'returned' => ['actual_return_date' => now()->toDateString(), 'received_by_id' => $actor->id],
                default => [],
            };
            $transaction->update($fields);
            match ($notation) {
                'dispatched' => $transaction->items()->whereNull('dispatched_at')->update(['dispatched_at' => now()]),
                'returned' => $transaction->items()->whereNull('returned_at')->update(['returned_at' => now()]),
                default => null,
            };
            VendorTransactionHistory::create(['transaction_id' => $transaction->id, 'status_from_id' => $from->id, 'status_to_id' => $to->id, 'changed_by_id' => $actor->id,
                'notes' => ($n = trim((string) $notes)) === '' ? null : $n, 'created_at' => now()]);
        }));
    }

    /** Before records leave: approved, with records, insured and valued where the service asks it. */
    private function readyToSend(VendorTransaction $transaction): void
    {
        $transaction->approval_date !== null || throw new InvalidArgumentException(__('Approve the work before the records are sent.'));
        $transaction->items()->exists() || throw new InvalidArgumentException(__('Add the records being sent first.'));
        $service = $this->services($transaction->institution_id)->first(fn ($s) => $s->concept->id === $transaction->service_type_id);
        if ($service?->requires_insurance && ! $transaction->insurance_arranged && ! $this->insured($transaction->vendor)) {
            throw new InvalidArgumentException(__('This service needs insurance: the vendor\'s cover has lapsed or it has none, and none was arranged for this work.'));
        }
        if ($service?->requires_valuation && $transaction->items()->whereNull('declared_value')->exists()) {
            throw new InvalidArgumentException(__('This service needs a valuation: give every record its declared value.'));
        }
    }

    /** Send a record with the work (Heratio's addTransactionItem); not one already out with a vendor. */
    public function addItem(VendorTransaction $transaction, RecordResource $record, array $data, User $actor): VendorTransactionItem
    {
        ($record->institution_id === $transaction->institution_id && $record->deleted_at === null) || throw new InvalidArgumentException(__('That record is not here.'));
        $actor->may('read', 'description', $record) || throw new InvalidArgumentException(__('You may not read that record.'));
        $out = VendorTransactionItem::where('record_resource_id', $record->id)->whereHas('transaction', fn ($q) => $q->where('id', '!=', $transaction->id)
            ->whereHas('status', fn ($s) => $s->whereNotIn('iri', array_map(fn ($n) => Vocab::BASE.'vendor-transaction-status/'.$n, self::CLOSED))))->with('transaction')->first();
        $out && throw new InvalidArgumentException(__('That record is out with a vendor already, under :number.', ['number' => $out->transaction->transaction_number]));
        $transaction->items()->where('record_resource_id', $record->id)->exists() && throw new InvalidArgumentException(__('That record is in this work already.'));

        return AuditLog::actingAs($actor, fn () => VendorTransactionItem::create(['transaction_id' => $transaction->id, 'record_resource_id' => $record->id, ...$this->item($data)]));
    }

    public function updateItem(VendorTransactionItem $item, array $data, User $actor): void
    {
        AuditLog::actingAs($actor, fn () => $item->update($this->item($data)));
    }

    public function removeItem(VendorTransactionItem $item, User $actor): void
    {
        $item->dispatched_at === null || throw new InvalidArgumentException(__('That record was sent; record its return instead.'));
        AuditLog::actingAs($actor, fn () => $item->delete());
    }

    private function item(array $data): array
    {
        $text = fn ($k) => ($v = trim((string) ($data[$k] ?? ''))) === '' ? null : $v;
        $grade = fn ($k) => ($n = $text($k)) === null ? null : $this->term('condition-grade', $n)->id;
        foreach (['declared_value', 'item_cost'] as $money) {
            $text($money) === null || (is_numeric($text($money)) && $text($money) >= 0) || throw new InvalidArgumentException(__('An amount is a positive number.'));
        }

        return ['condition_before' => $text('condition_before'), 'condition_before_grade_id' => $grade('condition_before_grade'), 'condition_after' => $text('condition_after'),
            'condition_after_grade_id' => $grade('condition_after_grade'), 'declared_value' => $text('declared_value'), 'item_cost' => $text('item_cost'),
            'service_description' => $text('service_description'), 'service_notes' => $text('service_notes'), 'service_completed' => ! empty($data['service_completed'])];
    }

    /** A quote, invoice, condition report or photograph kept with the work. */
    public function attach(VendorTransaction $transaction, string $path, string $originalName, string $type, ?string $description, User $actor): VendorTransactionAttachment
    {
        $concept = $this->term('vendor-attachment-type', $type);
        $file = $this->store->ingest($transaction->institution_id, $path);

        return AuditLog::actingAs($actor, fn () => VendorTransactionAttachment::create(['institution_id' => $transaction->institution_id, 'transaction_id' => $transaction->id,
            'type_id' => $concept->id, 'original_name' => mb_substr($originalName, 0, 255), 'storage_path' => $file['storage_path'], 'sha256' => $file['sha256'],
            'size_bytes' => $file['size_bytes'], 'mime_type' => $file['mime_type'], 'scanned_with' => $file['scanned_with'], 'scanned_at' => $file['scanned_at'],
            'description' => ($d = trim((string) $description)) === '' ? null : $d, 'uploaded_by_id' => $actor->id, 'created_at' => now()]));
    }

    /** A vendor's figures (Heratio's getVendorStats). */
    public function figures(Vendor $vendor): array
    {
        $all = $vendor->transactions()->with('status')->get();
        $returned = $all->filter(fn ($t) => $t->actual_return_date !== null);

        return ['total' => $all->count(), 'completed' => $all->filter(fn ($t) => $t->status->notation === 'returned')->count(),
            'active' => $all->reject(fn ($t) => in_array($t->status->notation, self::CLOSED, true))->count(),
            'on_time' => $returned->filter(fn ($t) => $t->expected_return_date !== null && $t->actual_return_date->lte($t->expected_return_date))->count(),
            'total_cost' => $all->sum(fn ($t) => (float) $t->actual_cost),
            'turnaround' => ($sent = $returned->filter(fn ($t) => $t->dispatch_date !== null))->isEmpty() ? null : round($sent->avg(fn ($t) => $t->dispatch_date->diffInDays($t->actual_return_date)), 1),
            'items' => VendorTransactionItem::whereIn('transaction_id', $all->pluck('id'))->count()];
    }

    /** The dashboard (Heratio's index): counts by status, what is overdue, the latest open work, and twelve months. */
    public function dashboard(Institution $institution): array
    {
        $open = VendorTransaction::where('institution_id', $institution->id)->whereHas('status', fn ($q) => $q->whereNotIn('iri', array_map(fn ($n) => Vocab::BASE.'vendor-transaction-status/'.$n, self::CLOSED)))
            ->with('vendor.agent', 'serviceType.labels', 'status.labels')->orderByDesc('created_at')->get();
        $year = VendorTransaction::where('institution_id', $institution->id)->where('request_date', '>=', now()->subMonths(12)->startOfMonth())->get();

        return ['vendors' => Vendor::where('institution_id', $institution->id)->count(),
            'byStatus' => VendorTransaction::where('institution_id', $institution->id)->select('status_id', DB::raw('count(*) as n'))->groupBy('status_id')->pluck('n', 'status_id'),
            'overdue' => $open->filter->overdue()->sortBy('expected_return_date')->values(), 'open' => $open->take(10),
            'months' => $year->groupBy(fn ($t) => $t->request_date->format('Y-m'))->sortKeys()->map(fn ($g) => ['count' => $g->count(), 'cost' => $g->sum(fn ($t) => (float) $t->actual_cost)])];
    }

    private function term(string $vocabulary, string $notation): Concept
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->where('notation', $notation)->first()
            ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
