---
title: Describing repositories
category: Describing
order: 30
routes: repositories.*
heratio: repository-manage-user-guide.md
---

# Describing repositories

A repository is an institution, or a part of one, that holds archival material: a national archive, a municipal record centre, a university's special collections. In RiC it is a `rico:CorporateBody`, described by ISDIAH and joined to the units it holds.

The screens are under **Describe**, **Repositories** (`/repositories`).

## Why it matters

Every top-level unit of description names the repository that holds it, and the units inside inherit it. The repository then gives:

- the repository facet in the catalogue's search;
- the holder in every export, such as `rico:hasOrHadHolder` in RiC-O and the repository in EAD;
- a public page with its holdings, contact details and opening times;
- the institution's reference code prefix, if it has one.

## Making one

1. Open **Repositories** and choose **New**.
2. Give its authorised form of name and its identifier, such as its ISO 15511 code.
3. Save, then fill in the rest of its description.

## What it holds

The page follows the ISDIAH areas, numbered as ISDIAH numbers them, each field showing its RiC term:

| ISDIAH area | What you record |
|---|---|
| 5.1 Identity | Identifier, authorised and other forms of name, type |
| 5.2 Contact | Contact points: address, telephone, e-mail, web and a contact person |
| 5.3 Description | History, geographical and cultural context, mandates, administrative structure, records management and collecting policies, buildings, holdings, finding aids |
| 5.4 Access | Opening times, conditions of access, accessibility |
| 5.5 Services | Research, reproduction and public areas |
| 5.6 Control | Description identifier, rules, status, level of detail, dates, languages, sources |

A repository can have more than one contact point; one is marked as its main one, and it may carry coordinates so it appears on maps.

## Its own look

**Theme** gives a repository its own banner, logo, colours and text on its public page, so a department or a partner institution can be seen as itself inside the institution's catalogue.

## Its holdings

The repository's page lists the top-level units it holds. Its public page shows the same list for what is published. To move records to another repository, change the holder on the top-level unit; the units inside follow.

## Who may do what

Anyone may read a published repository in the public catalogue. Repositories are foundational, so by default only editors and administrators create and change them.
