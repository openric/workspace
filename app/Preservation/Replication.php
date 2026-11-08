<?php

// OpenRiC Workspace - replication of the object store (roadmap phase 15c, ADR 0022), after Heratio's ahg-preservation
// replication targets and log: the OCFL storage root copied whole to a folder on this server or on another reached by
// rsync over SSH, on the target's schedule or at once, and compared with it on request. A copy is the storage root as
// it stands, objects purged by a disposal included, so a destroyed file does not outlive its destruction in a copy.
// Each file first copied to a target is a PREMIS replication event. A target folder must be empty or hold an OCFL
// storage root already, so a target set to the wrong folder is refused rather than emptied.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Preservation;

use App\Files\FileStore;
use App\Models\Concept;
use App\Models\DigitalFile;
use App\Models\ReplicationRun;
use App\Models\ReplicationTarget;
use App\Models\User;
use App\Support\LimitedProcess;
use App\Support\Vocab;
use InvalidArgumentException;
use Throwable;

class Replication
{
    /** What a host, user name, folder and key file may be: nothing a shell or rsync would read as an option or a command. */
    private const SAFE = ['host' => '/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', 'username' => '/^[a-z_][a-z0-9_.-]{0,31}$/i', 'path' => '#^/[A-Za-z0-9_./-]+$#',
        'ssh_key_path' => '#^/[A-Za-z0-9_./-]+$#'];

    public function __construct(private FileStore $store, private PreservationEvents $events) {}

    public function save(array $data, ?ReplicationTarget $target = null): ReplicationTarget
    {
        $type = (string) ($data['type'] ?? '');
        in_array($type, ['local', 'rsync'], true) || throw new InvalidArgumentException(__(':value is not in the list', ['value' => $type]));
        $fields = ['name' => trim((string) ($data['name'] ?? '')), 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'path' => rtrim(trim((string) ($data['path'] ?? '')), '/'),
            'host' => $type === 'rsync' ? trim((string) ($data['host'] ?? '')) : null, 'username' => $type === 'rsync' ? (trim((string) ($data['username'] ?? '')) ?: null) : null,
            'port' => $type === 'rsync' && ($data['port'] ?? '') !== '' ? (int) $data['port'] : null,
            'ssh_key_path' => $type === 'rsync' ? (trim((string) ($data['ssh_key_path'] ?? '')) ?: null) : null, 'is_active' => (bool) ($data['is_active'] ?? true)];
        $fields['name'] !== '' || throw new InvalidArgumentException(__('Give the target a name.'));
        foreach (self::SAFE as $field => $pattern) {
            ($fields[$field] === null || preg_match($pattern, $fields[$field])) || throw new InvalidArgumentException(__('The :field is not one this can use.', ['field' => ['host' => __('server'), 'username' => __('user name'), 'path' => __('folder'), 'ssh_key_path' => __('SSH key file')][$field]]));
        }
        ($type === 'local' || $fields['host'] !== null) || throw new InvalidArgumentException(__('Give the server to copy to.'));
        ($fields['port'] === null || ($fields['port'] > 0 && $fields['port'] < 65536)) || throw new InvalidArgumentException(__('A port is a number from 1 to 65535.'));
        if ($type === 'local') {
            $root = realpath($this->store->root()) ?: $this->store->root();
            $path = $fields['path'];
            (! str_starts_with($path.'/', $root.'/') && ! str_starts_with($root.'/', $path.'/')) || throw new InvalidArgumentException(__('A copy is kept outside the object store, and the object store outside it.'));
        }
        $frequency = (string) ($data['frequency'] ?? 'daily');
        $fields += ['type_id' => $this->concept('replication-target-type', $type), 'frequency_id' => $this->concept('fixity-frequency', $frequency)];

        return $target ? tap($target)->update($fields) : ReplicationTarget::create([...$fields, 'next_sync_at' => now()]);
    }

