---
title: Federation
category: Federation and open data
order: 30
routes: federation.*, discover.federated
heratio: federation-user-guide.md
---

# Federation

Federation joins catalogues. A researcher searching one institution's catalogue can search its partners' too, in one go; institutions can harvest each other's records and share vocabularies. The Workspace federates with other Workspaces, with other institutions on the same installation, and with Heratio.

The screens are under **Admin**, **Federation** (`/federation`), for those who administer the institution.

## Peers

A peer is another catalogue the institution federates with: another institution here, another Workspace, a Heratio, a DSpace or Alma catalogue, or any OAI-PMH repository. For each, record its name, address, kind and, where it asks for one, its API key (kept encrypted). Switch a peer off to stop asking it without losing it.

## Searching other catalogues

With a peer's search switched on, the public catalogue's results offer **Search other catalogues for this** (`/discover/federated`). The search goes to every such peer at once, within a time limit, and their answers come back together, each marked with where it came from. Answers are kept for fifteen minutes, so the same search a moment later is quick.

An institution here is read as the public sees it. Another Workspace or a Heratio is asked through its signed federation API.

## What the institution shares

An institution shares only what it publishes, and only when it chooses. **What the institution shares** sets whether it answers peers at all, and which levels of description it offers. A draft is never shared.

## Signed answers and trust

Every answer is signed with the installation's Ed25519 key, in Heratio's scheme, and the key is published at `/.well-known/openric-federation-key`. The first time a peer answers, its key is pinned; an answer later signed with a different key is refused, so a peer cannot be impersonated.

Each peer has a **trust level**. An untrusted peer is never asked; a verified peer's unsigned answers are dropped. An institution can choose to drop every unverified answer. A peer can also be given a rate limit, and the kinds of request it may be sent: search, the union catalogue, vocabularies.

## Harvesting

An OAI-PMH peer can be **harvested**: its records taken in, on an interval, into draft units of description in a repository of their own, mapped from Dublin Core as Heratio maps them. Later harvests bring them up to date and delete what the peer deleted. Each harvest is listed, with what it made, changed and deleted.

## Vocabularies

An institution can share its own vocabularies with its peers, and take in theirs, through SKOS. For each, choose the direction and what to do when the two differ: keep ours or take theirs. A concept taken in keeps the peer's IRI as an exact match.

## Discovery and the network

The Workspace looks at each peer's protocol description every night, to see what it offers and how well. The **network** (`/open-data/network`) lists the catalogues the institution federates with, for the public. Another institution can ask to join from `/federation/join`; a request approved becomes a peer.

## See also

- [The union catalogue](/help/article/union-catalogue)
- [Europeana](/help/article/europeana-publish)
- [Loans between institutions](/help/article/loan-user-guide)
