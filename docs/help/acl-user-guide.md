---
title: Roles and permissions
category: Administration
order: 20
staff: true
routes: roles.*, accounts.permissions, accounts.access
heratio: acl-user-guide.md
---

# Roles and permissions

What a person may do is decided by their roles. A role is a set of permissions, each an action on a kind of record: read, create, update, delete, publish, view drafts, read masters, translate and so on, on archival descriptions, authority records, repositories, vocabularies, storage, accessions, agreements and vendors, and the administrative permissions of the institution itself.

## The roles every institution starts with

| Role | What it may do |
|---|---|
| Public | What anyone may read: the published catalogue |
| Signed in | What every signed-in person may do |
| Contributor | Describe: create and change descriptions, see drafts |
| Editor | Describe, publish and delete; manage repositories and vocabularies |
| Translator | Read drafts and add translations, and nothing else |
| Administrator | Everything, including the institution's own administration |

Roles inherit: an editor may do what a signed-in person may do, and more.

## Changing a role

**Roles** (`/roles`) lists them. Open one to change its permissions kind by kind, and its name and description in each language. **New role** makes a role of your own, such as *Volunteer* or *Records officer*, under a role it inherits from.

The **Public** role decides what the public catalogue, the API and every open interface may show, so change it with care.

## Permissions for one person

A person's own permissions, set on their account, come before those of their roles: give one contributor the right to publish one repository's records, or keep one editor from deleting.

## Where a permission applies

A permission can apply to all of a kind, or to part of it: one repository, or one record set and everything inside it. An editor of one repository's records can then publish those and no others.

## Administrative permissions

Some permissions are of the institution rather than of a kind of record: administer the institution, manage accounts, manage roles, view the audit log, manage workflows, manage privacy, manage the shop, manage the reading room, and review submissions. Give them to the people who do that work.

## Checking

**Access** on a user's page shows everything they may do, and which role or permission gives it.