    /** Bring the copy up to date (sync), or compare it with the object store (verify). */
    public function run(ReplicationTarget $target, string $operation = 'sync', ?User $actor = null): ReplicationRun
    {
        $run = ReplicationRun::create(['target_id' => $target->id, 'operation_id' => $this->concept('replication-operation', $operation),
            'status_id' => $this->concept('replication-status', 'started'), 'started_at' => now(), 'user_id' => $actor?->id]);
        $started = microtime(true);
        try {
            $this->store->ocfl()->initialize();
            $this->assertTargetFolder($target);
            $process = LimitedProcess::make('rsync', $this->rsync($target, $operation === 'verify'
                ? ['--dry-run', '--checksum', '--delete', '--itemize-changes', '--stats']
                : ['--delete', '--itemize-changes', '--stats']));
            $process->setTimeout(86400)->run();
            $process->isSuccessful() || throw new \RuntimeException(trim(mb_substr($process->getErrorOutput() ?: 'rsync exit '.$process->getExitCode(), 0, 2000)));
            [$changed, $deleted, $stats] = $this->parse($process->getOutput());
            $copied = array_values(array_filter($changed, fn ($p) => ! str_ends_with($p, '/')));
            if ($operation === 'sync') {
                $this->recordEvents($target, $copied);
            }
            $differ = $operation === 'verify' ? count($copied) + count($deleted) : 0;
            $run->update(['status_id' => $this->concept('replication-status', $differ > 0 ? 'partial' : 'completed'), 'files_total' => $stats['files'],
                'files_synced' => $operation === 'sync' ? count($copied) : $stats['files'] - $differ, 'files_failed' => $differ, 'bytes_transferred' => $operation === 'sync' ? $stats['bytes'] : 0,
                'details' => ['changed' => array_slice($copied, 0, 200), 'deleted' => array_slice($deleted, 0, 200)]]);
        } catch (Throwable $e) {
            $run->update(['status_id' => $this->concept('replication-status', 'failed'), 'error_message' => mb_substr($e->getMessage(), 0, 2000)]);
        }
        $run->update(['completed_at' => now(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
        if ($operation === 'sync') {
            $target->update(['last_sync_at' => now(), 'last_status_id' => $run->status_id, 'last_sync_files' => $run->files_synced, 'last_sync_bytes' => $run->bytes_transferred,
                'next_sync_at' => match ($target->frequency->notation) {
                    'daily' => now()->addDay(), 'monthly' => now()->addMonth(), default => now()->addWeek()
                }]);
        }

        return $run->fresh();
    }

    /** Sync every active target whose time has come. */
    public function runDue(): int
    {
        $due = ReplicationTarget::where('is_active', true)->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))->get();
        $due->each(fn ($target) => $this->run($target));

        return $due->count();
    }

    /** The folder copied to is empty, does not exist yet, or holds an OCFL storage root; anything else is not overwritten. */
    private function assertTargetFolder(ReplicationTarget $target): void
    {
        $process = LimitedProcess::make('rsync', $this->rsync($target, ['--list-only', '--no-recursive', '--dirs'], true));
        $process->setTimeout(120)->run();
        if (! $process->isSuccessful()) {
            // rsync says 23 when the folder is not there yet: it will be made.
            $process->getExitCode() === 23 || throw new \RuntimeException(trim(mb_substr($process->getErrorOutput() ?: 'rsync exit '.$process->getExitCode(), 0, 2000)));

            return;
        }
        $names = array_values(array_filter(array_map(fn ($line) => preg_replace('/^\S+\s+\S+\s+\S+\s+\S+\s+/', '', $line), explode("\n", trim($process->getOutput()))), fn ($n) => ! in_array($n, ['', '.'], true)));
        ($names === [] || in_array('0=ocfl_1.1', $names, true))
            || throw new \RuntimeException(__('The folder :path holds files that are not an OCFL storage root, so nothing was copied into it.', ['path' => $target->path]));
    }

    /** @return list<string> the rsync command, from the storage root (or only its listing of the target) to the target */
    private function rsync(ReplicationTarget $target, array $options, bool $listTarget = false): array
    {
        $remote = $target->type->notation === 'rsync';
        $destination = ($remote ? ($target->username ? $target->username.'@' : '').$target->host.':' : '').$target->path.'/';
        $ssh = $remote ? ['-e', implode(' ', array_filter(['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new',
            $target->port ? '-p '.$target->port : null, $target->ssh_key_path ? '-i '.$target->ssh_key_path : null]))] : [];

        return $listTarget
            ? ['rsync', ...$ssh, ...$options, $destination]
            // The partial versions OCFL is still writing are left for the next run.
            : ['rsync', '-a', ...$ssh, '--exclude', '.v*.partial', ...$options, rtrim($this->store->root(), '/').'/', $destination];
    }

    /** @return array{0: list<string>, 1: list<string>, 2: array{files: int, bytes: int}} paths sent, paths deleted, and the totals */
    private function parse(string $output): array
    {
        $changed = $deleted = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\*deleting\s+(.+)$/', $line, $m)) {
                $deleted[] = $m[1];
            } elseif (preg_match('/^[<>ch.][fdLDS][^ ]{9}\s+(.+)$/', $line, $m) && $line[0] !== '.') {
                $changed[] = $m[1];
            }
        }
        preg_match('/Number of files: ([\d,]+)/', $output, $files);
        preg_match('/reg: ([\d,]+)/', $output, $regular);
        preg_match('/Total transferred file size: ([\d,]+)/', $output, $bytes);
        $number = fn ($m) => (int) str_replace(',', '', $m[1] ?? '0');

        return [$changed, $deleted, ['files' => $number($regular ?: $files), 'bytes' => $number($bytes)]];
    }

    /** A replication event for each kept file copied, named by its content path. */
    private function recordEvents(ReplicationTarget $target, array $paths): void
    {
        foreach (array_chunk($paths, 500) as $chunk) {
            foreach (DigitalFile::whereIn('storage_path', $chunk)->get() as $file) {
                $this->events->record($file, 'replication', 'success', __('Copied to :target.', ['target' => $target->name]), null, null, 'rsync');
            }
        }
    }

    private function concept(string $vocabulary, string $notation): string
    {
        return Concept::where('iri', Vocab::BASE."$vocabulary/$notation")->value('id') ?? throw new InvalidArgumentException(__(':value is not in the list', ['value' => $notation]));
    }
}
