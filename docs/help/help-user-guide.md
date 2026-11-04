---
title: Using the Help Center
category: Getting started
order: 20
routes: help.*
heratio: help-user-guide.md
---

# Using the Help Center

The Help Center is the Workspace's own library of guides: what each part does and how to do it. It is at `/help`, and the **Help** link in the menu opens it at the guide for the screen you are on.

## Finding a guide

**Search.** Type a few words into the search box on any Help Center page. You get two lists: whole guides whose title or text matched, best first, with the words found picked out; and the sections of guides whose heading or text matched, each of which opens the guide at that heading. Search understands word forms, so *preserving* finds *preservation*, and it takes phrases in quotes and words to leave out after a minus sign.

**Browse.** The landing page lists the categories, from Getting started to the Technical reference, with how many guides each holds. A category lists its guides in reading order, grouped where it has subcategories.

**Follow the links.** A guide lists, at its foot, the guides it links to and those that link to it. The links before and after take you through the rest of its category in order.

## Reading a guide

A longer guide opens with its contents; each entry jumps to that heading. The category and the length are shown above the text. Signed-in staff also see which file the guide comes from and, where it was adapted from one, which Heratio guide.

## Who sees what

Most guides are open to anyone, including visitors to the public catalogue. Guides for staff, such as those on administration and the technical reference, are shown only once you are signed in.

## For administrators: adding and changing guides

Guides are not edited in the browser. Each is a Markdown file in `docs/help` that ships with the Workspace, under a short header:

```
---
title: Using the Help Center
category: Getting started
order: 20
staff: false
routes: help.*
heratio: help-user-guide.md
---
```

- **title** and **category** place it in the library. **order** is its place in the category.
- **staff: true** keeps it from the public.
- **routes** names the screens it explains, by route name or a pattern such as `records.*`, for help in context.
- **heratio** names the Heratio guide it was adapted from.

The Workspace reads the guides in again by itself whenever the files change, so a release that brings new guides needs no extra step. `php artisan workspace:help:ingest` reads them in at once if you need to.

A link in a guide's text to `/help/article/` and another guide's name links the two. An administrator can also link two guides by hand from the foot of either; a link made by hand is kept when the guides are read in again.
