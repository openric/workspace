---
title: Records in Contexts in the Workspace
category: Getting started
order: 15
routes: discover.graph
heratio: ric-user-guide.md
---

# Records in Contexts in the Workspace

Records in Contexts (RiC) is the International Council on Archives' standard for describing archives as a network: records, the people and bodies who made and kept them, the activities that produced them, the places and rules they belong with, and the relations between all of these. RiC-CM 1.0 is the conceptual model; RiC-O 1.1 is the ontology that writes it down as linked data. The Workspace is built on both.

## Why it matters day to day

A traditional catalogue is a tree of descriptions, with names and places typed into fields. In RiC, the person who created a series is an authority record of their own, joined to the series by a relation that says how, and since when. Describe them once and every record they touch shows them, every search for them finds those records, and the relation itself can carry its dates, its evidence and who asserted it.

You still describe in the terms you know. The forms follow ISAD(G), ISAAR(CPF) and ISDIAH, area by area. What changes is underneath: each field is stored as the RiC term it is, and each screen shows that term next to the field, so you can see what you are recording.

## The entities

| RiC entity | In the Workspace |
|---|---|
| `rico:Record`, `rico:RecordSet`, `rico:RecordPart` | Units of description, at any level |
| `rico:Person`, `rico:Family`, `rico:CorporateBody` | Authority records, and repositories |
| `rico:Activity` | Activities: creation, accumulation, accessions, functions |
| `rico:Place` | Places, with coordinates |
| `rico:Rule` | Rules: laws, mandates, policies |
| `rico:Instantiation` | The copies of a record: scans, recordings, files |
| `rico:DateRange` | Every date, with what it is the date of |

Where the Workspace keeps something RiC-O has no term for, such as an accession's valuation or a reading room booking, it says so in the scope note of the vocabulary that holds it, and keeps it out of the RiC-O export or writes it with an OpenRiC extension term (`openricx:`).

## Relations

Relations are what make it RiC. See [Relations between entities](/help/article/relations-user-guide).

## Seeing the network

Every published entity in the public catalogue has a **graph** view (`/discover/graph/{kind}/{id}`): the entity in the middle, the entities it is related to around it, each relation labelled, one step out or two. Click any entity to move to it.

For questions across the whole catalogue, such as every record created by someone who worked for a given body, the [graph explorer](/help/article/graph-explorer-user-guide) builds them without writing SPARQL, where an institution has the graph switched on.

## RiC-O out

Every published entity has a persistent IRI, and its RiC-O, as JSON-LD, is served by the OpenRiC API. The same RiC-O feeds the API, OAI-PMH, the RiC-O exports, the SPARQL endpoint and the federation. Each release is tested against the OpenRiC SHACL shapes, so the RiC-O the Workspace writes conforms to the OpenRiC profiles published at openric.org.
