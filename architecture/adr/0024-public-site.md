# ADR 0024: Publishing and outreach, the institution's public site and its help

- Status: accepted
- Date: 2026-11-04
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

Roadmap phase 20 copies the parts of Heratio that let an institution run its public site from the Workspace: static pages, articles and landing pages (`ahg-static-page`, `ahg-articles`, `ahg-landing-page`), in-application help (`ahg-help`) after Heratio's Help Center at https://heratio.org/help/, DOIs minted for published records (`ahg-doi-manage`), display profiles (`ahg-display`) and the functions documentation (`ahg-functions-docs`). The roadmap's test: an institution runs its public site from the Workspace.

On 4 November 2026 Dr Johan Pieterse chose:

- **Help: copy and adapt** ("Copy and adapt (Recommended)"). The help engine is copied, and so are Heratio's user guides for the areas the Workspace has, each rewritten for its screens and its RiC terms. Guides for areas it does not have are left out.
- **DOIs: DataCite test only** ("DataCite test only (Recommended)"). All of `ahg-doi-manage` is built and tested against a faked DataCite, with the test endpoint as the default. It stays off until an institution gives its own repository ID and password. Live holds no real data, so no real DOI is needed yet.
- **The split, 20a to 20d** ("20a-20d (Recommended)"), one release each:
  - 20a: static pages, the landing page builder, articles and marketing;
  - 20b: the Help Center;
  - 20c: DOIs;
  - 20d: display profiles and the functions documentation.

## 20a: static pages, articles and landing pages

**Static pages**, after Heratio's `StaticPageController`. Each page has a slug: about, privacy, terms or any other. Its title and text are written in Markdown in each language it is translated into, and it is shown in the visitor's language where it has one, else in the language it was first written in. About, privacy and terms are served at `/about`, `/privacy` and `/terms`, the others at `/pages/{slug}`. Those three are linked from the foot of every public page once they exist.

What was changed from Heratio:

- Each institution has its own pages.
- Any HTML in the text is stripped when it is shown, where Heratio cleans it as it is saved.

**Articles**, after Heratio's `BlogService`, `BlogLinkService`, `BlogController` and `BlogAdminController`.

- **Writing.** An article is written in Markdown, shown with any HTML stripped and no unsafe links, as Heratio shows it. It has a group, an author, a summary and a cover picture.
- **Publishing.** It is a draft until published. The publication date is stamped the first time it goes public, unless one is given; a date still to come keeps it off the public site until then.
- **The public list.** Nine articles to a page, filtered by group, newest, oldest, by title or most read, as tiles or a list.
- **Files.** An article's files are guides, templates, checklists, worksheets, reports, datasets, presentations, policies, conference papers and videos. They are kept in sections, in the order chosen, and a video plays in the page. Heratio's file types and its 200 MB limit for videos are kept.
- **Related articles.** One link joins two articles and is read from both ends, with its order and why they are related.
- **Comments.** A visitor's comment is shown at once, as Heratio shows it, with Heratio's guards: a field only robots fill in, a pause of 20 seconds between comments from one address, and a length limit. An editor moderates each comment as approved, pending or spam.
- **Reads.** A read is counted by the page's own script once the article has been seen for a few seconds, once a session. Reads by who edits the site are not counted.

What was changed from Heratio:

- Each institution has its own articles, with slugs unique within it.
- Its files and pictures are taken in as every file is: held to the upload limit and scanned for viruses. Each is kept in an OCFL object of its own (ADR 0022) and served only while its article is published. A deleted article takes its files with it.
- Who may publish descriptions edits the site, where Heratio asks for an administrator.
- Heratio's `protect_from_reset`, which keeps an article through its nightly demonstration reset, is not carried: the Workspace has no such reset.

**Landing pages**, after Heratio's `LandingPageService`, `BlockDataResolver` and `LandingPageController`.

- **Pages.** A page is built of blocks. The institution's default page is the catalogue's home; the catalogue itself remains at `?catalogue=1`, and every landing page links to it. Other pages are served at `/landing/{slug}`, and a page can be hidden from the public.
- **Blocks.** There are 20 block types, a vocabulary of their own:
  - rows of one, two or three columns that hold other blocks;
  - a hero banner, text in Markdown, an image carousel and a search box;
  - figures and browse panels;
  - recent items, featured items, the holdings list and a repository spotlight;
  - a map;
  - a header, a footer, quick links and a copyright bar;
  - a spacer and a divider.
- **Changing blocks.** Each block has its settings, its colours, its spacing and its width. It is shown or hidden, copied, and moved up or down.
- **Data.** Each block's data comes from what the public may read, decided by the public catalogue as for every public page (ADR 0005). Heratio's two rules are kept: a count is as public as a list, so a figure counts only what is published; and a block that cannot be filled shows nothing rather than failing the page. Counts are kept five minutes, as Heratio keeps them.
- **The map.** It shows the published places that have coordinates, on Leaflet 1.9.4 (copied from Heratio's vendored files) over OpenStreetMap tiles, and lists them for a browser without scripts.
- **A new page.** It can start from the homepage Heratio seeds: a hero banner, a search box, figures, browse panels and recent items.
- **Dashboards.** Each user's own dashboards are built the same way, and only their owner sees or changes them.

What was changed from Heratio:

- Each institution has its own pages, and the block types are a vocabulary with their default settings in code.
- A page's history is the audit log. Heratio's `atom_landing_page_version` is never written by Heratio's own code, and its separate audit log table is the Workspace's.
- A page keeps a style sheet of its own, which cannot close its style element, but no JavaScript of its own (Heratio's `custom_js`), so nothing typed into a page runs in a visitor's browser.
- Every link a block holds is checked: an address on the web or a path on this site, never `javascript:`.
- A dashboard belongs to its owner, where Heratio marks it with a layout value.
- Heratio's GLAM browser block (phase 21's other domains) and its second two-column block for public display (the same as the first) are not carried.

**Marketing** (`ahg-marketing`) is not carried. It holds AHG's own sales pages for Heratio: a comparison with AtoM, and a migration assessment form that posts into the AHG Workbench on Heratio's host. An institution running the Workspace has no use for either.

### Evidence (20a)

- `tests/Feature/PublicSiteTest.php`:
  - static pages:
    - an about page written in English and Afrikaans by an editor and refused to a contributor;
    - a second page at the same address refused, and so is an address that is not a slug;
    - the page served at `/about` and `/pages/about` without its script;
    - linked from the foot of the catalogue;
  - articles:
    - a draft with a cover and a PDF guide in a section, kept in OCFL, its file refused to the public until the article is published and served after;
    - a PHP file refused;
    - its HTML stripped;
    - a related article read from both ends;
    - an article dated next week kept off the list;
    - the list filtered by group;
    - a read counted only for the page's script, once a session;
    - deleting the article removes its file's OCFL object;
  - comments: a comment posted and shown, a second too soon refused, a robot's dropped, and one marked as spam taken off the article;
  - landing pages:
    - the starter page made as the default home, with a two-column row holding a holdings list and a map, and a row inside a row refused;
    - a `javascript:` link dropped;
    - the home showing the published fonds and not the unpublished one, with a figure of one record;
    - the catalogue still at `?catalogue=1`;
    - a hidden block left off, and a page not shown answering 404, with the catalogue's own home back;
  - dashboards: one made by a contributor, refused to an editor, and not served as a public page.
- `tests/Feature/AccessMatrixTest.php` builds a static page, two linked articles with a file and a comment, and a landing page with a block. Every new address refuses another institution's administrator and an account with no role.
