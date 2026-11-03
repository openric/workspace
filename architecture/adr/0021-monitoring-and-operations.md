# ADR 0021: Monitoring and operations, single sign-on to follow

- Status: accepted
- Date: 2026-11-03
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 12 copies what an operator needs to keep an installation healthy: Heratio's `ahg-observability` (health and metrics), `ahg-integrity` (fixity on a schedule), `ahg-jobs-manage` (an operator's view of jobs), and single sign-on through OpenID Connect from `ahg-user-manage`. It also asks for runbooks for updating the Workspace and rotating its keys; the restore runbook comes with phase 3.

Until now fixity was checked on demand, file by file, or by `workspace:fixity` from a cron line. The deploy notes asked operators to add that cron line, but nothing checked that they had: on the live instance only the scheduler ran.

On 3 November 2026 Dr Johan Pieterse chose:

- **Single sign-on:** an unknown sign-in waits for approval, as registration does, rather than making an account at once.
- **Metrics:** both a page in the Workspace and Prometheus text for a monitor.

The phase is split: 12a operations (this decision), 12b single sign-on.

## Decision (12a: operations)

**Fixity on a schedule.** After Heratio's `ahg-integrity`, for the files of instantiations.

- **Schedules.** A schedule covers every file, a repository's files, or a record's and everything inside it. It runs daily, weekly or monthly. It can check only files not checked for some days. It stops at its limit of files or minutes, and the files it did not reach wait for the next run. The longest unchecked files go first, so a run that stops short still moves through the whole store.
- **When they run.** `workspace:fixity:scheduled` runs from the scheduler every fifteen minutes. It starts whichever schedules are due. A new schedule is due at once.
- **The ledger.** Every check is kept in it, as a PREMIS fixity check event: the size and SHA-256 that were expected and found, the outcome (unchanged, changed, missing), how long it took, and the run.
- **Failures.** A changed or missing file is kept open, Heratio's dead letter, counting its failures in a row. It can be acknowledged or investigated. It is closed when it passes again, or by hand as resolved or ignored, and closing it by hand needs a reason.
- **Telling someone.** A schedule names the account to tell when a run finds files changed or missing.
- **One history per file.** The manual check on a file's page and the `workspace:fixity` command go through the same ledger.

**Health and metrics.** After Heratio's `ahg-observability`. One set of figures is read when it is asked for:

- the database;
- the queue and its oldest waiting job;
- failed jobs;
- the scheduler's heartbeat;
- the object store's writability and free space;
- the files kept, and those not checked for fixity in 30 days;
- open fixity failures and the last fixity run;
- stopped webhooks and the deliveries waiting to be tried again;
- the audit log and its signing key;
- the institutions.

The figures appear in two places:

- **Admin, Operations** shows them with what needs attention. Failed background jobs can be tried again or forgotten there.
- **`/metrics`** gives them as Prometheus text (exposition format 0.0.4), to a monitor that sends `WORKSPACE_METRICS_TOKEN` as a bearer token, optionally only from `WORKSPACE_METRICS_IPS`. Without a token it is not served.

Changed from Heratio: no metrics library or shared store. Heratio's HTTP latency histograms and OpenTelemetry traces need a store shared by every PHP worker; nginx's own logs and status are the place for those.

**Operators.** The queue, failed jobs and disks belong to the installation, not to an institution. So the installation part of the page is shown only to the accounts `WORKSPACE_OPERATORS` names. Where it names none and the installation holds one institution, whoever may manage that institution is the operator.

**Jobs.** Heratio's `ahg-jobs-manage` was already copied in phase 5: the jobs page with browse, show, CSV, clearing finished jobs and cancelling. Phase 12a adds Laravel's own failed queue jobs to the operations page.

**Runbooks.**

- `deploy/runbooks/updating.md` covers before, during and after a deploy, and what to do when one fails.
- `deploy/runbooks/rotating-keys.md` covers the application key (with `workspace:keys:reencrypt`, which encrypts every encrypted field again after `APP_PREVIOUS_KEYS` holds the old key), the database password, API keys, webhook secrets and the other secrets in `.env`.
- The audit signing key is never rotated (ADR 0004).

### Not carried in 12a

- Heratio's integrity retention policies, legal holds, disposition and destruction certificates: retention and disposal are phase 13b's, with their own certificates.
- Its SHA-512 option: the store keeps SHA-256, which is what every file came in with.
- Its per-schedule memory limit and concurrency setting: a run is one process with `withoutOverlapping`.
- OpenTelemetry tracing.

### Evidence (12a)

- `tests/Feature/OperationsTest.php`:
  - a schedule over a record and what is inside it, finding one file changed and one missing, its account told, and not due again until its next run;
  - a failure counted again, then closed by itself when the file passes, every check kept;
  - closing by hand needing a reason;
  - a run stopping at its limit and the next taking the files it did not reach;
  - operators, the figures and what needs attention;
  - `/metrics` absent without a token, refused without it or from the wrong address, and served with it;
  - failed jobs forgotten;
  - the fixity pages;
  - encrypted fields encrypted again under a new application key.
