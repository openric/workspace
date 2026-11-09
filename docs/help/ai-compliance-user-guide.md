---
title: AI register and compliance
category: AI assistance
order: 40
staff: true
routes: ai.compliance, ai.systems.show, ai.attestation
heratio: ai-compliance-article-9.md
---

# AI register and compliance

**Admin > AI > AI register and compliance** keeps what an institution needs to show how it uses AI: what each AI system is for, how people oversee it, what can go wrong with it, what did go wrong, which models answered, and that reviewers know how to review. It follows the EU AI Act (articles 9, 12 and 14 and Annex IV) because that is the most detailed rule there is, and serves equally for POPIA's accountability and for an institution's own AI policy. It is for those who may manage the institution, but the attestation, which every reviewer gives.

## The register

Each AI service of the Workspace is a system in the register: names, summaries, condition, translation, subjects, search by meaning, the assistant and the rest. The Workspace fills in what it knows (its purpose, the gateway as provider, the institution as deployer, every output a proposal a person reviews) and a risk class: the assistant is **limited risk**, since it talks to the public; the rest are **minimal risk**, since nothing they make changes a record before a person accepts it. Change any of it on the system's page, with the accountable person or unit and the dates of the last and next review.

Beside each system the register shows its oversight over the last 30 days: how many of its proposals reviewers accepted and rejected, and for the assistant how many flagged answers were found right or wrong.

## Halting a system

**Halt** stops a system at once, with a reason. The Workspace does not call a halted system: its buttons say it is halted, and the assistant disappears from both the staff menu and the catalogue. **Resume** lets it be called again. Both are in the audit trail.

## Risks and incidents (article 9)

On a system's page, **Add a risk** records what could go wrong: the risk, its severity and likelihood, whether it arises from intended use or foreseeable misuse, who is affected, the mitigation, the risk left after it, and its status. Each change is kept with who reviewed it.

**Record an incident** records what did go wrong, optionally against the risk it bears on; **Resolve** closes it with how. The nightly monitor (`php artisan workspace:ai:monitor`) raises an incident itself when reviewers rejected more than half of at least 20 of a system's proposals in 30 days, or found three of the assistant's answers wrong, and raises no second one while the first is open.

## Models and record-keeping (article 12)

Every model that answered appears under **Models**, with its version, its calls and when it was first and last seen, taken from the log of inferences. Add what is known of its training data, its limits and its intended purpose, and mark a model retired. The log itself keeps every call, chained and signed; `php artisan workspace:ai:verify` checks the chain.

## Technical documentation (Annex IV)

Each system's page ends with its technical documentation, in six parts: general description, elements and development (the models), monitoring and control, risk management, incidents and record-keeping. **Download as Markdown** gives the same to keep or send.

## Attestations (article 14)

Anyone who may review proposals is asked to read **Reviewing AI output** and attest that they have: what AI output is, automation bias (the pull to accept what a machine proposes), what to check before accepting, and when to reject or flag. An attestation holds for a year, and the register lists who has one.
