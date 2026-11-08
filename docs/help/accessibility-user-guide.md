---
title: Accessibility
category: Getting started
order: 90
routes: accessibility.statement
heratio: accessibility-statement-user-guide.md
---

# Accessibility

The Workspace aims at WCAG 2.2 level AA, the international baseline for web accessibility, for its public catalogue and for staff. How far it gets is checked on every release, and said plainly in the public accessibility statement.

## The accessibility statement

Every catalogue has one at `/accessibility-statement`, linked from the foot of each public page. It follows the W3C model statement, as Heratio's does: the institution's commitment, the conformance status (partially conformant, level AA targeted), what has been done, the known limitations, how to report a barrier (through the catalogue's feedback form) and how the statement was prepared. It names the Workspace version it describes.

## What is checked on every release

Two checks run in CI, in a browser, against a Workspace holding the demonstration dataset, and a release is refused while either fails:

- **WCAG 2.2 AA with axe-core**, on the public catalogue, search, a record with a scan, an authority record, sign-in and the statement, and for staff on the dashboard, the lists of records and authority records, a record, its form, an authority record's form, import, export, offline catalogues, search and a 3D model's page. Any critical or serious failure fails the check. The list of known failures (`tests/browser/a11y-baseline.json`) is empty, and is only ever lowered.
- **The keyboard alone**, through the main steps of the V0.1 demonstration scenario: sign in, skip past the menu, search, open a record, change its scope and content and save, publish it, and sign out. At every stop the focus must be visible and show its outline, and every step must be reached with Tab alone, so nothing traps the keyboard.

Choosing a file to upload is not in the keyboard check: it opens the system's own file dialog, which a browser test cannot drive.

## Running them yourself

The checks need Node 20 and the test tools in `package.json` (Playwright and axe-core, pinned):

    npm ci && npx playwright install chromium
    php artisan workspace:install ... && php artisan workspace:demo --password=<at least 12 characters>
    php artisan serve --port=8000 &
    DEMO_PASSWORD=<the same> npx playwright test

`workspace:demo` loads the synthetic Riverside Parish Council papers of the V0.1 demonstration scenario, about forty units marked synthetic, with an archivist and a reader account. Run it on an instance meant for trying things out, never on one with real records.

## What the checks cannot say

Automated checks find about a third of accessibility failures. The Workspace has not yet been reviewed by people using screen readers and other assistive technology; that review is one of the conditions of version 0.1, with an outside installation, an archivist's acceptance run and a security review.
