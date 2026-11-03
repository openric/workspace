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
