---
title: Search tips
category: Search and discovery
order: 20
heratio: advanced-search-user-guide.md
---

# Search tips

The search box takes more than plain words. This guide gathers the ways to find exactly what you are after. For the search itself, see [Searching the catalogue](/help/article/search-user-guide).

## Phrases, exclusions and alternatives

- Put a phrase in double quotes: `"deed of transfer"`.
- Put a minus sign before a word to leave it out: `photographs -postcards`.
- Use `OR`, in capitals, for either of two words: `Pretoria OR Tshwane`.
- Combine them: `"town council" minutes -draft`.

## Word forms

Search reduces words to their stem, so *photograph*, *photographs* and *photographed* find each other. Very common words such as *the* and *of* are passed over, so a phrase still matches when they differ.

## Names and identifiers

Every published name of an entity finds it, not only its authorised form: a person's maiden name, a body's former name, a place's older spelling. A reference code or identifier typed exactly finds the unit it belongs to.

## Narrowing

After a search, the facets narrow by kind, level of description, repository and years. A range of years finds units whose dates overlap it, so `1900` to `1910` finds a series of 1895 to 1905.

## Regular expressions

Tick **As a regular expression** beside the search box to search with a pattern instead of words. The pattern is matched against the names, identifiers and text of each entry, without regard to case, in PostgreSQL's regular-expression syntax:

- `^Minutes of` finds entries whose title begins with those words.
- `19[0-4][0-9]` finds any year from 1900 to 1949 written in the text.
- `water.{0,20}supply` finds *water* followed within twenty characters by *supply*.

A pattern searches only what a word search would: the public catalogue sees only what is published. A pattern that takes longer than five seconds is stopped; anchor it with `^`, or name more letters, to make it quicker. A pattern the search cannot read is explained instead of searched.

## Browsing instead of searching

Searching suits a question you can put in words. To see what a collection holds, start from its top: the public catalogue's home lists every top-level collection and every repository, each opening onto what is inside it, and every entity's page links to the entities related to it. The [graph explorer](/help/article/graph-explorer-user-guide) answers questions about relations, such as every record created by members of a given family.

## Nothing found

- Try fewer words, or a word less specific.
- Try another form of a name, or the place under its older name.
- Check the spelling. When no entry holds every word, search shows instead the entries whose title holds a word closest to what you typed, such as *Minutes* for *Minuets*, and says that these are close matches. A search with a phrase in quotes or a word left out is taken as meant, and shows no close matches.
- In the public catalogue, the record may simply not be published yet.
