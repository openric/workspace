---
title: The API
category: Technical reference
order: 10
heratio: api-technical-reference.md
---

# The API

The OpenRiC API serves an institution's published catalogue as RiC-O, by the OpenRiC specification (openric.org). It is what other systems, aggregators, researchers' scripts and AI assistants read the catalogue through. Its full description, for any OpenAPI tool, is at `/api/ric/v1/openapi.json`.

## Reading

Reading what is published needs no key. Every answer is JSON-LD in RiC-O, with a `@context`, and every entity is named by its persistent IRI.

| Address | What it gives |
|---|---|
| `/api/ric/v1` | The service: what it offers, and its version |
| `/api/ric/v1/records`, `/records/{id}` | Units of description |
| `/api/ric/v1/agents`, `/agents/{id}` | Authority records |
| `/api/ric/v1/repositories`, `/repositories/{id}` | Repositories |
| `/api/ric/v1/places`, `/places/{id}`, `/places/within` | Places, and those within a bounding box |
| `/api/ric/v1/activities`, `/functions`, `/rules` | Activities, functions and rules |
| `/api/ric/v1/instantiations`, `/records/{id}/files` | Instantiations, and a record's files |
| `/api/ric/v1/relations`, `/relations-for/{id}` | Relations, all or of one entity |
| `/api/ric/v1/hierarchy/{id}` | A record's place in its hierarchy |
| `/api/ric/v1/graph` | The neighbourhood of an entity, as a graph |
| `/api/ric/v1/autocomplete` | Entities whose names begin with what is typed |
| `/api/ric/v1/vocabulary` | The vocabularies the answers use |
| `/api/ric/v1/records/{id}/export` | A record's full RiC-O export |
| `/api/ric/v1/dataset`, `/changelog` | The catalogue as a dataset, and what changed |
| `/api/ric/v1/graphql` | The same, by GraphQL |
| `/api/ric/v1/oai` | OAI-PMH; see [Open data and harvesting](/help/article/oai-user-guide) |
| `/api/ric/v1/health` | Whether the service is up |

Lists are paged and take filters, such as a repository, a level or a search; the OpenAPI description gives each one's parameters. Errors come as RFC 9457 problem details (`application/problem+json`).

## API keys

Writing needs an API key. A signed-in user makes their own under **API keys** (`/account/api-keys`), each with its scopes (read, write) and, if wished, an end date. The key is shown once; keep it safe. Send it in the `X-API-Key` header, or as `Authorization: Bearer` and the key.

A key acts as the person who made it, with their permissions and no more. Revoke a key from the same page, or an administrator from the person's account.

## Writing

| Address | What it does |
|---|---|
| `POST /api/ric/v1/records/{id}/proposals` | Proposes a change to a record, reviewed by a person before it is applied |
| `GET /api/ric/v1/proposals` | Lists your proposals and where each stands |
| `POST /api/ric/v1/intake` | Sends a capture station's files to become records; see [Scanning and watched folders](/help/article/scan-user-guide) |

Nothing written through the API changes a description directly: a proposal waits for review, as every machine-made change does.

## Limits

The API allows 600 requests a minute from one address. A harvester that needs everything should use OAI-PMH, ResourceSync or the dataset rather than walking every record.

## Following an IRI

A persistent IRI of a unit of description, an authority record or a repository resolves: a browser is sent to its public page, and a client asking for JSON-LD (`Accept: application/ld+json`) is sent by a 303 redirect to its RiC-O here, so the IRIs in every export can be followed by any linked-data tool.
