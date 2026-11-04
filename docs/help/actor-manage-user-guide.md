---
title: Describing authority records
category: Describing
order: 20
routes: agents.*
heratio: actor-manage-user-guide.md
---

# Describing authority records

An authority record describes a person, a family or a corporate body: who created, received, kept or is named in the records. In RiC they are agents, a `rico:Person`, `rico:Family` or `rico:CorporateBody`, described once and joined by relations to every record, place and activity they belong with.

The screens are under **Describe**, **Authority records** (`/agents`).

## Making one

1. Open **Authority records** and choose **New**.
2. Say whether it is a person, a family or a corporate body, and give its authorised form of name.
3. Save. It is a draft until it is published, as every description is.

While describing a unit you can also make a creator on the spot from the creators panel, then return to the authority record later to fill it out.

## What it holds

The page follows the ISAAR(CPF) areas, each field showing the RiC term it is recorded as:

- **Identity**: the kind of entity, its authorised form of name and its other names, and its identifiers.
- **Description**: dates of existence, history, places, legal status, functions, occupations and activities, mandates, internal structure and general context.
- **Relationships**: other agents it is joined to, such as a parent, an employer or a member of a family, each typed by its RiC-O property and with its dates.
- **Control**: rules and conventions, status, level of detail, languages and scripts, sources and maintenance notes.

**Names.** An agent can have as many names as it needs, each with its type: authorised, parallel, standardised, other, historical or abbreviated, each in its language and with the dates it was used. Any published name finds the agent in a search.

**Dates** are kept as RiC date ranges: birth and death for a person, existence for any agent.

**Contact points** hold addresses, telephones and e-mail for an agent that has them.

## Duplicates, merging and splitting

**Duplicates** (`/agents/duplicates`) lists pairs of agents whose names look alike. For each, choose **Merge** to keep one and fold the other into it, every relation and name moving with it; or **Not a duplicate** to stop it being offered again. A merge is recorded in the audit log, and the merged agent is deprecated: its IRI then says it was merged, and points to the one kept.

**Split** does the opposite: it takes an agent that turned out to be two people and makes a second from it, with the names and relations you move across.

## Other things on its page

- **EAC-CPF**: the authority record exported as EAC-CPF (the 2010 schema).
- **Print**: the record to read or print.
- The records it is joined to, and its other relations, each linking through.

## Who may do what

Anyone may read a published authority record in the public catalogue. Contributors and up create and change them; editors and administrators publish, merge and delete.
