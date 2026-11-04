---
title: Open data and harvesting
category: Federation and open data
order: 10
heratio: oai-user-guide.md
---

# Open data and harvesting

Everything an institution publishes is open data. Aggregators, union catalogues, researchers' tools and other archives can read it, in standard ways, without asking anyone. Only what is published is ever served.

## The OpenRiC API

The API serves every published entity as RiC-O, by the OpenRiC specification: units of description, authority records, repositories, places, activities, rules and instantiations, as JSON-LD, with search, lists and autocomplete. Its description is at `/api/ric/v1/openapi.json`, which any OpenAPI tool can read; see [The API](/help/article/api-technical-reference). Reading is open; writing, such as proposing a change, needs an API key with the write scope.

## OAI-PMH

The OAI-PMH 2.0 provider (`/api/ric/v1/oai`) is the common harvesting protocol of archives and libraries. It answers all six verbs, `Identify`, `ListMetadataFormats`, `ListSets`, `ListIdentifiers`, `ListRecords` and `GetRecord`, by GET or POST.

| metadataPrefix | What a record holds |
|---|---|
| `oai_dc` | Simple Dublin Core |
| `rico_ld` | The unit's full RiC-O, as JSON-LD |

**Sets** follow the hierarchy: `records` holds every unit, and `records:{id}` a fonds and every unit inside it, so a harvester can take one collection. **Incremental harvesting** with `from` and `until` takes only what changed, a unit's datestamp being the last change to it or to any of its values, names, identifiers and dates. Lists come 100 at a time with a resumption token.

Some useful requests:

```
/api/ric/v1/oai?verb=Identify
/api/ric/v1/oai?verb=ListSets
/api/ric/v1/oai?verb=ListRecords&metadataPrefix=rico_ld&from=2026-11-01
```

The provider does not keep deleted records (`deletedRecord` is `no`). To learn what was withdrawn, use ResourceSync or the IIIF change feed, which list withdrawals.

## ResourceSync

ResourceSync (ANSI/NISO Z39.99) lets a harvester keep a copy in step without taking everything again. See [ResourceSync](/help/article/open-data-resourcesync-user-guide).

## Sitemaps, feeds and IIIF

- `/sitemap.xml`: a sitemap of every published page, for search engines, with `/robots.txt` pointing to it.
- `/feed.atom`: an Atom feed of the descriptions published most recently.
- IIIF manifests, collections and the Change Discovery feed; see [Viewers and IIIF](/help/article/iiif-integration-user-guide).

## The SPARQL endpoint

Where the institution has the graph switched on, `/sparql` answers SPARQL queries over all of its published RiC-O. See [The graph explorer](/help/article/graph-explorer-user-guide).
