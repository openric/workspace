# ADR 0011: Workflows and tasks, as Heratio's, with teams as roles

- Status: accepted
- Date: 2026-10-30
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 10 brings Heratio's ahg-workflow into the Workspace: work assignment and the description workflow. Done when a team divides a fonds' description among its members and sees what is left. Heratio's package (7,830 lines, at 7663253e9) has workflows with ordered steps, tasks on records, a pool to claim from, decisions, history, queues and service levels, bulk operations, publish gates and Spectrum procedures. Its my work, team work, timeline and step forms are placeholders; PSIS's ahgWorkflowPlugin (15fc8f64), which Heratio was ported from, has them working, with returning, resubmitting and choosing the workflow that applies. On 30 October 2026 Dr Johan Pieterse chose to split the phase: 10a the workflows, tasks and assignment; 10b queues, service levels, overdue notices, publish gates and the diagram.

## Decision

- Workflows, steps, tasks and history are tables of the institution, with Heratio's fields, and every list a bundled vocabulary: scope, trigger, step type, step action, task status, priority, decision and history action. The Workspace adds the step type describe, so a team can divide description work.
- A workflow applies everywhere (the default for its kind), to a repository, or to a collection: a unit of description and everything inside it. As PSIS chooses, the nearest collection workflow from a description upwards applies, then its repository's, then the default.
- A task is one step for one record (or authority record, repository or accession). It is claimed from the pool or assigned; only whoever has it decides it, as Heratio guards it. Approving it creates the next active step's task, or completes the workflow. Rejecting or returning it needs a reason; a returned task goes back to whoever submitted it, who resubmits it to the pool, as PSIS does. A task changes under a row lock.
- Who may take a step: an account that may change the record (update on its kind), and, when the step says so, has its required role, one of its roles, or is one of its accounts. A team is a role, as Heratio's AtoM groups are; team work shows the open tasks of a role's members. Heratio's security clearance on a step is kept and applied with phase 18.
- Workflows and steps need the new institution permission Manage workflows, given to administrators, as Heratio's admin pages are admin only. Cancelling a task needs it too.
- Starting a workflow for a description can start it for everything inside it the account may change, which is how a team divides a fonds; the description's page then shows what is left inside it, by status and by who has it.
- Notices of a task assigned, decided or completed are mailed through the Outbox to accounts of the institution, when the workflow sends notifications. Heratio's notification queue table is not carried: the Outbox and its sent-mail log are.
- Publication stays the Workspace's own review and publish step. Heratio's publish gates and its setting to require an approved workflow before publishing come in 10b.

## Consequences

- The history is the workflow's own account of what happened, beside the audit log, which still records every change. Heratio's IP address and user agent on each history row are not kept: the audit log keeps who did what.
- Spectrum procedures stay out (roadmap phase 21), and so do Heratio's drag-and-drop designer and its edges for branching steps: steps run in order.
