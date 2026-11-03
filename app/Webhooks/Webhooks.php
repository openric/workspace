<?php

// OpenRiC Workspace - webhooks: another system told when the institution's entities are created, changed, deleted,
// published or withdrawn (roadmap phase 22c, ADR 0020).
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// After Heratio's ahg-api WebhookService (trigger, deliver, the HMAC-SHA256 signature, retries after 1, 2, 4, 8 and 16
// minutes, a webhook left alone after five failures). Changed: a delivery says what happened to which entity, by its
// persistent IRI and its API address, and nothing of what the entity says, so the receiver reads it through the API
// with its own key, and clearance, embargoes and redaction hold. An entity is marked as the search index and versions
// mark it, and each webhook is told once for each entity and event when the request, command or job ends; a publish
// is told as published or withdrawn, not also as changed. A webhook posts only to a public address: never to this
// server or a private network, checked when it is saved and again at each delivery.

namespace App\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Concept;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Webhooks
{
    /** The kinds a webhook may follow: table => the name a receiver sees, and the API address it reads them at. */
    public const KINDS = ['record_resources' => 'records', 'agents' => 'agents', 'repositories' => 'repositories', 'places' => 'places', 'activities' => 'activities'];

    /** Seconds before each retry (Heratio's delays). */
    public const RETRIES = [60, 120, 240, 480, 960];

    /** @var array<string, array<string, true>> "table:id" => events */
    private static array $pending = [];

    public static function mark(string $event, ?string $table, ?string $id): void
    {
        if ($table !== null && $id !== null && isset(self::KINDS[$table])) {
            self::$pending["$table:$id"][$event] = true;
        }
    }

    /** Tell each webhook what happened in this request, once for each entity and event. */
    public function flush(): void
    {
        $pending = self::$pending;
        self::$pending = [];
        foreach ($pending as $key => $events) {
            [$table, $id] = explode(':', $key, 2);
            if (isset($events['created']) || isset($events['deleted'])) {
                unset($events['updated']);
            }
            if (isset($events['published']) || isset($events['withdrawn'])) {
                unset($events['updated']);
            }
            $entity = DB::table($table)->where('id', $id)->first(['id', 'iri', 'institution_id']);
            if ($entity === null) {
                continue;
            }
            $hooks = Webhook::where(['institution_id' => $entity->institution_id, 'is_active' => true])->where('failure_count', '<', 5)->get()
                ->filter(fn ($w) => in_array(self::KINDS[$table], $w->entity_kinds, true));
            foreach (array_keys($events) as $event) {
                foreach ($hooks->filter(fn ($w) => in_array($event, $w->events, true)) as $hook) {
                    $this->queue($hook, $event, self::KINDS[$table], $entity);
                }
            }
        }
    }

    private function queue(Webhook $hook, string $event, string $kind, object $entity): void
    {
        $delivery = WebhookDelivery::create(['webhook_id' => $hook->id, 'event_id' => $this->concept('webhook-event', $event), 'entity_kind' => $kind, 'entity_id' => $entity->id,
            'payload' => ['event' => $event, 'kind' => $kind, 'id' => $entity->id, 'iri' => $entity->iri, 'api' => url('/api/ric/v1/'.$kind.'/'.$entity->id), 'occurred_at' => now()->toIso8601String()],
            'status_id' => $this->concept('webhook-delivery-status', 'pending'), 'created_at' => now()]);
        $hook->update(['last_triggered_at' => now()]);
        DeliverWebhook::dispatch($delivery->id);
    }

    /** Send one delivery: signed, and on failure tried again later, up to five times (Heratio's deliver). */
    public function deliver(WebhookDelivery $delivery): bool
    {
        $hook = $delivery->webhook;
        if (! $hook->is_active) {
            return false;
        }
        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $attempts = $delivery->attempt_count + 1;
        [$code, $answer] = [null, null];
        try {
            self::publicUrl($hook->url) || throw new InvalidArgumentException(__('The address is not a public one.'));
            $response = Http::timeout(10)->withoutRedirecting()->withBody($body, 'application/json')->withHeaders([
                'User-Agent' => 'OpenRiC-Workspace-Webhook', 'X-OpenRiC-Event' => $delivery->payload['event'], 'X-OpenRiC-Delivery' => $delivery->id,
                'X-OpenRiC-Signature' => 'sha256='.hash_hmac('sha256', $body, $hook->secret)])->post($hook->url);
            [$code, $answer] = [$response->status(), mb_substr($response->body(), 0, 2000)];
        } catch (\Throwable $e) {
            $answer = mb_substr($e->getMessage(), 0, 2000);
        }
        $ok = $code !== null && $code >= 200 && $code < 300;
        $last = $attempts >= count(self::RETRIES);
        $delivery->update(['attempt_count' => $attempts, 'response_code' => $code, 'response_body' => $answer,
            'status_id' => $this->concept('webhook-delivery-status', $ok ? 'delivered' : ($last ? 'failed' : 'retrying')),
            'next_retry_at' => $ok || $last ? null : now()->addSeconds(self::RETRIES[$attempts - 1]), 'delivered_at' => $ok ? now() : null]);
        $hook->update(['failure_count' => $ok ? 0 : ($last ? $hook->failure_count + 1 : $hook->failure_count)]);

        return $ok;
    }

    /** The deliveries due to be tried again (Heratio's processRetries). @return int how many */
    public function retryDue(): int
    {
        $due = WebhookDelivery::whereHas('status', fn ($q) => $q->where('iri', Vocab::BASE.'webhook-delivery-status/retrying'))->where('next_retry_at', '<=', now())->limit(200)->get();
        $due->each(fn ($d) => DeliverWebhook::dispatch($d->id));

        return $due->count();
    }

    public function save(string $institutionId, ?string $userId, array $data, ?Webhook $hook = null): array
    {
        $name = trim((string) ($data['name'] ?? '')) ?: throw new InvalidArgumentException(__('Name the webhook.'));
        $url = trim((string) ($data['url'] ?? ''));
        self::publicUrl($url) || throw new InvalidArgumentException(__('Give a public http or https address; a webhook never posts to this server or a private network.'));
        $events = array_values(array_intersect((array) ($data['events'] ?? []), Concept::choices(Vocab::BASE.'webhook-event/')->pluck('notation')->all()));
        $kinds = array_values(array_intersect((array) ($data['entity_kinds'] ?? []), self::KINDS));
        ($events !== [] && $kinds !== []) || throw new InvalidArgumentException(__('Choose at least one event and one kind of entity.'));
        $secret = $hook && empty($data['new_secret']) ? null : Str::random(64);
        $fields = ['name' => $name, 'url' => $url, 'events' => $events, 'entity_kinds' => $kinds, 'is_active' => ! array_key_exists('is_active', $data) || ! empty($data['is_active'])]
            + ($secret ? ['secret' => $secret] : []) + (! empty($data['is_active']) ? ['failure_count' => 0] : []);
        $hook = $hook ? tap($hook)->update($fields) : Webhook::create($fields + ['institution_id' => $institutionId, 'created_by_id' => $userId]);

        return [$hook, $secret];
    }

    /** An http(s) address whose host resolves only to public addresses (no loopback, private, link-local or reserved). */
    public static function publicUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user'])) {
            return false;
        }
        if (! config('workspace.webhooks_check_hosts', true)) {
            return true;
        }
        $host = trim($parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [...(gethostbynamel($host) ?: []), ...array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')];

        return $addresses !== [] && array_filter($addresses, fn ($ip) => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) === [];
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE.$vocabulary.'/'.$notation)->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
