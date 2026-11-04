---
title: The union catalogue
category: Federation and open data
order: 40
routes: discover.union, federation.union-records
heratio: union-catalogue.md
---

# The union catalogue

A union catalogue lists, in one place, what a group of institutions hold: a regional network of archives, a university's libraries and archives, a consortium. In the Workspace, an institution keeps the members of its union and their records, and searches them together.

## Members

The institution's members are set under **Federation**, **Union catalogue**: each with its name, address and what it shares, and the institution itself as a member. The institution's own published records are always in it. Each other member's records are fetched from its union endpoint, every night, with when they were last fetched.

## Searching it

The public catalogue's results offer **Search the union catalogue for this** (`/discover/union`), where the institution has members. Each result names the member that holds it and links to it there.

## Offering ours

Every institution offers its own published records to unions it belongs to at `/union/records`, a list of each record with its reference, title, level, dates, repository and address. A draft is never in it.

## DSpace and Alma

A DSpace repository or an Alma library catalogue can be searched by its own protocol, as a peer, beside the union catalogue. See [Federation](/help/article/federation-user-guide).
