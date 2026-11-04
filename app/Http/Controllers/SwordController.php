<?php

// OpenRiC Workspace - SWORD 1.3 deposit (roadmap phase 15d, ADR 0022), as AtoM's qtSwordPlugin answers it, so that
// Archivematica's "Upload DIP to AtoM" can deposit a DIP here: a service document listing the top-level units (or a
// unit's parts) as collections, and a deposit to a unit of a METSArchivematicaDIP package, sent as a ZIP body or named
// by Content-Location in the folder the installation allows, taken in in the background and answered 202 with an Atom
// entry. Heratio has no SWORD twin; this is written fresh against the DIP ingest.
// Changed from AtoM: an account signs in with HTTP Basic as its e-mail address and an API key of its with the write
// scope, never its password, so two-factor sign-in is not passed by; and a Content-MD5 that does not match is refused
// before anything is taken in.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers;

use App\Accounts\ApiKeyService;
use App\Jobs\TakeInDeposit;
use App\Models\ArchivematicaSettings;
use App\Models\RecordResource;
use App\Models\User;
use App\Relations\EntityKinds;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class SwordController extends Controller
{
    public const PACKAGING = 'http://purl.org/net/sword-types/METSArchivematicaDIP';

    public function __construct(private ApiKeyService $keys) {}

    public function serviceDocument(Request $request, ?RecordResource $record = null): Response
    {
        if (! $user = $this->user($request)) {
            return $this->challenge();
        }
        if ($record && ($record->institution_id !== $user->institution_id || $record->deleted_at !== null || ! $user->may('read', 'description', $record))) {
            return $this->error(404, 'ErrorBadRequest', __('Not found'));
        }
        $units = RecordResource::where('institution_id', $user->institution_id)->whereNull('deleted_at')->where('parent_id', $record?->id)
            ->orderBy('position')->orderBy('id')->limit(500)->get()->filter(fn ($u) => $user->may('read', 'description', $u));
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $collections = $units->map(fn (RecordResource $u) => '  <collection href="'.$e(route('sword.deposit', $u)).'">'."\n"
            .'    <atom:title type="text">'.$e(EntityKinds::label($u))."</atom:title>\n    <accept>application/zip</accept>\n    <sword:mediation>false</sword:mediation>\n"
            .'    <sword:acceptPackaging q="1.0">'.self::PACKAGING."</sword:acceptPackaging>\n"
            .(RecordResource::where('parent_id', $u->id)->whereNull('deleted_at')->exists() ? '    <sword:service>'.$e(route('sword.service', $u))."</sword:service>\n" : '')
            ."  </collection>\n")->join('');
        $kilobytes = (int) (config('workspace.max_upload_megabytes') * 1024);

        return $this->atom(200, '<service xmlns="http://www.w3.org/2007/app" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:sword="http://purl.org/net/sword/" xmlns:dcterms="http://purl.org/dc/terms/">'."\n"
            ."<sword:version>1.3</sword:version>\n<sword:verbose>false</sword:verbose>\n<sword:noOp>false</sword:noOp>\n<sword:maxUploadSize>$kilobytes</sword:maxUploadSize>\n"
            .'<workspace>'."\n".'  <atom:title type="text">'.$e($record ? EntityKinds::label($record) : $user->institution->name)."</atom:title>\n".$collections."</workspace>\n</service>");
    }

    public function deposit(Request $request, RecordResource $record): Response
    {
        if ($request->isMethod('put') || $request->isMethod('delete')) {
            return $this->error(501, 'ErrorNotImplemented', __('Not implemented'));
        }
        if (! $user = $this->user($request)) {
            return $this->challenge();
        }
        if ($record->institution_id !== $user->institution_id || $record->deleted_at !== null) {
            return $this->error(404, 'ErrorBadRequest', __('Not found'));
        }
        if (! $user->may('create', 'description', $record)) {
            return $this->error(403, 'TargetOwnerUnknown', __('Forbidden'));
        }
        if ($request->header('X-Packaging') !== self::PACKAGING) {
            return $this->error(415, 'ErrorContent', __('The supplied format is not supported by this server'));
        }
        $id = (string) Str::uuid7();
        if ($location = $request->header('Content-Location')) {
            // Archivematica copies the DIP to this server and names it; only a folder the installation allows is read.
            $allowed = realpath((string) ArchivematicaSettings::current()->deposit_path) ?: null;
            $path = realpath(preg_replace('#^file://#', '', $location));
            if ($allowed === null || $path === false || ! str_starts_with($path.'/', rtrim($allowed, '/').'/')) {
                return $this->error(400, 'ErrorBadRequest', __('The package is not in the folder this server takes deposits from.'));
            }
            $source = ['path' => $path];
        } else {
            if (! in_array(strtok((string) $request->header('Content-Type'), ';'), ['application/zip'], true)) {
                return $this->error(415, 'ErrorContent', __('The supplied content type is not supported by this server'));
            }
            $content = $request->getContent();
            if (strlen($content) > config('workspace.max_upload_megabytes') * 1024 * 1024) {
                return $this->error(413, 'ErrorMaxUploadSizeExceeded', __('The package is larger than this server takes.'));
            }
            // Content-MD5 as RFC 1864 gives it (base64), or as hexadecimal, as some clients send it.
            if (($md5 = $request->header('Content-MD5')) && ! in_array($md5, [base64_encode(md5($content, true)), md5($content)], true) && strtolower($md5) !== md5($content)) {
                return $this->error(412, 'ErrorChecksumMismatch', __('The package\'s MD5 is not the one sent with it.'));
            }
            is_dir(storage_path('app/sword')) || mkdir(storage_path('app/sword'), 0750, true);
            file_put_contents($kept = storage_path("app/sword/$id.zip"), $content);
            $source = ['archive' => $kept];
        }
        TakeInDeposit::dispatch($id, $record->id, $user->id, $source);

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return $this->atom(202, '<entry xmlns="http://www.w3.org/2005/Atom" xmlns:sword="http://purl.org/net/sword/">'."\n"
            .'  <title>'.$e(EntityKinds::label($record))."</title>\n  <id>urn:uuid:$id</id>\n  <updated>".now()->toAtomString()."</updated>\n"
            .'  <author><name>'.$e($user->name)."</name></author>\n"
            .'  <generator uri="'.$e(url('/')).'" version="'.$e(trim((string) @file_get_contents(base_path('VERSION')))).'">OpenRiC Workspace</generator>'."\n"
            .'  <content type="text/html" src="'.$e(route('records.show', $record)).'"/>'."\n"
            ."  <sword:noOp>false</sword:noOp>\n  <sword:packaging>".self::PACKAGING."</sword:packaging>\n"
            .'  <sword:userAgent>'.$e($request->userAgent())."</sword:userAgent>\n</entry>", ['Location' => route('records.show', $record)]);
    }

    /** The account of HTTP Basic sign-in: its e-mail address and an API key of its with the write scope. */
    private function user(Request $request): ?User
    {
        [$email, $secret] = [(string) $request->getUser(), (string) $request->getPassword()];
        if ($email === '' || $secret === '' || ! ($key = $this->keys->resolve($secret)) || ! $key->user || mb_strtolower($key->user->email) !== mb_strtolower($email)) {
            return null;
        }
        if (! in_array('write', $this->keys->effectiveScopes($key), true) || $key->user->isDisabled()) {
            return null;
        }
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $key->user;
    }

    private function challenge(): Response
    {
        return response(__('Sign in with your e-mail address and an API key with the write scope.'), 401, ['WWW-Authenticate' => 'Basic realm="SWORD"']);
    }

    private function error(int $status, string $kind, string $summary): Response
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return $this->atom($status, '<sword:error href="http://purl.org/net/sword/error/'.$kind.'" xmlns="http://www.w3.org/2005/Atom" xmlns:sword="http://purl.org/net/sword/">'."\n"
            .'  <summary type="text">'.$e($summary)."</summary>\n  <title type=\"text\">ERROR</title>\n  <updated>".now()->toAtomString()."</updated>\n"
            .'  <generator uri="'.$e(url('/')).'">OpenRiC Workspace</generator>'."\n</sword:error>");
    }

    private function atom(int $status, string $body, array $headers = []): Response
    {
        return response('<?xml version="1.0" encoding="UTF-8"?>'."\n".$body."\n", $status, ['Content-Type' => 'application/atom+xml; charset="utf-8"', ...$headers]);
    }
}
