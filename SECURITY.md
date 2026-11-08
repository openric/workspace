# Security policy

The Workspace is in development and has no release yet. It does have application code, a public development instance at [workspace.openric.org](https://workspace.openric.org) and a public API, so security problems in it are real and we want to hear about them.

## Reporting a vulnerability

Please do not open a public issue for a security problem. Report it privately instead:

- **On GitHub:** the Security tab of this repository, then "Report a vulnerability". The report stays private between you and the maintainers.
- **By email:** johan@theahg.co.za, with "OpenRiC Workspace security" in the subject line.

Tell us the version you tested (the `VERSION` file, or the release tag), the steps to reproduce, and what an attacker could gain. Do not include anyone's personal data beyond what is needed to show the problem.

We acknowledge reports within five working days and tell you what we intend to do. Once a fix ships we publish a GitHub security advisory and credit you, unless you would rather stay anonymous. Please give us a reasonable time to fix the problem before you disclose it.

## Scope

Covered: the code in this repository and the development instance at workspace.openric.org. The OpenRiC specification, the reference service, the viewer and the capture client are covered by [openric/spec SECURITY.md](https://github.com/openric/spec/blob/main/SECURITY.md).

The development instance holds demonstration data only. Read-only checks against it are fine. Do not create, change or delete data there, do not run load or denial-of-service tests, and do not try to reach another institution's data or anything unpublished beyond what is needed to show that you can. To test writes, install the Workspace yourself, or ask us for a disposable environment.

## Supported versions

There is one line of development, from `main`. Fixes go into the next release; older releases do not get backported fixes. Until V0.1 the Workspace is not supported for production data at all (see [ROADMAP.md](ROADMAP.md)).

## What the Workspace does now

Everything is denied unless a role grants it ([ADR 0005](architecture/adr/0005-roles-and-permissions.md)), and each account sees only its own institution. API keys are tied to an institution and carry scopes; writes through the API are proposals a person reviews ([ADR 0007](architecture/adr/0007-api-keys-and-proposals.md)). Unpublished records, embargoes and redacted personal information are applied in the public catalogue, the API, OAI-PMH, IIIF and file delivery. Delivered files carry a sandboxing Content-Security-Policy. Sign-in, registration and every public token link are rate limited. Changes are written to a tamper-evident audit log ([ADR 0004](architecture/adr/0004-tamper-evident-audit-log.md)). Every outside program (converters, format identification, virus scanning, archivers) runs in a session of its own under CPU, memory and file-size limits; the jobs that run converters can run in a worker with no network beyond the machine (INSTALL.md, section 8); every archive is counted before it is unpacked and refused past configurable limits. The CI pins every GitHub Action by commit SHA.

## What is not yet proven

An external review on 1 November 2026 found no exploitable problem, but rightly pointed out that the controls above are not yet proven by a systematic set of tests that try what must be refused (another institution, unpublished, embargoed, revoked keys, expired links, cached responses). That test set, dependency audits in CI and a deployment check are the hardening and acceptance phase in [ROADMAP.md](ROADMAP.md). Two-factor sign-in comes with single sign-on (phase 12). Backup and a witnessed restore are a condition of V0.1 sign-off.
