---
title: Landing pages and dashboards
category: Public site
order: 40
staff: true
routes: site.landing*, landing.*, dashboards*
heratio: landing-page-user-guide.md
---

# Landing pages and dashboards

A landing page is a page of the public site built of blocks: a banner, some text, a search, figures, collections with pictures, a map. The institution's default landing page becomes the catalogue's home; others are served at `/landing/` and their address. They are under **Public site**, **Landing pages** (`/site/landing-pages`), for anyone who may publish descriptions.

A **dashboard** is the same, for yourself: a page of your own, built of blocks from what the catalogue publishes, that only you see. **My dashboards** (`/dashboards`) is open to every signed-in user.

## Making a page

Give it a name and, if you like, an address and a description. Tick **The catalogue's home page** to make it the default. Keep **Start with a banner, a search, figures, browse panels and recent items** to begin from a working page rather than an empty one.

## Blocks

| Block | What it shows |
|---|---|
| Rows of 1, 2 or 3 columns | Other blocks, side by side |
| Hero banner | A large picture with a heading, a line under it and a button |
| Text | Words in Markdown |
| Image carousel | The catalogue's collections with pictures, or images you choose |
| Search box | A search of the catalogue |
| Statistics | How much of each kind is published |
| Browse panels | Cards leading into the catalogue, with how much each holds |
| Recent items | The descriptions published most recently |
| Featured items | Descriptions you choose |
| Holdings list | The top-level descriptions, of one repository or all |
| Repository spotlight | A repository with its holdings and how to reach it |
| Map | The published places, on a map |
| Header, footer, quick links, copyright bar | The page's furniture |
| Spacer and divider | Space, and a line |

**Add a block** puts a new one at the end; a row's columns each have their own **Add to this column**. Each block's **Settings** hold what it shows, its colours, the space above and below it, and whether it fills the width of the window. A setting that is a list, such as the figures to show or the links in a footer, is written as JSON, with an example given. The arrows move a block up or down; **Hide** takes it off the page without losing it; **Copy** makes another just after it.

## Only what is published

Every figure, list, picture and map comes from what the public may read, so a page never shows a draft, and a count never tells a visitor how much exists that they may not see. A block that cannot be filled shows nothing rather than breaking the page.

## Safe to build

Every link in a block must be an address on the web or a path on this site; anything else, such as a `javascript:` link, is dropped. A page can have a style sheet of its own, but no script, so nothing typed into a page runs in a visitor's browser.

## Page settings

**Page settings** holds its name, address and description; whether it is **shown on the public site**; whether it is the catalogue's home; a title and description for search engines; and its style classes and style sheet. A page not shown answers that it is not found.

## The catalogue behind it

When a landing page is the catalogue's home, the catalogue's own home is still at `?catalogue=1`, and every landing page links to it.
