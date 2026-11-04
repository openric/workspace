<?php

/**
 * ArchivematicaDashboardClient and ArchivematicaSsClient - Heratio
 *
 * Copyright (C) 2026 Johan Pieterse
 * Plain Sailing Information Systems
 * Email: johan@plainsailingisystems.co.za
 *
 * This file is part of Heratio.
 *
 * Heratio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenRiC Workspace - ported from Heratio, packages/ahg-archivematica/src/Services/ArchivematicaDashboardClient.php
 * and ArchivematicaSsClient.php at commit 9e3100a6a (governance/CODE_PROVENANCE.md, roadmap phase 15d, ADR 0022):
 * the Dashboard's start_transfer (paths[] each base64 of the transfer source location UUID and the path, sent as
 * repeated form keys), approve, unapproved, transfer and ingest status; the Storage Service's package list, paged
 * by limit and offset, one package, and its download; both with Archivematica's "ApiKey user:key" header. Changed:
 * one class for both, read from the installation's Archivematica settings, not config; JSON pages followed by the
 * Storage Service's meta.next only, so a page that repeats cannot loop.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Preservation\Archivematica;

use App\Models\ArchivematicaSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Client
{
    public function __construct(private ?ArchivematicaSettings $settings = null) {}

    public function settings(): ArchivematicaSettings
    {
        return $this->settings ??= ArchivematicaSettings::current();
    }

    /**
     * Start a transfer of the folder at $path in the transfer source. The transfer's UUID comes only with its approval.
     *
     * @return array<string, mixed> such as {message, path}
     */
    public function startTransfer(string $name, string $type, string $path, ?string $accession = null): array
    {
        $location = (string) $this->settings()->transfer_source_location_uuid;
        $location !== '' || throw new RuntimeException(__('The Archivematica transfer source location UUID is not set (from the Storage Service\'s Locations page).'));
        $fields = ['name='.rawurlencode($name), 'type='.rawurlencode($type), 'paths[]='.rawurlencode(base64_encode($location.':'.$path)), 'row_ids[]='];
        if ($accession !== null && $accession !== '') {
            $fields[] = 'accession='.rawurlencode($accession);
        }
        $response = $this->send('dashboard', fn (PendingRequest $http, string $base) => $http->withBody(implode('&', $fields), 'application/x-www-form-urlencoded')
            ->post($base.'/api/transfer/start_transfer/'));

        return $response;
    }

    /** @return array<string, mixed> such as {message, uuid} */
    public function approveTransfer(string $type, string $directory): array
    {
        return $this->send('dashboard', fn (PendingRequest $http, string $base) => $http->asForm()->post($base.'/api/transfer/approve/', ['type' => $type, 'directory' => $directory]));
    }

    /** @return list<array<string, mixed>> the transfers waiting to be approved, each {type, directory, uuid} */
    public function unapproved(): array
    {
        $results = $this->send('dashboard', fn (PendingRequest $http, string $base) => $http->get($base.'/api/transfer/unapproved/'))['results'] ?? [];

        return is_array($results) ? array_values($results) : [];
    }

    /** @return array<string, mixed> such as {status, name, microservice, uuid, sip_uuid} */
    public function transferStatus(string $uuid): array
    {
        return $this->send('dashboard', fn (PendingRequest $http, string $base) => $http->get($base.'/api/transfer/status/'.rawurlencode($uuid).'/'));
    }

    /** @return array<string, mixed> such as {status, name, microservice, uuid} */
    public function ingestStatus(string $uuid): array
    {
        return $this->send('dashboard', fn (PendingRequest $http, string $base) => $http->get($base.'/api/ingest/status/'.rawurlencode($uuid).'/'));
    }

    /** @return list<array<string, mixed>> the Storage Service's packages of a type (DIP, AIP), every page */
    public function packages(string $type = 'DIP', array $filters = [], int $limit = 100): array
    {
        $objects = [];
        for ($offset = 0, $pages = 0; $pages < 1000; $offset += $limit, $pages++) {
            $body = $this->send('storage', fn (PendingRequest $http, string $base) => $http->get($base.'/api/v2/file/', [...$filters, 'package_type' => $type, 'limit' => $limit, 'offset' => $offset]));
            array_push($objects, ...array_values(is_array($body['objects'] ?? null) ? $body['objects'] : []));
            if (empty($body['meta']['next'])) {
                break;
            }
        }

        return $objects;
    }

    /** @return array<string, mixed> */
    public function package(string $uuid): array
    {
        return $this->send('storage', fn (PendingRequest $http, string $base) => $http->get($base.'/api/v2/file/'.rawurlencode($uuid).'/'));
    }

    /** Download a package to $destination; the path written. */
    public function download(string $uuid, string $destination): string
    {
        [$http, $base] = $this->http('storage');
        try {
            $response = $http->timeout(3600)->sink($destination)->get($base.'/api/v2/file/'.rawurlencode($uuid).'/download/');
        } catch (\Throwable $e) {
            throw new RuntimeException(__('Downloading package :uuid from the Storage Service failed: :error', ['uuid' => $uuid, 'error' => $e->getMessage()]), 0, $e);
        }
        if (! $response->successful() || ! is_file($destination) || filesize($destination) === 0) {
            @unlink($destination);
            throw new RuntimeException(__('Downloading package :uuid from the Storage Service failed: :error', ['uuid' => $uuid, 'error' => 'HTTP '.$response->status()]));
        }

        return $destination;
    }

    /** @param  'dashboard'|'storage'  $service */
    private function send(string $service, callable $call): array
    {
        [$http, $base] = $this->http($service);
        try {
            $response = $call($http, $base);
        } catch (\Throwable $e) {
            throw new RuntimeException(__('Archivematica could not be reached: :error', ['error' => $e->getMessage()]), 0, $e);
        }
        $response->successful() || throw new RuntimeException(__('Archivematica answered HTTP :status: :body', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]));

        return is_array($decoded = $response->json()) ? $decoded : [];
    }

    /** @return array{0: PendingRequest, 1: string} */
    private function http(string $service): array
    {
        $s = $this->settings();
        [$url, $user, $key] = $service === 'dashboard' ? [$s->dashboard_url, $s->dashboard_username, $s->dashboard_api_key] : [$s->storage_url, $s->storage_username, $s->storage_api_key];
        ($url && $user && $key) || throw new RuntimeException($service === 'dashboard' ? __('The Archivematica Dashboard is not set up (its address, user and API key).')
            : __('The Archivematica Storage Service is not set up (its address, user and API key).'));

        return [Http::withHeaders(['Authorization' => 'ApiKey '.$user.':'.$key, 'Accept' => 'application/json'])->timeout(max(5, $s->timeout_seconds)), rtrim($url, '/')];
    }
}
