<?php

// OpenRiC Workspace - the catalogue's assistant (roadmap phase 17f), after Heratio's ahg-ai-chatbot ChatbotController:
// a conversation in the public catalogue, where the institution offers it, and one for staff; a question asked by an
// ordinary form, so the page works without JavaScript and with a screen reader; an answer flagged as wrong; a new
// conversation; and the review of flagged answers. A visitor's conversation is theirs by a token in their session.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Ai\AiUnavailable;
use App\Ai\Chatbot;
use App\Ai\Compliance;
use App\Ai\Gateway;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Support\HostInstitution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function __construct(private Chatbot $chatbot, private Gateway $gateway) {}

    public function show(Request $request): View
    {
        [$institution, $public] = $this->where($request);
        $conversation = $this->chatbot->conversation($institution, $public ? null : $request->user(), $public, $this->token($request, $public));

        return view($public ? 'chat.public' : 'chat.staff', ['conversation' => $conversation, 'institution' => $institution, 'public' => $public,
            'messages' => $conversation->messages()->with('role')->orderBy('created_at')->get()]);
    }

    public function ask(Request $request): RedirectResponse
    {
        [$institution, $public] = $this->where($request);
        $data = $request->validate(['question' => ['required', 'string', 'max:2000']]);
        $conversation = $this->chatbot->conversation($institution, $public ? null : $request->user(), $public, $this->token($request, $public));
        try {
            $this->chatbot->ask($institution, $conversation, $request->user(), $data['question']);
        } catch (AiUnavailable $e) {
            return back()->withInput()->withErrors(['question' => $e->getMessage()]);
        }

        return redirect()->route($public ? 'discover.ask' : 'ask')->with('asked', true);
    }

    public function flag(Request $request, ChatMessage $message): RedirectResponse
    {
        [$institution, $public] = $this->where($request);
        $conversation = $this->chatbot->conversation($institution, $public ? null : $request->user(), $public, $this->token($request, $public));
        abort_unless($message->conversation_id === $conversation->id && $message->role->notation === 'assistant', 404);
        $this->chatbot->flag($message, $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null);

        return redirect()->route($public ? 'discover.ask' : 'ask')->with('status', __('Thank you: the answer is flagged for the archive\'s staff to review.'));
    }

    /** A new conversation: the one in hand is left to its retention. */
    public function restart(Request $request): RedirectResponse
    {
        [, $public] = $this->where($request);
        if ($public) {
            $request->session()->forget('chat_token');
        } else {
            ChatConversation::where(['user_id' => $request->user()->id, 'public' => false])->update(['user_id' => null]);
        }

        return redirect()->route($public ? 'discover.ask' : 'ask');
    }

    /** The answers readers flagged, for who may review proposals (Heratio's chatbot review queue). */
    public function flagged(Request $request): View
    {
        abort_unless($request->user()->mayAnywhere('review-proposals', 'description'), 403);

        return view('chat.flagged', ['messages' => ChatMessage::whereNotNull('flagged_at')
            ->whereHas('conversation', fn ($q) => $q->where('institution_id', $request->user()->institution_id))
            ->with('review.labels', 'conversation')->orderByRaw('reviewed_at IS NOT NULL')->orderByDesc('flagged_at')->limit(200)->get()]);
    }

    public function review(Request $request, ChatMessage $message): RedirectResponse
    {
        abort_unless($request->user()->mayAnywhere('review-proposals', 'description'), 403);
        abort_unless($message->conversation->institution_id === $request->user()->institution_id && $message->flagged_at !== null, 404);
        $this->chatbot->review($message, $request->validate(['finding' => ['required', 'in:right,wrong']])['finding'], $request->user());

        return back()->with('status', __('Saved.'));
    }

    /** The institution and whether this is the public catalogue's assistant; neither exists where it is not offered. */
    private function where(Request $request): array
    {
        $public = $request->routeIs('discover.ask*');
        $institution = $public ? (HostInstitution::for($request) ?? abort(404)) : $request->user()->institution;
        abort_unless($this->gateway->available($institution->id) && ! app(Compliance::class)->halted($institution->id, 'chatbot'), 404);
        abort_if($public && ! $this->gateway->settings($institution->id)->chatbot_public, 404);
        abort_if(! $public && ! $request->user()->mayAnywhere('read', 'description'), 403);

        return [$institution, $public];
    }

    /** A visitor's token, kept in their session; staff need none. */
    private function token(Request $request, bool $public): ?string
    {
        if (! $public) {
            return null;
        }
        if (! $request->session()->has('chat_token')) {
            $request->session()->put('chat_token', Str::random(48));
        }

        return (string) $request->session()->get('chat_token');
    }
}
