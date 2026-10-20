# ADR 0005: Roles and permissions after Heratio's access control, deny wins

- Status: accepted
- Date: 2026-10-20
- Deciders: Dr Johan Pieterse
- Gate: G2

## Context

The Workspace started with three fixed roles and six permissions. Dr Johan Pieterse asked for Heratio's functionality (20 October 2026): an institution creates its own roles, and a permission matrix decides what each role, or one account, may do with each kind of record. Heratio's `ahg-acl` package does this after AtoM: groups in a hierarchy with members; per class (descriptions, actors, repositories, terms) a set of actions; each action granted, denied or inherited, for everything, for one repository's records, or for one record; permissions for a single user; a translate permission with the languages a group may translate into.

Security classification, clearances, compartments and watermarks sit in the same Heratio package but belong to `ahg-security-clearance`, which the G1 audit puts at "later: jurisdiction". Access requests are their own package (`ahg-access-request`) and their own Workspace area. Two-factor sign-in belongs with accounts.

## Decision

1. **Roles are institution records**, not vocabulary concepts: a `roles` table with names and descriptions per language, a parent role, members, and the languages it may translate into. Each role may name the template it was created from (a concept of `account-role`). The templates follow Heratio: Public (anonymous) and Signed in (authenticated), which are system roles that cannot be removed, and Administrator, Editor, Contributor and Translator. Signed in inherits from Public, and new roles inherit from Signed in.
2. **Actions and kinds of record stay vocabulary concepts** (`permission`, `record-kind`), so there is no enumeration. Which actions apply to which kind is `workspace.permission_matrix`.
3. **A permission** is one row: a role or one account, an action, a kind, a scope (everything, one repository, or one record), and grant or deny. Inherit is the absence of a row.
4. **The decision**, for an account (or the public), an action, a kind and optionally a record:
   - the scopes are tried from the most specific, the record, then its repository, then everything;
   - at each scope the account's own permissions are tried first, then those of its roles, their parent roles, Signed in and Public;
   - at the first level where any permission applies, **a denial wins over a grant** (Dr Johan Pieterse, 20 October 2026; Heratio lets a grant win, AtoM lets a denial win);
   - if nothing applies anywhere, the answer is no.
5. **Translation**: writing values in the original language of a record needs update; writing another language needs update, or translate together with that language in one of the account's roles.
6. **Heratio code reused** under the provenance ledger: the scope bucketing, the grant/deny/inherit form handling and the decision walk of `AclService` (`bucketIoPermissions`, `applyAclForm`, `check`), ported to the Workspace schema with the deny-wins rule, user-level precedence and role inheritance added.

## Consequences

Every screen asks the access service, with the record in hand, instead of a fixed permission. Existing installations are migrated: their three roles become institution roles with equivalent permissions, and their members stay members. Public discovery does not exist yet; the Public role's permissions take effect when it does. Permissions on agents and functions are added with those modules, by adding a record kind and its actions.
