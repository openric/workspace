---
title: Users
category: Administration
order: 10
staff: true
routes: accounts.*
heratio: user-manage-user-guide.md
---

# Users

Every person who signs in to the Workspace has an account in one institution. **Users** (under **Admin**, `/accounts`) is for those who manage accounts.

## Adding a user

**New user** asks for their name and e-mail address. They are sent a **one-time link to set the password**; the Workspace never sends or shows a password. Give them their roles on the same screen.

Visitors can also ask for an account, where the institution allows it in its settings. Their requests wait under **Requests for an account** until an administrator approves or rejects each.

## Changing an account

Open a user to:

- **Edit user and contact details**;
- choose their **roles** and **Save roles**;
- send a **new password link**;
- set **permissions of this user** alone, by kind of record; these come before those of their roles;
- see their **API keys**, and revoke any;
- **turn two-factor sign-in off** for someone who lost both their phone and their recovery codes; they set it up again at the next sign-in if it is required;
- **disable** the account, which stops it signing in without losing its history, or **enable** it again.

**Delete user** removes the account. Everything it did stays in the audit log under its name.

## Passwords

A password must be at least 12 characters, and is stored only as a hash. Sign-in allows five tries a minute for one e-mail address from one place, so a password cannot be guessed by trying many.

## Access in a sentence

**Access** on a user's page lists what they may do, kind by kind, and where it comes from: which role, or their own permissions. See [Roles and permissions](/help/article/acl-user-guide).
