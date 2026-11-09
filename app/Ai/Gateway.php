<?php

// OpenRiC Workspace - the client of the AHG AI gateway (roadmap phase 17a), after Heratio's ahg-ai-services clients
// (HtrService, LlmService, VisionOcrService), which each carried the gateway's Bearer token: the one door through which
// the Workspace asks a model anything. It refuses unless the installation has a gateway key and the institution has
// switched AI on and is under its monthly limit, and it records every call it makes, answered or not, in the chained
// log of inferences. A model's answer is never written to a description here: what it says becomes a proposal.
// Changed from Heratio: one client for every service rather than one a service; a node address is refused, never used.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Ai;

use App\Models\AiInference;
use App\Models\AiSetting;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class Gateway
{
    public function __construct(private Inferences $inferences) {}

    /** Has the installation a gateway key? */
    public function configured(): bool
    {
        return (string) config('workspace.ai.gateway_key') !== '';
    }

    public function settings(string $institutionId): AiSetting
    {
        return AiSetting::firstOrNew(['institution_id' => $institutionId]);
    }

    /** Why the institution may not ask a model now (for this service, when one is named), or null when it may. */
    public function refusal(string $institutionId, ?string $service = null): ?string
    {
        $settings = $this->settings($institutionId);

        return match (true) {
            ! $this->configured() => __('AI is not set up: the installation has no AI gateway key.'),
            ! $settings->enabled => __('AI is switched off for this institution.'),
            // A system the institution halted in its AI register (phase 17f, EU AI Act article 14) is never called.
            $service !== null && app(Compliance::class)->halted($institutionId, $service) => __('This AI system is halted in the institution\'s AI register.'),
            $settings->monthly_limit !== null && $this->inferences->thisMonth($institutionId) >= $settings->monthly_limit => __('This month\'s limit of :n AI calls is reached.', ['n' => $settings->monthly_limit]),
            default => null,
        };
    }

    public function available(string $institutionId): bool
    {
        return $this->refusal($institutionId) === null;
    }

    /**
     * Ask the gateway, and record the call. $path is under the gateway's /ai/v1 (such as "ollama/api/chat"); $body is
     * JSON, or form fields when $file (a path) is given; $trace: subject (a model), field, user, input (what is hashed
     * as the input), input_excerpt, model (asked for). $read turns the answer into what is recorded: output (text),
     * confidence, model, model_version, details.
     *
     * @param  callable(array): array{output: string, confidence?: ?float, model?: string, model_version?: string, details?: array}  $read
     * @return array{answer: array, read: array, inference: AiInference}
     *
     * @throws AiUnavailable when AI is off, over its limit, or the gateway did not answer
     */
    public function call(Institution $institution, string $service, string $path, array $body, array $trace, callable $read, ?string $file = null): array
    {
        if ($reason = $this->refusal($institution->id, $service)) {
            throw new AiUnavailable($reason);
        }
        $endpoint = (string) config('workspace.ai.gateway_url').'/'.ltrim($path, '/');
        $started = microtime(true);
        $record = fn (array $result) => $this->inferences->record([
            'institution_id' => $institution->id, 'service' => $service, 'endpoint' => $endpoint, 'subject' => $trace['subject'] ?? null, 'field' => $trace['field'] ?? null,
            'user_id' => ($trace['user'] ?? null) instanceof User ? $trace['user']->id : null, 'input' => (string) ($trace['input'] ?? json_encode($body)),
            'input_excerpt' => $trace['input_excerpt'] ?? null, 'model' => $result['model'] ?? ($trace['model'] ?? $body['model'] ?? 'unknown'),
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)] + $result);
        try {
            $request = $this->http();
            $response = $file !== null ? $request->attach('file', fopen($file, 'r'), basename($file))->post($endpoint, $body) : $request->post($endpoint, $body);
        } catch (ConnectionException $e) {
            $record(['succeeded' => false, 'error' => $e->getMessage()]);
            throw new AiUnavailable(__('The AI gateway did not answer.'));
        }
        if (! $response->successful() || ! is_array($response->json())) {
            $record(['succeeded' => false, 'error' => 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500)]);
            throw new AiUnavailable(__('The AI gateway refused the request (:status).', ['status' => $response->status()]));
        }
        $answer = $response->json();
        try {
            $result = $read($answer);
        } catch (Throwable $e) {
            $record(['succeeded' => false, 'error' => 'unreadable answer: '.$e->getMessage(), 'output' => $response->body()]);
            throw new AiUnavailable(__('The AI gateway gave an answer the Workspace could not read.'));
        }

        return ['answer' => $answer, 'read' => $result, 'inference' => $record(['succeeded' => true] + $result)];
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('workspace.ai.gateway_key'))->acceptJson()->timeout((int) config('workspace.ai.timeout'));
    }
}
