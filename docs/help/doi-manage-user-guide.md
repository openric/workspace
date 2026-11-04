---
title: DOIs
category: Federation and open data
order: 80
staff: true
routes: dois.*, records.doi, records.funding.*
heratio: doi-manage-user-guide.md
---

# DOIs

A DOI (Digital Object Identifier) is a persistent identifier that researchers, journals and repositories trust, resolving at `https://doi.org/`. The Workspace registers DOIs for published units of description at DataCite, under the institution's own prefix, and each DOI resolves to the unit's persistent IRI.

The screens are under **Public site**, **DOIs** (`/dois`), for anyone who may publish descriptions.

## Setting it up

DOIs need the institution's DataCite account: its repository ID, password and prefix, and whether it is DataCite's test service or production. It is the same account the research services use, kept under **Research**, **ORCID and DataCite**; the password is kept encrypted. Until it is set up, nothing is sent to DataCite.

Start with the **test service**. A DOI registered there behaves as a real one does, but does not resolve at doi.org, so an institution can try everything before it registers DOIs for good. A DOI keeps the service it was registered with: one registered with the test service cannot be changed through a production account, or the other way round.

**DOI settings** (`/dois/settings`, for those who administer the institution) holds:

- whether a unit is given a DOI **when it is published**, only for some levels of description, and only if it has a file;
- the **publisher**, the institution's name unless given;
- the **resource type**: *Collection* for a unit with units inside it and *Text* otherwise, unless one is chosen;
- the **suffix pattern**, from `{repository_code}`, `{reference_code}`, `{year}` and `{id}`, such as `{repository_code}/{year}/{id}`;
- whether to report **usage events** to DataCite, with a token from DataCite Fabrica.

## A DOI for one unit

On a published unit's page, open **DOI and funding**:

- **Mint a DOI** registers it at once, findable in DataCite's search;
- **Reserve one as a draft** registers it without publishing it, to be made findable later with **Make it findable**;
- **Queue it** leaves it to the queue.

A unit must be published first: registering a DOI makes it public. Once minted, the DOI is one of the unit's identifiers, so it goes out in its RiC-O and its other exports; it is shown on its public page, and citations of the unit give it.

Later, **Send it again** sends the unit's metadata to DataCite again; **Ask DataCite** asks what state the DOI is in; **Hide it** takes it out of DataCite's search, leaving it registered and resolving, since a DOI must always resolve. Publishing a unit again queues its DOI to be sent again by itself.

## What DataCite is told

The DOI's metadata, in the DataCite Metadata Schema 4, is made from what the public reads of the unit:

- its title, and its creators, each with their ORCID iD, ISNI or ROR ID where the authority record has one;
- the publisher and the year, from its earliest date;
- its resource type;
- its scope and content, archival history and acquisition;
- its subjects and genres, its dates, its language and its reference code;
- the record set it is part of, by that one's DOI, or its IRI where it has none;
- its places, with their coordinates;
- its funding;
- the rights in force, by their RightsStatements.org or Creative Commons address.

## Funding

**Add funding** on a unit records who funded the work: the funder, its identifier and scheme (ROR, Crossref Funder ID, ISNI or GRID), and the award's number, address and title. A CSV of funding can be taken in at once with `php artisan workspace:doi:funding-import`, each row naming its unit by IRI or reference code.

## Many at once

**Mint many** (`/dois/batch`) queues a DOI for each published unit that has none, of one repository or level, or all, up to a number.

## The queue

**Queue** (`/dois/queue`) lists every request to DataCite: what it is, for which unit, its status and its attempts. The queue is sent every five minutes; **Send them now** does not wait. A request DataCite refuses is tried again later, five minutes more each time, three times in all, then marked failed with DataCite's answer; **Try again** puts it back.

## Seeing what was done

**Every DOI** lists them by state. A DOI's own page shows what was registered, where it resolves, and every request made of DataCite for it, with its outcome. **Report** gives the DOIs by state, by month and by repository, the usage events, and what DataCite refused. The person who asked is sent an e-mail when a DOI is registered, or refused.

## Usage events

With usage events switched on, a public view of a unit with a findable DOI, or a download of one of its files, is reported to DataCite's Events API, once a DOI and day, so the DOI's page at DataCite shows how it is used. `php artisan workspace:doi:metrics-backfill` reports the views and downloads already counted.
