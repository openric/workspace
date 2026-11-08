<?php

// OpenRiC Workspace - every outside program run under limits (roadmap R1 hardening, after the external production-readiness
// assessment of 7 November 2026): the converters, identifiers, scanners and archivers the Workspace calls each run in a
// session of their own (setsid, so a signal one raises never reaches the PHP process that called it), with an address
// space, CPU time and largest file of their own (prlimit), and single-threaded maths libraries (a thread pool's stacks
// count against the address space). A program that passes a limit is stopped and fails like any other, its reason named.
// Network isolation is not done here: on a host that forbids unprivileged namespaces it is the files worker's systemd
// unit (deploy/systemd), which file jobs run in.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Support;

use Symfony\Component\Process\Process;

class LimitedProcess
{
    private static ?bool $available = null;

    /** The command as a Process under the tool's limits (config workspace.process_limits), or as it is where the limits are off. */
    public static function make(string $tool, array $command, ?string $cwd = null): Process
    {
        $limits = self::limits($tool);
        if ($limits !== null) {
            // sh stays the direct child and the program leads a process group of its own (setsid, with no fork for a
            // background job): a signal the program raises stays in its group, a fatal one is an exit of 128 + it, and
            // when sh is told to stop (a timeout) it kills the whole group, so nothing the program started is left.
            $limit = 'prlimit --as='.($limits['memory_mb'] * 1048576).' --cpu='.$limits['cpu_seconds'].' --fsize='.($limits['file_mb'] * 1048576);
            $command = ['sh', '-c', 'setsid '.$limit.' -- "$@" & c=$!; trap \'kill -KILL -- -$c 2>/dev/null; exit 143\' TERM INT HUP; wait $c', 'limited', ...$command];
        }

        return new class($command, $cwd, ['OPENBLAS_NUM_THREADS' => '1', 'OMP_NUM_THREADS' => '1']) extends Process
        {
            /**
             * Stopped (a timeout stops with SIGKILL, which no shell can catch), everything it started goes too: the
             * program is in a session of its own, so killing its parent alone would leave it running.
             */
            public function stop(float $timeout = 10, ?int $signal = null): ?int
            {
                if ($this->isRunning() && ($pid = $this->getPid())) {
                    foreach (LimitedProcess::descendants($pid) as $child) {
                        @posix_kill($child, 9);
                    }
                }

                return parent::stop($timeout, $signal);
            }
        };
    }

    /** @return list<int> every process below this one, read from /proc */
    public static function descendants(int $pid): array
    {
        $children = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
            // pid (comm) state ppid: the name may hold spaces and brackets, so read after its last ')'.
            $line = (string) @file_get_contents($stat);
            $after = substr($line, (int) strrpos($line, ')') + 2);
            $parent = (int) (explode(' ', $after)[1] ?? 0);
            $children[$parent][] = (int) basename(dirname($stat));
        }
        $out = [];
        $queue = [$pid];
        while ($queue) {
            foreach ($children[array_shift($queue)] ?? [] as $child) {
                $out[] = $child;
                $queue[] = $child;
            }
        }

        return $out;
    }

    /** @return array{memory_mb: int, cpu_seconds: int, file_mb: int}|null */
    public static function limits(string $tool): ?array
    {
        if (! config('workspace.process_limits.enabled', true) || ! self::available()) {
            return null;
        }

        return array_map('intval', (config("workspace.process_limits.tools.$tool") ?? []) + config('workspace.process_limits.default'));
    }

    /** Why a limited program stopped, where a limit stopped it: a signal setsid passes on as 128 + its number. */
    public static function reason(Process $process): ?string
    {
        return match ($process->getExitCode()) {
            128 + 24, 128 + 9 => __('it used more CPU time or memory than it is allowed'),
            128 + 25 => __('it wrote a file larger than it is allowed'),
            default => null,
        };
    }

    // ponytail: setsid and prlimit are util-linux, on every Linux; elsewhere (a developer's Mac) programs run unlimited.
    private static function available(): bool
    {
        return self::$available ??= is_executable('/usr/bin/setsid') && is_executable('/usr/bin/prlimit');
    }
}
