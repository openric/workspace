---
title: Help for the screen you are on
category: Getting started
order: 30
heratio: contextual-help-user-guide.md
---

# Help for the screen you are on

The **Help** link at the end of the staff menu opens, in a new tab, the guide that explains the screen you are on. On a record it opens the guide to describing units of description; on the accessions list, the guide to accessions; and so on. Where no guide names the screen, it opens the Help Center itself, so the link always goes somewhere useful.

## How a screen finds its guide

Each guide names, in its header, the screens it explains: by the screen's route name, such as `records.show`, or by a pattern, such as `records.*` for every screen of units of description. When you ask for help, the Workspace picks the guide that names the screen exactly; failing that, the guide with the longest pattern that matches it, which is the most specific one.

A guide meant only for staff is never offered to a visitor, so the public catalogue's own screens lead to the public guides.

## Pointing a screen at a guide

To change which guide a screen opens, add the screen's route name or a pattern to the `routes` line of the guide's header (see [Using the Help Center](/help/article/help-user-guide)). No code changes, and a guide that is removed simply stops being offered.
