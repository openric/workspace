---
title: Working with concepts
category: Describing
order: 45
routes: concepts.*
heratio: term-taxonomy-user-guide.md
---

# Working with concepts

A concept is one term of a vocabulary: a subject, a place, a level of description, a kind of relation. This guide covers what you can do with one. For vocabularies as a whole, see [Vocabularies](/help/article/vocabularies-skos-concept-schemes).

## Adding a concept

Open the vocabulary and choose **Add a concept**, or open a concept and choose **Add a narrower concept** to put the new one below it. Give its preferred label in your language; add its labels in other languages, alternative labels, hidden labels and a code if you need them. It can be used straight away.

## Moving one

**Move** puts a concept, and every concept below it, under another broader concept, or at the top of the vocabulary.

## Related concepts and matches

**Related** joins two concepts of equal standing that are worth seeing together. **Matches in other vocabularies** records that the concept is the same as, or close to, a concept elsewhere, such as an LCSH heading or a Wikidata item, by its IRI. Matches are exported with the concept, so a reader can follow them.

## Merging duplicates

When two concepts turned out to be the same, open the one to drop and choose **Merge into**, then the one to keep. Everything moves across: its narrower concepts, the units of description and places that use it, its related concepts and matches. Its labels become alternative labels of the one kept, so a search for them still finds it. The dropped concept is deprecated and points to the one kept.

## Deleting

A concept is never removed outright, so its IRI always resolves and every record that cites it stays readable. **Delete** deprecates it: it is no longer offered for new descriptions and is marked as deprecated where it appears. **Restore** brings it back. A vocabulary of your own that holds no concepts can be deleted altogether.

## Concepts that are relation types

A concept in the relation types vocabulary can be made a relation type of your own: say which bundled type it narrows, how it reads in each direction (such as *is the godparent of* and *is the godchild of*), and whether it reads the same both ways. It then joins what the bundled type joins, takes its agent role, and is exported as that bundled type's RiC-O property, so your own distinctions never break the export.

## Community protocols

A concept can carry a community protocol: a Traditional Knowledge or Biocultural label from Local Contexts, the community that owns it, and an access condition. An open, attribution or non-commercial condition shows the label but hides nothing. A restricted condition, such as seasonal, gendered or secret and sacred, hides the concept and every description that uses it from the public, in the catalogue, the API, OAI-PMH and exports, while staff with access still see them.