- `tests/Feature/InstantiationTest.php` unchanged: `workspace:fixity` still records a failure on the file and exits with failure.
- `tests/Feature/AccessMatrixTest.php`: schedules, runs and failures refused to another institution's administrator.

## Decision (12b: single sign-on and two-factor sign-in), 3 November 2026

**Single sign-on by OpenID Connect.** After AtoM's `arOidcPlugin`, which Heratio never carried (its login page only names it). It uses the same client library, `jumbojett/openid-connect-php` (Apache 2.0).

- **Providers.** Each institution keeps its identity providers as rows: Microsoft Entra ID, Google Workspace, Keycloak or any other OpenID Connect provider. Each row holds the button's name, the issuer, the client ID and secret (the secret encrypted, and kept out of the audit log), the scopes, how a sign-in finds its account, which claim holds roles and which role each value gives, and whether an unknown sign-in may ask for an account. The sign-in page shows a "Sign in with" button for each active provider of the institution it is for.
- **The protocol.** The authorisation code flow with PKCE (S256). Everything that checks the provider's answer is the library's: the state, the ID token's signature against the keys the provider publishes, its issuer, audience, expiry and nonce. The library accepts a token that carries no nonce or no expiry; the Workspace does not.
- **Fitted to Laravel.** The library is used through a subclass. Its redirect comes back to the controller instead of calling `exit`. Its state, nonce and code verifier live in Laravel's session. It fetches through Laravel's HTTP client, so the tests play the provider end to end.
- **Finding the account.** By the provider's subject, once linked. Before that, where the provider is set to match by email, by an email the provider says it has verified; an unverified email never matches.
- **An unknown sign-in.** On Dr Johan Pieterse's choice, a sign-in that finds no account becomes a request for one. An administrator approves or rejects it under the existing requests, as Heratio's registration does, and approving it links the identity to the new account. AtoM made the account at once. A provider can also refuse unknown sign-ins altogether.
- **Roles.** Roles from a claim (AtoM's `set_groups_from_attributes`) are added at each sign-in as the provider maps them, and never taken away, so a role given in the Workspace is not lost when the provider omits it.
- **Refusals.** A disabled account is refused, and so is an account of another institution.

**Two-factor sign-in.** After Heratio's `ahg-security-clearance` TotpService and MfaPolicyService.

- **The codes.** Time-based one-time passwords, RFC 6238: HMAC-SHA1, 30-second steps, six digits, one step either side accepted. They are worked in the Workspace rather than through Heratio's Google2FA package, and checked against the RFC's own test values. A code is never accepted twice, as RFC 6238 5.2 asks.
- **Setting it up.** The secret is set up from a QR code and kept encrypted. It comes into force only when a code from it is confirmed.
- **Recovery codes.** Ten, of ten characters, each good once, kept only as SHA-256 hashes, and shown once.
- **Signing in.** With two-factor on, the password alone does not sign in: the code must follow within ten minutes.
- **Turning it off.** The account turns it off with a current code; an administrator turns it off for someone who lost their phone and their codes.
- **The policy.** It is the institution's: off, optional, required for administrators (accounts that may manage the institution, its accounts or its roles), or required for every account. Days of grace run from when the policy changed or the account was made. After them, an account that must have it is sent to set it up before it can do anything else.
- **Signing in through the provider.** A sign-in through the institution's identity provider uses that provider's own second factor, and is not asked for the Workspace's.

### Not carried in 12b

- WebAuthn security keys and one-time codes by email (Heratio's WebAuthnService and OtpService). The authenticator app and the identity provider's own second factor cover the policy; WebAuthn can follow when an institution asks for it.
- Signing out at the provider, and refresh tokens. A Workspace session is its own, and ends when the person signs out of the Workspace.
- Heratio's per-tenant global default policy. Each institution sets its own.

### Evidence (12b)

- `tests/Feature/SignOnTest.php`. The test plays the identity provider: discovery, a published RSA key and signed ID tokens. It covers:
  - an unknown sign-in becoming one request, approved, then signing in with the role its claim maps to;
  - a verified email linking an existing account, and an unverified one not;
  - refusals: a token signed with a key the provider does not publish, a token with no nonce, a callback with the wrong state, and a disabled account;
  - TOTP checked against RFC 6238's test values, a code refused the second time, a recovery code working once, and the second factor expiring after ten minutes;
  - the policy sending an administrator to set it up after the grace days, and not a reader, and not an account signed in through the provider;
  - an administrator's reset.
- `tests/Feature/AccessMatrixTest.php`: identity providers refused to another institution's administrator.
