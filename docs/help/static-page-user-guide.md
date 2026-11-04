---
title: Static pages
category: Public site
order: 10
staff: true
routes: site.pages*
heratio: static-page-user-guide.md
---

# Static pages

A static page is a page of the public site that is not part of the catalogue: about the institution, its privacy notice, its terms of use, how to visit, how to donate. They are under **Public site**, **Static pages** (`/site/pages`), for anyone who may publish descriptions.

## Writing one

1. Choose **New page**, or one of **Write the about page**, **the privacy page** or **the terms page**.
2. Give its **address**: lower-case letters, digits and hyphens, such as `visiting-us`.
3. Give its **title** and write its **text** in Markdown: headings with `#`, lists with `-`, links as `[words](address)`.
4. Save.

The page is in the language you were working in. To translate it, give another language at the top of the page, such as `af`, and choose **Write in this language**: write its title and text there and save. **Written in** lists the languages it has.

## Where it appears

- About, privacy and terms are served at `/about`, `/privacy` and `/terms`, and once they exist they are linked from the foot of every public page.
- Any other page is served at `/pages/` and its address, for you to link to from a landing page, an article or another page.

A visitor sees the page in their language where it has one, and in the language it was first written in otherwise.

## Safe to write

Any HTML typed into the text is stripped when the page is shown, so a page can never carry a script. Use Markdown for everything you need.

## Deleting

**Delete page** removes it in every language at once. Remember to remove any links to it.
