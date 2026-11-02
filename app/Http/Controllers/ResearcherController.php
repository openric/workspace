<?php

// OpenRiC Workspace - a researcher's own pages (roadmap phase 19b, ADR 0017), after Heratio's
// ResearchRegistrationController and ResearchBookingsController (ahg-research): register, keep one's details up,
// renew, book the reading room with the records to have ready, see a booking and its requests, write about them,
// and book equipment.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\Favourite;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestMessage;
use App\Models\ReadingRoom;
use App\Models\ReadingRoomBooking;
use App\Models\ReadingRoomEquipment;
use App\Models\RecordResource;
use App\Models\ResearcherOrcidLink;
use App\Models\ResearcherType;
use App\Models\ResearchFile;
use App\Research\Bookings;
use App\Research\Orcid;
use App\Research\Researchers;
use App\Research\ResearchFiles;
use App\Research\Retrieval;
use App\Support\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ResearcherController extends Controller
{
    public function __construct(private Researchers $researchers, private Bookings $bookings, private Retrieval $retrieval) {}

    public function profile(Request $request): View
    {
        $researcher = $this->researchers->of($request->user());

        return view('research.profile', ['researcher' => $researcher, 'active' => $this->researchers->active($researcher),
            'bookings' => $researcher ? $researcher->bookings()->with('room', 'status')->orderByDesc('booking_date')->get() : collect(),
            'types' => ResearcherType::where(['institution_id' => $request->user()->institution_id, 'is_active' => true])->orderBy('position')->get(),
            'affiliations' => Concept::choices(Vocab::BASE.'researcher-affiliation/')->get(), 'idTypes' => Concept::choices(Vocab::BASE.'identity-document-type/')->get(),
            'orcidLink' => $researcher ? ResearcherOrcidLink::where('researcher_id', $researcher->id)->first() : null, 'orcidReady' => (bool) app(Orcid::class)->settings($request->user())]);
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        return $this->attempt(fn () => $this->researchers->register($request->user(), $data, $request->file('photo')), __('Your registration is sent; the reading room will review it.'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $researcher = $this->researchers->of($request->user()) ?? abort(404);

        return $this->attempt(fn () => $this->researchers->update($request->user(), $researcher, $data, $request->file('photo')), __('Saved.'));
    }

    public function renew(Request $request): RedirectResponse
    {
        $researcher = $this->researchers->of($request->user()) ?? abort(404);

        return $this->attempt(fn () => $this->researchers->renew($request->user(), $researcher), __('Your renewal is sent; the reading room will review it.'));
    }

    /** Book a visit (Heratio's book), the records from one's favourites or by their IRIs. */
    public function bookForm(Request $request): View|RedirectResponse
    {
        $researcher = $this->researchers->of($request->user());
        if (! $this->researchers->active($researcher)) {
            return redirect()->route('research.profile')->withErrors(['research' => __('Only an approved researcher may book the reading room.')]);
        }

        return view('research.book', ['researcher' => $researcher, 'rooms' => ReadingRoom::where(['institution_id' => $request->user()->institution_id, 'is_active' => true])->orderBy('name')->get(),
            'favourites' => Favourite::where(['user_id' => $request->user()->id, 'subject_table' => 'record_resources'])->get()
                ->map(fn ($f) => RecordResource::whereNull('deleted_at')->find($f->subject_id))->filter()->values(),
            'record' => $request->query('record') ? RecordResource::where('institution_id', $request->user()->institution_id)->find($request->query('record')) : null]);
    }

    public function book(Request $request): RedirectResponse
    {
        $data = $request->validate(['reading_room_id' => ['required', 'uuid'], 'booking_date' => ['required', 'date_format:Y-m-d'], 'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'], 'purpose' => ['nullable', 'string', 'max:2000'], 'notes' => ['nullable', 'string', 'max:2000'],
            'rules_acknowledged' => ['nullable', 'boolean'], 'records' => ['nullable', 'array', 'max:200'], 'records.*' => ['uuid'], 'iris' => ['nullable', 'string', 'max:20000']]);
        try {
            $records = [...($data['records'] ?? []), ...collect(preg_split('/\R/', (string) ($data['iris'] ?? '')))->map(fn ($l) => trim($l))->filter()
                ->map(fn ($iri) => RecordResource::where(['institution_id' => $request->user()->institution_id, 'iri' => $iri])->value('id') ?? throw new InvalidArgumentException(__('No record has the IRI :iri.', ['iri' => $iri])))->all()];
            $booking = $this->bookings->book($request->user(), $data, $records);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return redirect()->route('research.bookings.show', $booking)->with('status', __('Your booking is sent; the reading room will confirm it.'));
    }

    public function booking(Request $request, ReadingRoomBooking $booking): View
    {
        $staff = $this->bookings->manages($request->user(), $booking);
        abort_unless($staff || $booking->researcher->user_id === $request->user()->id, 404);
        $booking->load('room.seats', 'room.equipment.condition', 'status', 'seat', 'researcher.user', 'materials.record', 'materials.status', 'materials.priority',
            'materials.messages.sender', 'materials.statusChanges.changedBy', 'materials.handoffs.handoffType', 'materials.handoffs.condition', 'equipmentBookings.equipment', 'equipmentBookings.status');

        return view('research.booking', ['booking' => $booking, 'staff' => $staff, 'lang' => Vocab::languages()]);
    }

    public function cancel(Request $request, ReadingRoomBooking $booking): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->bookings->cancel($request->user(), $booking, $data['reason'] ?? null), __('The booking is cancelled.'));
    }

    public function addRecord(Request $request, ReadingRoomBooking $booking): RedirectResponse
    {
        $data = $request->validate(['iri' => ['required', 'string', 'max:2048']]);
        $unit = RecordResource::where(['institution_id' => $request->user()->institution_id, 'iri' => trim($data['iri'])])->whereNull('deleted_at')->first();

        return $this->attempt(fn () => $unit ? $this->bookings->addRecord($request->user(), $booking, $unit) : throw new InvalidArgumentException(__('No record has that IRI.')), __('The record is asked for.'));
    }

    /** A message about a request: from the researcher, or from staff (who may keep it internal). */
    public function write(Request $request, MaterialRequest $material): RedirectResponse
    {
        $staff = $this->bookings->manages($request->user(), $material->booking);
        abort_unless($staff || $material->booking->researcher->user_id === $request->user()->id, 404);
        $data = $request->validate(['subject' => ['nullable', 'string', 'max:255'], 'body' => ['required', 'string', 'max:10000'], 'is_internal' => ['nullable', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:'.ResearchFiles::MAX_KILOBYTES]]);

        return $this->attempt(fn () => $this->retrieval->write($request->user(), $material, $data, $staff, $request->file('attachment')), __('The message is sent.'));
    }

    public function bookEquipment(Request $request, ReadingRoomEquipment $equipment): RedirectResponse
    {
        $data = $request->validate(['booking_date' => ['required', 'date_format:Y-m-d'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'],
            'purpose' => ['nullable', 'string', 'max:1000'], 'booking_id' => ['nullable', 'uuid']]);

        return $this->attempt(fn () => $this->bookings->bookEquipment($request->user(), $equipment, $data), __('The equipment is booked.'));
    }

    /** A file of research services, to its owner or to reading room staff. */
    public function file(Request $request, ResearchFile $file): Response
    {
        $user = $request->user();
        abort_unless($file->institution_id === $user->institution_id && ($file->uploaded_by_id === $user->id || $user->hasPermission(Vocab::permission('manage-reading-room'))
            || MaterialRequestMessage::where('attachment_file_id', $file->id)->where('is_internal', false)->whereHas('request', fn ($q) => $q->whereHas('booking.researcher', fn ($r) => $r->where('user_id', $user->id)))->exists()), 404);

        return app(ResearchFiles::class)->respond($file, $request->boolean('download'));
    }

    private function validated(Request $request): array
    {
        return $request->validate(['title' => ['nullable', 'string', 'max:50'], 'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'], 'affiliation' => ['required', 'string', 'max:64'], 'institution' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'], 'position' => ['nullable', 'string', 'max:255'], 'student_id' => ['nullable', 'string', 'max:100'],
            'research_interests' => ['nullable', 'string', 'max:5000'], 'current_project' => ['nullable', 'string', 'max:5000'], 'orcid_id' => ['nullable', 'string', 'max:19'],
            'researcher_id_wos' => ['nullable', 'string', 'max:100'], 'scopus_id' => ['nullable', 'string', 'max:100'], 'isni' => ['nullable', 'string', 'max:19'],
            'researcher_type_id' => ['nullable', 'uuid'], 'id_type' => ['nullable', 'string', 'max:64'], 'id_number' => ['nullable', 'string', 'max:100'],
            'photo' => ['nullable', 'image', 'max:5120']]);
    }

    private function attempt(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['research' => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }
}
