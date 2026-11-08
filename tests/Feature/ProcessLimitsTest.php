<?php

// OpenRiC Workspace - outside programs under limits, archives counted before they are unpacked, file jobs on their own
// queue and the CI's actions pinned (roadmap R1 hardening, after the external production-readiness assessment of
// 7 November 2026): a program past its memory or CPU limit fails and its reason is named; a signal it raises stays in its
// own session; a ZIP, a tar or a 7z holding more files or bytes than allowed is refused; the jobs that run converters go
// to the files queue; and every `uses:` in the CI is a commit SHA.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use App\Jobs\IdentifyFormat;
use App\Jobs\MakeViewingCopies;
use App\Support\Archives;
use App\Support\LimitedProcess;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

class ProcessLimitsTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir().'/workspace-limits-'.bin2hex(random_bytes(6));
        mkdir($this->work);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->work));
        parent::tearDown();
    }

    public function test_a_program_runs_under_its_limits_and_fails_past_them(): void
    {
        if (LimitedProcess::limits('anything') === null) {
            $this->markTestSkipped('setsid and prlimit are not on this machine');
        }
        $ok = LimitedProcess::make('anything', ['sh', '-c', 'echo fine']);
        $ok->run();
        $this->assertSame("fine\n", $ok->getOutput());

        config(['workspace.process_limits.tools.hungry' => ['memory_mb' => 128]]);
        $hungry = LimitedProcess::make('hungry', [PHP_BINARY, '-d', 'memory_limit=-1', '-r', 'echo strlen(str_repeat("x", 512 * 1024 * 1024));']);
        $hungry->run();
        $this->assertFalse($hungry->isSuccessful(), 'half a gigabyte under a 128 MB address space');

        config(['workspace.process_limits.tools.busy' => ['cpu_seconds' => 1]]);
        $busy = LimitedProcess::make('busy', ['sh', '-c', 'while :; do :; done']);
        $busy->setTimeout(30)->run();
        $this->assertNotNull(LimitedProcess::reason($busy), 'stopped by its CPU limit, and said so');

        config(['workspace.process_limits.tools.writer' => ['file_mb' => 1]]);
        $writer = LimitedProcess::make('writer', ['sh', '-c', 'head -c 3000000 /dev/zero > big.bin'], $this->work);
        $writer->run();
        $this->assertSame(__('it wrote a file larger than it is allowed'), LimitedProcess::reason($writer));
    }

    public function test_a_signal_a_program_raises_stays_in_its_own_session(): void
    {
        if (LimitedProcess::limits('anything') === null) {
            $this->markTestSkipped('setsid and prlimit are not on this machine');
        }
        $process = LimitedProcess::make('anything', ['sh', '-c', 'kill -INT 0']);
        $process->run();
        // Had the interrupt reached this process group, the test would not get here.
        $this->assertFalse($process->isSuccessful());
    }

    public function test_an_archive_past_its_limits_is_refused_before_it_is_unpacked(): void
    {
        config(['workspace.archive_limits' => ['max_bytes' => 1_000_000, 'max_entries' => 5]]);
        $zip = new ZipArchive;
        $zip->open($this->work.'/many.zip', ZipArchive::CREATE);
        foreach (range(1, 6) as $i) {
            $zip->addFromString("f$i.txt", 'x');
        }
        $zip->close();
        $zip->open($this->work.'/many.zip');
        $this->assertRefused(fn () => Archives::zip($zip), 'six files');

        // A small file that unpacks to two megabytes.
        $zip = new ZipArchive;
        $zip->open($this->work.'/bomb.zip', ZipArchive::CREATE);
        $zip->addFromString('zeros.bin', str_repeat("\0", 2_000_000));
        $zip->close();
        $this->assertLessThan(100_000, filesize($this->work.'/bomb.zip'));
        $zip->open($this->work.'/bomb.zip');
        $this->assertRefused(fn () => Archives::zip($zip), 'two megabytes');

        file_put_contents($this->work.'/zeros.bin', str_repeat("\0", 2_000_000));
        exec('tar -C '.escapeshellarg($this->work).' -czf '.escapeshellarg($this->work.'/bomb.tar.gz').' zeros.bin');
        $this->assertRefused(fn () => Archives::file($this->work.'/bomb.tar.gz', 'tar'), 'a tar of two megabytes');

        config(['workspace.archive_limits' => ['max_bytes' => 10_000_000, 'max_entries' => 5]]);
        Archives::file($this->work.'/bomb.tar.gz', 'tar');
        $zip->open($this->work.'/bomb.zip');
        Archives::zip($zip);
    }

    public function test_the_jobs_that_run_converters_go_to_the_files_queue(): void
    {
        Queue::fake();
        MakeViewingCopies::dispatch('01a1b000-0000-7000-8000-000000000000', '01a1b000-0000-7000-8000-000000000001');
        IdentifyFormat::dispatch('01a1b000-0000-7000-8000-000000000002');
        Queue::assertPushedOn('files', MakeViewingCopies::class);
        Queue::assertPushedOn('files', IdentifyFormat::class);
    }

    public function test_every_action_the_ci_uses_is_pinned_by_commit(): void
    {
        preg_match_all('/^\s*-?\s*uses:\s*(\S+)/m', (string) file_get_contents(base_path('.github/workflows/ci.yml')), $uses);
        $this->assertNotSame([], $uses[1]);
        foreach ($uses[1] as $action) {
            $this->assertMatchesRegularExpression('/@[0-9a-f]{40}$/', $action, "$action is pinned by a tag, which can be moved");
        }
    }

    private function assertRefused(callable $check, string $what): void
    {
        try {
            $check();
            $this->fail("$what was let through");
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
