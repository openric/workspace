---
title: The record set explorer
category: Search and discovery
order: 25
routes: discover.explorer, explorer.settings
heratio: collection-linking-and-explorer-user-guide.md
---

# The record set explorer

A scrapbook of 46 pages, or a series of a few hundred letters, is easier to know by its names than by its titles: who is in it, where it happened, what it was about. The explorer starts from those names. Choose a person, a place or an event, and it draws the units that carry the name, with the other names printed beside them.

Any published record set with units inside it has one. Its public page and its RiC graph link to it as **Explore the people, places and events in it**; the address is `/discover/records/{id}/explorer`.

## Where the names come from

- **The catalogue**: the people and organisations, places, events and subjects the set's units are related to, each linked to its own page.
- **The text**: names found in the units' transcripts and proposed for review (see [AI tools](/help/article/ai-tools-user-guide)). Only names accepted, or linked to an authority record, are shown, unless the set's settings below show the rest.

Spellings of one name, such as "The Star" and "Star", are grouped as one, with every spelling listed. A draft unit, and anything the public may not read, is never in it.

## Using it

1. **Where to start** lists the names on most units. Click one, or search above for a person, place, event or title.
2. The graph draws the chosen name in the middle, the units that carry it around it, and the names on those units around them. The panel beside it lists the same connections, for anyone who prefers a list.
3. Click a unit to see its picture (zoomable), its description and, where the reader may have its reference copy, its transcript.
4. **Double-click** a node to drill down: what it connects to is added, and what is drawn stays. **Where you have been** keeps the trail; **Back** steps along it, **Start again** clears it.
5. The timeline below places the units by their dates.

**Right-click** gives the options:

- on a name: centre on it, drill down, show only its kind, hide it from the view, add it to the hide list (staff), open its page, show it in the RiC graph;
- on a unit: open the image, read the transcript, open the record, show it in the RiC graph, download the image;
- on the graph: fit to the screen, back, start again, save this view as a picture.

## Explorer settings (staff)

Anyone who may describe the set sees **Explorer settings (staff)** at the foot of the page:

- **General words to hide**, one a line or separated by commas, such as a newspaper's name printed on every cutting. Hidden names can still be shown with **Show the names hidden from the view**.
- **The fewest units a name must be on**, to leave out names that appear once.
- **Show names found in the text that are not yet reviewed**, and of those, only names found with at least a given confidence (0 to 1). The page then says that such names are given only as printed.

The settings are kept with the set. What the explorer shows is rebuilt whenever anything in the institution changes, and five minutes at most after an embargo starts or ends by its date.

## Names found elsewhere

Where the names were read outside the Workspace, by a model run elsewhere or from another system's export, an operator takes them in from a CSV as proposals for review:

```
sudo -u www-data php artisan workspace:explorer:names names.csv --as=editor@example.org --source=qwen3
```

The CSV has the headings `record`, `type`, `value` and, optionally, `confidence`. The record is the unit's IRI, id or reference code; the type is spaCy's label (PERSON, ORG and NORP become people and organisations; GPE, LOC and FAC places; other labels are not taken in). The account named by `--as` must be allowed to describe each unit. A name the unit already has, waiting or accepted, is skipped, so a file can be taken in again. The command reports how many names were proposed, how many were already there and how many were not taken in.

## The same as Heratio's

The explorer's data has the shape of Heratio's explorer (ExplorerDataService), so a Heratio export and a Workspace explorer read alike.
