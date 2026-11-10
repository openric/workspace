<?php

// OpenRiC Workspace - release control (roadmap R1 hardening, after the external audit of 8 November 2026, findings F01
// and F03): the deploy refuses a tag unless every CI job passed on its commit, so its list of jobs is the workflow's,
// job for job; it verifies the tag's signature against the release already live, so the list of signers is kept and
// holds a key; and it keeps the release's evidence.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Feature;

use Tests\TestCase;

class ReleaseControlTest extends TestCase
{
    public function test_the_deploy_requires_every_job_of_the_workflow(): void
    {
        preg_match_all('/^  [a-z-]+:\n    name: (.+)$/m', file_get_contents(base_path('.github/workflows/ci.yml')), $jobs);
        $this->assertCount(5, $jobs[1], 'the workflow\'s jobs');
        preg_match('/^REQUIRED_CHECKS=\((.+)\)$/m', file_get_contents(base_path('deploy/deploy.sh')), $line);
        preg_match_all('/"([^"]+)"/', $line[1] ?? '', $required);
        $this->assertEqualsCanonicalizing($jobs[1], $required[1], 'deploy.sh requires exactly the workflow\'s jobs');
    }

    public function test_the_deploy_verifies_the_tag_against_the_live_release_and_keeps_its_evidence(): void
    {
        $deploy = file_get_contents(base_path('deploy/deploy.sh'));
        $this->assertStringContainsString('gpg.ssh.allowedSignersFile="$LIVE/deploy/allowed_signers" verify-tag "$TAG"', $deploy);
        // Verified in stage 1, before the new release is checked out, so the signers are the live release's.
        $this->assertLessThan(strpos($deploy, 'checkout --quiet --detach "$TAG"'), strpos($deploy, 'verify-tag "$TAG"'));
        $this->assertStringContainsString('.verification.verified', $deploy);
        $this->assertStringContainsString('storage/app/releases', $deploy);
        $this->assertMatchesRegularExpression('/^\S+@\S+ namespaces="git" ssh-ed25519 \S+$/m', file_get_contents(base_path('deploy/allowed_signers')));
    }
}
