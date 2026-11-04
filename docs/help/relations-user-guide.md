---
title: Relations between entities
category: Describing
order: 50
routes: relations.*
heratio: ric-user-guide.md
---

# Relations between entities

A relation joins two entities and says how: this person created that series, this series was accumulated by that body, this file concerns that place, this rule governed that activity. In the Workspace every relation is typed by the RiC-O property it is, such as `rico:hasCreator` or `rico:isAssociatedWithPlace`, and can carry its own dates, evidence and record of who asserted it.

## Adding a relation

On the page of any entity, open its relations and choose **Add a relation**:

1. Choose the **relation type**. Each type joins particular kinds of entity: a creation relation joins a unit of description to an agent, a place relation joins one to a place. The list offers only those that fit.
2. **Find the other entity** by name or identifier. If it does not exist yet, give its authorised name and the Workspace makes it as a draft.
3. Add its dates, if the relation held only for a time, and a note.
4. Save.

The relation shows on both entities, read the right way from each: *created* on one, *was created by* on the other.

## Relation types

The relation types are a vocabulary. The bundled types map to RiC-O properties; an institution can add narrower types of its own, such as *is the godparent of* under a family relation, which are exported as the bundled type they narrow. See [Working with concepts](/help/article/term-taxonomy-user-guide).

## Evidence

A relation can name the unit of description that proves it or describes it, such as the letter that shows two people were partners (`rico:evidences`). Choose **Add evidence** on the relation, find the unit, and add it.

## Assertions

A relation is a claim, and claims can be disputed. Each relation keeps who asserted it and where it came from: a person's account, an import, or a model's proposal. Anyone with access may **assert or dispute** it, giving the basis; a reviewer accepts or rejects each assertion. A relation proposed by a machine is shown as proposed until someone accepts it, and never reaches the public before then.

## Publishing

A relation has its own state, like every value. It reaches the public catalogue and the exports only once it is published, and only if both entities it joins are published too.
