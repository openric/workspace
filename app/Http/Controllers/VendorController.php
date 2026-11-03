<?php

// OpenRiC Workspace - vendors and the work sent to them (roadmap phase 22d), after Heratio's ahg-vendor VendorController:
// the dashboard, the vendor list with its filters, vendor profiles on authority records, the services and what each
// needs, and transactions with their records, status changes and documents. Another institution's vendor or
// transaction does not exist here (404); without the permission, 403.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Description\AgentService;
use App\Files\FileResponse;
use App\Files\FileStore;
use App\Models\Agent;
use App\Models\Concept;
use App\Models\RecordResource;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Models\VendorTransactionAttachment;
use App\Models\VendorTransactionItem;
use App\Relations\EntityKinds;
use App\Relations\RelationService;
use App\Support\Vocab;
use App\Vendors\Vendors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VendorController extends Controller
{
    public function __construct(private Vendors $vendors) {}

    public function index(Request $request): View
    {
        return view('vendors.index', ['dashboard' => $this->vendors->dashboard($request->user()->institution), 'statuses' => $this->choices('vendor-transaction-status')]);
    }

    /** Heratio's list: by name, code or registration, and by status, type, service, insurance and preference. */
    public function list(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $vendors = Vendor::where('institution_id', $request->user()->institution_id)->with('agent.names.nameType', 'status.labels', 'vendorType.labels', 'services.serviceType.labels')
            ->when($request->filled('status'), fn ($w) => $w->whereHas('status', fn ($s) => $s->where('notation', $request->query('status'))))
            ->when($request->filled('type'), fn ($w) => $w->whereHas('vendorType', fn ($s) => $s->where('notation', $request->query('type'))))
            ->when($request->filled('service'), fn ($w) => $w->whereHas('services.serviceType', fn ($s) => $s->where('notation', $request->query('service'))))
            ->when($request->boolean('insured'), fn ($w) => $w->where('has_insurance', true)->where(fn ($d) => $d->whereNull('insurance_expiry_date')->orWhere('insurance_expiry_date', '>=', now()->toDateString())))
            ->when($request->boolean('preferred'), fn ($w) => $w->where('is_preferred', true))
            ->get()
            ->filter(fn (Vendor $v) => $q === '' || str_contains(mb_strtolower(EntityKinds::label($v->agent).' '.$v->vendor_code.' '.$v->registration_number.' '.$v->vat_number), mb_strtolower($q)))
            ->sortBy(fn (Vendor $v) => mb_strtolower((string) EntityKinds::label($v->agent)))->values();

        return view('vendors.list', ['vendors' => $vendors, 'q' => $q, 'statuses' => $this->choices('vendor-status'), 'types' => $this->choices('vendor-type'),
            'services' => $this->choices('vendor-service-type')]);
    }

    /** Make an authority record a vendor: found by name, or a new corporate body. */
    public function create(Request $request): View
    {
        $this->may($request, 'create');
        $q = trim((string) $request->query('q', ''));
        $agent = $request->filled('agent') ? $this->agent($request, (string) $request->query('agent')) : null;

        return view('vendors.create', ['agent' => $agent, 'q' => $q, 'candidates' => $agent || $q === '' ? collect() : app(RelationService::class)->candidates($request->user(), ['agent'], $q)
            ->reject(fn ($a) => Vendor::where('agent_id', $a->id)->exists()), ...$this->formChoices()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->may($request, 'create');
        try {
            $agent = $request->filled('agent') ? $this->agent($request, (string) $request->input('agent'))
                : app(AgentService::class)->create($request->user()->institution, 'corporate-body', trim((string) $request->input('new_name')) ?: throw new InvalidArgumentException(__('Name the vendor.')), 'en', null, $request->user());
            $vendor = $this->vendors->create($agent, $request->all(), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['vendor' => $e->getMessage()]);
        }

        return redirect()->route('vendors.show', $vendor)->with('status', __('Vendor :code made.', ['code' => $vendor->vendor_code]));
    }

    public function show(Request $request, Vendor $vendor): View
    {
        $this->own($request, $vendor);
        $vendor->load('agent.names.nameType', 'agent.contactPoints', 'status.labels', 'vendorType.labels', 'services.serviceType.labels', 'services.currency');

        return view('vendors.show', ['vendor' => $vendor, 'figures' => $this->vendors->figures($vendor), 'insured' => $this->vendors->insured($vendor),
            'transactions' => $vendor->transactions()->with('serviceType.labels', 'status.labels')->orderByDesc('request_date')->get(),
            'canUpdate' => $request->user()->may('update', 'vendor'), 'serviceChoices' => $this->vendors->services($vendor->institution_id)->where('is_active', true)]);
    }

    public function edit(Request $request, Vendor $vendor): View
    {
        $this->own($request, $vendor, 'update');

        return view('vendors.edit', ['vendor' => $vendor->load('services.serviceType', 'services.currency'), ...$this->formChoices()]);
    }

    public function update(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->own($request, $vendor, 'update');
        try {
            $this->vendors->update($vendor, $request->all(), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['vendor' => $e->getMessage()]);
        }

        return redirect()->route('vendors.show', $vendor)->with('status', __('Saved.'));
    }

    public function destroy(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->own($request, $vendor, 'delete');
        try {
            $this->vendors->delete($vendor, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['vendor' => $e->getMessage()]);
        }

        return redirect()->route('vendors.list')->with('status', __('The vendor profile was removed; the authority record stays.'));
    }

    public function services(Request $request): View
    {
        return view('vendors.services', ['services' => $this->vendors->services($request->user()->institution_id), 'canUpdate' => $request->user()->may('update', 'vendor')]);
    }

    public function saveServices(Request $request): RedirectResponse
    {
        $this->may($request, 'update');
        try {
            foreach ((array) $request->input('services', []) as $notation => $data) {
                $this->vendors->saveService($request->user()->institution_id, (string) $notation, (array) $data);
            }
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['services' => $e->getMessage()]);
        }

        return redirect()->route('vendors.services')->with('status', __('Saved.'));
    }

    /** Heratio's transaction list: by number, vendor, status, service, and what is overdue. */
    public function transactions(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $all = VendorTransaction::where('institution_id', $request->user()->institution_id)->with('vendor.agent.names.nameType', 'serviceType.labels', 'status.labels')
            ->when($request->filled('status'), fn ($w) => $w->whereHas('status', fn ($s) => $s->where('notation', $request->query('status'))))
            ->when($request->filled('service'), fn ($w) => $w->whereHas('serviceType', fn ($s) => $s->where('notation', $request->query('service'))))
            ->when($q !== '', fn ($w) => $w->where(fn ($o) => $o->where('transaction_number', 'ilike', '%'.addcslashes($q, '%_\\').'%')
                ->orWhereIn('vendor_id', Vendor::where('institution_id', $request->user()->institution_id)->get()->filter(fn ($v) => str_contains(mb_strtolower((string) EntityKinds::label($v->agent)), mb_strtolower($q)))->pluck('id'))))
            ->orderByDesc('request_date')->orderByDesc('transaction_number')->get()
            ->when($request->boolean('overdue'), fn ($c) => $c->filter->overdue()->values());

        return view('vendors.transactions', ['transactions' => $all, 'q' => $q, 'statuses' => $this->choices('vendor-transaction-status'), 'services' => $this->choices('vendor-service-type')]);
    }

    public function requestWork(Request $request, Vendor $vendor): View
    {
        $this->own($request, $vendor, 'create');

        return view('vendors.transaction-form', ['vendor' => $vendor->load('services.serviceType.labels'), 'transaction' => null, ...$this->transactionChoices()]);
    }

    public function storeTransaction(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->own($request, $vendor, 'create');
        try {
            $transaction = $this->vendors->request($vendor, $request->all(), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['transaction' => $e->getMessage()]);
        }

        return redirect()->route('vendors.transaction', $transaction)->with('status', __('Work requested as :number.', ['number' => $transaction->transaction_number]));
    }

    public function transaction(Request $request, VendorTransaction $transaction): View
    {
        $this->ownTransaction($request, $transaction);
        $transaction->load('vendor.agent.names.nameType', 'serviceType.labels', 'status.labels', 'paymentStatus.labels', 'currency', 'items.record.names.nameType',
            'items.gradeBefore.labels', 'items.gradeAfter.labels', 'history.from.labels', 'history.to.labels', 'history.changedBy', 'attachments.type.labels');
        $q = trim((string) $request->query('q', ''));

        return view('vendors.transaction', ['transaction' => $transaction, 'canUpdate' => $request->user()->may('update', 'vendor'),
            'service' => $this->vendors->services($transaction->institution_id)->first(fn ($s) => $s->concept->id === $transaction->service_type_id),
            'insured' => $this->vendors->insured($transaction->vendor), 'q' => $q,
            'candidates' => $q === '' ? collect() : app(RelationService::class)->candidates($request->user(), ['record'], $q)->reject(fn ($r) => $transaction->items->contains('record_resource_id', $r->id)),
            'statuses' => $this->choices('vendor-transaction-status'), 'grades' => $this->choices('condition-grade'), 'attachmentTypes' => $this->choices('vendor-attachment-type')]);
    }

    public function editTransaction(Request $request, VendorTransaction $transaction): View
    {
        $this->ownTransaction($request, $transaction, 'update');

        return view('vendors.transaction-form', ['vendor' => $transaction->vendor, 'transaction' => $transaction, ...$this->transactionChoices()]);
    }

    public function updateTransaction(Request $request, VendorTransaction $transaction): RedirectResponse
    {
        return $this->act($request, $transaction, fn () => $this->vendors->updateTransaction($transaction, $request->all(), $request->user()), __('Saved.'));
    }

    public function status(Request $request, VendorTransaction $transaction): RedirectResponse
    {
        return $this->act($request, $transaction, fn () => $this->vendors->setStatus($transaction, (string) $request->input('status'), $request->input('notes'), $request->user()), __('Status changed.'));
    }

    public function addItem(Request $request, VendorTransaction $transaction): RedirectResponse
    {
        return $this->act($request, $transaction, function () use ($request, $transaction) {
            $record = RecordResource::where('institution_id', $transaction->institution_id)->find((string) $request->input('record')) ?? throw new InvalidArgumentException(__('Choose a record.'));
            $this->vendors->addItem($transaction, $record, $request->all(), $request->user());
        }, __('Record added.'));
    }

    public function updateItem(Request $request, VendorTransaction $transaction, VendorTransactionItem $item): RedirectResponse
    {
        abort_unless($item->transaction_id === $transaction->id, 404);

        return $this->act($request, $transaction, fn () => $this->vendors->updateItem($item, $request->all(), $request->user()), __('Saved.'));
    }

    public function removeItem(Request $request, VendorTransaction $transaction, VendorTransactionItem $item): RedirectResponse
    {
        abort_unless($item->transaction_id === $transaction->id, 404);

        return $this->act($request, $transaction, fn () => $this->vendors->removeItem($item, $request->user()), __('Record removed.'));
    }

    public function attach(Request $request, VendorTransaction $transaction): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        return $this->act($request, $transaction, fn () => $this->vendors->attach($transaction, $request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName(),
            (string) $request->input('type'), $request->input('description'), $request->user()), __('Document kept.'));
    }

    public function attachment(Request $request, VendorTransaction $transaction, VendorTransactionAttachment $attachment): BinaryFileResponse
    {
        $this->ownTransaction($request, $transaction);
        abort_unless($attachment->transaction_id === $transaction->id, 404);
        $path = app(FileStore::class)->path($attachment->storage_path);
        abort_unless(is_file($path), 404);

        return FileResponse::make($attachment, $path, $request->boolean('download'));
    }

    private function act(Request $request, VendorTransaction $transaction, callable $do, string $done): RedirectResponse
    {
        $this->ownTransaction($request, $transaction, 'update');
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['transaction' => $e->getMessage()]);
        }

        return redirect()->route('vendors.transaction', $transaction)->with('status', $done);
    }

    private function formChoices(): array
    {
        return ['statuses' => $this->choices('vendor-status'), 'types' => $this->choices('vendor-type'), 'services' => $this->choices('vendor-service-type'), 'currencies' => $this->choices('currency')];
    }

    private function transactionChoices(): array
    {
        return ['payments' => $this->choices('vendor-payment-status'), 'currencies' => $this->choices('currency')];
    }

    private function choices(string $vocabulary)
    {
        return Concept::choices(Vocab::BASE.$vocabulary.'/')->get();
    }

    private function agent(Request $request, string $id): Agent
    {
        return Agent::where('institution_id', $request->user()->institution_id)->whereNull('deleted_at')->findOrFail($id);
    }

    private function may(Request $request, string $action): void
    {
        abort_unless($request->user()->may($action, 'vendor'), 403);
    }

    private function own(Request $request, Vendor $vendor, string $action = 'read'): void
    {
        abort_unless($vendor->institution_id === $request->user()->institution_id, 404);
        $this->may($request, $action);
    }

    private function ownTransaction(Request $request, VendorTransaction $transaction, string $action = 'read'): void
    {
        abort_unless($transaction->institution_id === $request->user()->institution_id, 404);
        $this->may($request, $action);
    }
}
