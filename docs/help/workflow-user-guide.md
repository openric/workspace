---
title: Workflows
category: Records management and workflow
order: 20
staff: true
routes: workflow.*
heratio: workflow-user-guide.md
---

# Workflows

A workflow takes work through its steps, such as describe, review, approve, check and publish, each done by the right people, in order, within a time. It gives an institution control over what reaches the public and a record of who did what.

## Your work

- **My tasks** (`/workflow/my-tasks`): the tasks assigned to you.
- **Pool** (`/workflow/pool`): tasks waiting for anyone in your role to claim.
- **Team** (`/workflow/team`): your team's tasks, for a supervisor.
- **Overdue** (`/workflow/overdue`): tasks past their service level.
- **Queues** and **History**: every task by queue, and every task done.

A task moves from pending to claimed, in progress, and then approved, rejected or returned for more work, or cancelled. Open one to see its subject, its step, its history and its diagram, and to decide it with a comment.

## Building a workflow

**Workflows** (under **Workflow**, for administrators) defines each workflow:

- what starts it: a submit, a publish, an update, a create, or starting it by hand;
- what it covers: a kind of entity, a repository, or a part of the catalogue;
- its steps in order, each of a type (describe, review, approve, quality check, final review, publish), with the role that does it and the service level in days;
- what happens when a step runs late: a reminder, or escalation to someone else.

**Diagram** shows the workflow as a picture, step by step.

## Publish gates

**Publish gates** (`/workflow/admin/gates`) are checks a unit must pass before it can be published, such as a title, a level, dates, a creator, or a rights statement. A unit that fails a gate cannot be published until it passes, unless someone with the authority publishes it anyway, which is recorded in the audit log. A unit's **Publish readiness** lists the gates it fails.

## Proposals

Changes proposed through the API or by a model come in as proposals, reviewed under **Proposals** before they touch a description.
