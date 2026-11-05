---
title: Searching by meaning
category: AI assistance
order: 60
staff: true
routes: search, ai.*
heratio: semantic-search-technical.md
---

# Searching by meaning

A search by the words finds what holds them. A search by meaning also finds what is about the same thing in other words: "crossing" finds the plans for a bridge.

## Switching it on

Under **Admin**, then **AI**, the **Search** settings hold:

- **Search by meaning as well as by the words.** Every entry of the search index, as the public sees it and as staff do, is turned into a vector by the AI gateway's embedding model (`nomic-embed-text` unless the institution names another). New and changed entries are done every ten minutes. The page shows how many are done.
- **Widen a search with the other labels of the concepts its words name.** A word that names a concept of the institution's subjects, places or other descriptive vocabularies, by its preferred or an alternative label, brings that concept's other labels into the search. This is on by default and needs no AI. "Viaducts" then also finds "Bridges".
- **Widen a search with terms a language model suggests**, through the gateway.
- **At most so many terms** are added, five unless set otherwise.
- **How alike an entry must be to be found by meaning**, 0.40 unless set otherwise. The nearest entries are ranked first, and those less alike than this are left out, so a search by meaning does not "find" the whole collection. A higher number finds fewer and closer.
- **Put first, for a signed-in account, what matches its recent searches.** The same results, reordered for that account. Its last 25 searches are kept for this, only while the setting is on, and with nothing else; the search statistics keep no one's name.

Search by meaning needs AI switched on for the institution and pgvector in the database (see INSTALL.md).

## Searching

On the search page, staff or public, **Find** offers three modes:

- **by the words**: the full-text search;
- **by meaning**: the nearest entries first, whether or not they hold the words;
- **by the words and by meaning**: both lists put together by rank.

The filters (kind, level, repository, years) and the facets work in every mode. The page says which words a search was widened with. Every query turned into a vector is a receipt in the log of inferences.

## Related by meaning

A unit's page lists the units nearest it in meaning, as the same audience sees them. Staff see only the related units they may read.

## Ways into the collection

The public catalogue's **Ways into the collection** (`/discover/ways-in`) offers:

- the **themes** (subjects) most published units sit under;
- the **places** they are of or about;
- the **people and organisations** who made or gathered them;
- the **kinds of record** (documentary forms);
- **the collection over time**, by century and decade.

Each leads to its published units. Only what a visitor may see is counted.

## Grounding for agents

`GET /ground?q=...&max=5` in the RiC API answers with the public entities a query names. Each comes as a pack of the facts its RiC-O holds (types, label, dates, properties, relations, provenance), with a block an agent may put before its prompt so it resolves names to the archive's facts rather than guessing. This is the OpenRiC Graph-Grounding profile, after Heratio's `/api/ric/ground`.

## Compared with Heratio

Heratio searches by meaning with a Qdrant collection and widens searches from a thesaurus table of its own. The Workspace keeps the vectors in its own database (pgvector), so they obey the same rules of institution and audience as the records. Its synonyms are the SKOS labels its vocabularies already hold. Its grounding packs are read from the public RiC-O, the projection its triplestore is fed from.
