---
title: Searching the catalogue
category: Search and discovery
order: 10
routes: search, discover.home
heratio: search-user-guide.md
---

# Searching the catalogue

One search box finds everything the catalogue describes: units of description, authority records, repositories, places and activities, by any of their names, titles, identifiers and the text of their descriptions. The search runs in PostgreSQL's full-text search, so it understands word forms (*councils* finds *council*) and weighs a match in a title above one in a long note.

There are two searches:

- **The public catalogue's** (`/discover`), for anyone: it finds only what is published.
- **The Workspace's** (`/search`), for staff: it finds drafts too, with what your role lets you read.

## Writing a query

Type words as you would into a web search engine:

| You type | It finds |
|---|---|
| `bridge council` | Both words, anywhere |
| `"town council"` | The phrase |
| `council -minutes` | *council*, leaving out anything with *minutes* |
| `bridge OR ferry` | Either word |

## Narrowing the results

The results page has facets beside the list:

- **Kind**: archival descriptions, authority records, activities, places or repositories, with how many of each were found;
- **Level of description**: fonds, series, file, item and the institution's own levels;
- **Repository**: which institution holds it;
- **Dates**: a range of years, from and to.

Choose a value to narrow the list; choose **Everything** or **Any** to widen it again. The words found are picked out in each result.

## Searching other catalogues

Where the institution federates with others, a search in the public catalogue offers **Search other catalogues for this**, and, where it belongs to a union catalogue, **Search the union catalogue for this**. See [Federation](/help/article/federation-user-guide).

## Keeping a search

Signed-in users can **Save this search**, to run it again later and be told when new results appear. See [Saved searches](/help/article/saved-searches).

## Search statistics

**Search statistics** (`/search/statistics`, for administrators) shows what people searched for, in the public catalogue and in the Workspace: what was searched most, what found nothing, and how many results searches found on average. The searches that found nothing are the most useful list: they show what people look for that the catalogue does not yet describe, or describes in other words. Nothing about who searched is kept.

## When something does not appear

The search index is brought up to date as descriptions change. If a published unit does not appear in the public search, check that its title or name is published, not only the unit; an entity is found by the published values it has.
