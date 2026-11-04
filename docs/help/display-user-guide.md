---
title: Display profiles and browsing
category: Administration
order: 80
staff: true
routes: display.*, records.index, records.browse-settings
heratio: display-user-guide.md
---

# Display profiles and browsing

Two things decide how descriptions look. A **display profile** decides how the public catalogue shows a unit of description. **Browse settings** decide how staff see the list of archival descriptions. Both are under **Admin**, then **Display**, at `/admin/display`, for an account that may administer the institution. Each staff account can also set how it browses, unless the institution takes that choice away.

## Display profiles

A profile is for one level of description, such as item or file, or it is the institution's default for every level that has no profile of its own. A unit's public page uses its level's profile, then the default. If neither exists, the page shows every element it has, as it always did.

In a profile you choose:

- **the elements**, by ticking the ISAD(G) elements to show. Number them to set their order within their area; ticked elements without a number follow in the standard's order. Tick none, and every element shows.
- **your labels**, in English, Afrikaans and your working language, for any element whose standard label does not suit your readers. An empty label keeps the standard's.
- **the hierarchy**, which can stand beside the page or be left out.
- **the pictures of the units inside**, at small (about 100 pixels), medium (200) or large (400), or none. Left empty, they appear as the page normally shows them.
- **what a reader may do**: add to the clipboard, keep as a favourite, order a copy, cite, give feedback, and download the master files. Untick an action and its button or link leaves the page.

A profile changes how the public reads the page, not what is published. Only published values ever reach the public, and the staff record page always shows every element, because that is where it is edited.

Each level has at most one profile, and the institution at most one default. Deleting a profile returns its level to the default, or to everything.

## How staff browse

The list of archival descriptions, at `/records`, shows as:

- a **list**, one unit a line with its level, reference code, dates, extent and a summary of its scope;
- a **table**, one unit a row;
- **cards**, a tile for each unit with its first thumbnail.

It sorts by title, reference code, last change, earliest date or latest date, in either direction. Units without dates come last when sorting by date.

On the **Display** page the institution sets the view, the sort and its direction, how many units a page (10 to 200), and whether the table and the cards show images. **Each account may set its own** lets staff override these. Untick it and every account browses as the institution does.

Under the list, **How I browse** saves your own view, sort, direction, page size and images, and **Browse as the institution does** removes them again. The filters at the top of the page still change one list without saving anything.

## Print and CSV

**Print this list** and **Download this list as CSV** take the list exactly as filtered and sorted, up to 5,000 units, and only those your account may read. The print view is a table of reference code, title, level, dates and extent, ready for the browser's print command. The CSV has IRI, reference code, title, level, repository, dates, extent and scope and content, with a byte order mark so spreadsheets read its accents correctly. To export everything with every field, use **Export as CSV** at the top of the page instead.

## Compared with Heratio

Heratio's ahg-display serves a whole GLAM sector: museum, library and gallery collection types, and profiles assigned record by record. The Workspace keeps the archival part. A profile is chosen by level of description, and every choice in it is a vocabulary term. Heratio keeps a separate table so MySQL can sort large catalogues by title and date. PostgreSQL sorts those directly, so the Workspace has no such table, but it offers the same date sorts.

## Related

- [Dropdown manager](dropdown-manage-user-guide.md) changes the wording and order of the lists the forms offer.
