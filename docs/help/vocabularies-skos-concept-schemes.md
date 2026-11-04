---
title: Vocabularies
category: Describing
order: 40
routes: vocabularies.*
heratio: vocabularies-skos-concept-schemes.md
---

# Vocabularies

Almost every choice you make in the Workspace comes from a vocabulary: levels of description, kinds of date, kinds of name, relation types, subjects, genres, accession statuses, condition grades, rights statements and hundreds more. None of these are fixed lists built into the code. Each is a SKOS concept scheme kept in the database, so an institution can add its own terms, translate them and share them.

The screens are under **Describe**, **Vocabularies** (`/vocabularies`).

## Two kinds of vocabulary

**Bundled vocabularies** ship with the Workspace, a little over 370 of them. Each concept has a stable IRI under `https://workspace.openric.org/vocab/`, an English and Afrikaans label, and a scope note that says which RiC-O term it is, or that it is not a RiC term at all and why it is kept. You cannot delete a bundled concept, but you can add your own narrower concepts beside it.

**Your institution's vocabularies** are your own: local subjects, a place hierarchy, a list of your own collection codes. Make one with **New**, choose what kind it is (subjects, places, genres and so on), and add concepts to it.

## Concepts

A concept has:

- a preferred label in each language, and alternative and hidden labels, so a search for an old or misspelt form still finds it;
- a code (its notation), if you use one;
- broader and narrower concepts, so a vocabulary can be a hierarchy;
- related concepts;
- matches in other vocabularies, such as LCSH, the Getty AAT or Wikidata, each an IRI with how closely it matches (exact, close, broader, narrower or related).

See [Working with concepts](/help/article/term-taxonomy-user-guide) for adding, moving, merging and deprecating them.

## Importing and exporting

**Import SKOS** reads a SKOS file in Turtle, RDF/XML or N-Triples into a vocabulary of yours: each `skos:Concept` becomes a concept with its labels, notation, hierarchy, notes and matches. Importing the same file again updates rather than duplicates.

**Export SKOS** gives the vocabulary back as Turtle, RDF/XML, JSON-LD or N-Triples, to hand to a partner or another system.

## The public view

Every bundled vocabulary, and every one of yours, is published as linked data at `/vocab/{scheme}`, with each concept at `/vocab/{scheme}/{code}`, as a page for people and as Turtle for machines. That is the address every exported record uses when it names a concept, so a reader of your RiC-O can always look a term up.

## Sharing vocabularies with peers

An institution in a federation can share its vocabularies and take in those of its peers, keeping its own or taking theirs where they differ. See [Federation](/help/article/federation-user-guide).
