---
title: Code reference
category: Technical reference
order: 20
staff: true
routes: functions-docs.*
heratio: functions-docs-user-guide.md
---

# Code reference

The code reference, at `/admin/code-reference`, is a read-only catalogue of what the Workspace is made of. It is for whoever administers the installation and for developers. The [Help Center](help-user-guide.md) explains how to use the Workspace; this explains where its parts are.

It is under **Admin**, for an account that may administer the institution.

## The catalogues

- **PHP classes and methods**: every class under `app/`, grouped by namespace, with what kind it is, what its header comment says, and each public method with its signature, the first paragraph of its comment, and its file and line.
- **Blade templates**: every template under `resources/views`, grouped by folder, with its header comment.
- **Routes**: every route with its methods, address, name, controller action and middleware, grouped by the first part of its address, and the comment on the action that answers it.
- **Console commands**: the Workspace's own `php artisan` commands with their arguments and descriptions.
- **Bundled vocabularies**: every vocabulary that ships with the Workspace, as open or fixed lists (see the [Dropdown manager](dropdown-manage-user-guide.md)), with its number of terms and its file.

**Filter** narrows a catalogue to the groups and entries whose name, signature, comment or place contains what you type.

## How it stays current

Each catalogue is read from the code itself, by PHP reflection and by reading each file's header comment. It is cached until a file it is built from changes, so a release shows its new code the first time anyone opens the page.

## Compared with Heratio

Heratio's catalogue displays Markdown files that a KM generator writes to `/opt/ai/km` on a schedule, with JavaScript and Python catalogues as well. The Workspace has no such generator and builds its catalogues from its own code. It adds console commands and vocabularies, and leaves out JavaScript and Python, which it hardly has.
