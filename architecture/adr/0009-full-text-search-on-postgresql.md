# ADR 0009: Full-text search on PostgreSQL

- Status: accepted
- Date: 2026-10-27
- Deciders: Dr Johan Pieterse
- Gate: G4

## Context

WS-016 asks for search that respects access rules. The Workspace searched names and identifiers by substring (ilike), on the public catalogue and on each staff list. Heratio searches with Elasticsearch (ahg-search: ElasticsearchService, query syntax, facets, highlighting, search analytics).

## Options

1. Elasticsearch or OpenSearch, as Heratio does. Relevance tuning and scale are its strengths. But it is another service to run, secure, back up and keep in step with the database, and every access rule has to be mirrored in it.
2. PostgreSQL's full-text search, in the database the Workspace already uses: tsvector, GIN indexes, websearch_to_tsquery, ts_rank_cd and ts_headline, with the trusted unaccent extension.

## Decision

Option 2.
- An index table, `search_entries`, holds two entries per described entity (unit of description, authority record, activity, place, repository):
  - the public entry: only published names and values of an entity the Public role may read;
  - the staff entry: everything.

  A search reads one or the other, so it can never show a draft to someone who may not see it.
- Each entry has two vectors:
  - one without stemming and without accents (simple and unaccent), for every language;
  - one stemmed in each value's own language: PostgreSQL's configuration named by the language's English name (en gives english, fr gives french), and simple where there is none, as for Afrikaans and the South African languages.
- Weights: names A, identifiers B, values C.
- The query takes the syntax people type into a search engine: words, "a phrase", -word, OR.
- Results are ranked by both vectors, the matches highlighted (the catalogue's text escaped, only <mark> added), with facets by kind, level of description and repository, and a range of years.
- The index follows the store. A change to a name, a value, an identifier, a date or an entity marks the entity, and marked entities are indexed again once, when the request or command ends. `php artisan workspace:search:rebuild` builds it from scratch, as the migration does.
- Copied from Heratio: its behaviour, not its code, which speaks to Elasticsearch.

## Evidence

`tests/Feature/SearchTest.php` covers:
- a draft found by staff and never by the public;
- stemming (meeting finds meetings) and accents (etude finds étude);
- a phrase, exclusion and OR;
- facets and a range of years;
- highlighting that lets no markup through;
- the public catalogue page;
- a record withdrawn through the screens leaving the public index.

## Consequences

No new service to run. A catalogue of some hundreds of thousands of entities is well within PostgreSQL's full-text search. Re-indexing happens at the end of each request, so a large import pays for its index then.

Revisit if relevance tuning, synonyms or search across very large federated catalogues are needed: the index table is derived and can be fed to another engine.

Staff search over the staff entries and search statistics without visitors' addresses followed in v0.0.61. Next: search by meaning (phase 17).
