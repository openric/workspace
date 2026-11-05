---
title: Heritage at risk and displaced heritage
category: Preservation
order: 70
staff: true
routes: heritage.*, discover.at-risk*, discover.displaced*
heratio: endangered-heritage-capture-priority.md
---

# Heritage at risk and displaced heritage

Two registers sit side by side here. One lists what should be captured before it is lost. The other traces objects held far from where they come from. Neither is a verdict: a flag on either is a reason to look, nothing more.

## Flagging a description for capture

Heritage can be lost before anyone records it: to conflict, a changing climate, fragile materials, lost funding, displacement, or simply because no durable digital copy was ever made. Anyone who may change a description can flag it from its page, under **Heritage at risk**, with:

- a **risk category**: conflict or unrest, climate or environment, material decay, funding or stewardship risk, displacement, a digitisation gap, or another risk;
- an **urgency**: critical, high, medium or low, a judgement of how soon to capture it, not a forecast of how soon it might be lost;
- a **capture status**: flagged, capture in progress, captured, or unflagged;
- a factual **reason**. State what is known and leave out speculation.

A description has one flag. Flagging it again changes that flag.

## The worklist

**Heritage at risk** in the menu puts everything still flagged or in progress in order. Each flag scores by its urgency (critical 1000, high 100, medium 10, low 1), with five more where no digital surrogate exists yet, so a critical digitisation gap comes first. Set the capture status from the worklist as the work moves. A captured or unflagged description leaves the worklist and stays in the full list below it.

## In the public catalogue and across the federation

Those who manage the institution choose three things under the worklist, all off to start with:

- **Show the register in the public catalogue.** It gains a **Heritage at risk** page with the counts by risk and urgency, how far capture has come (captured, out of captured and still waiting), and the published descriptions still waiting, most urgent first. The figures are also open data, as JSON. A description the public catalogue does not show is counted but never listed.
- **Let federation peers read the published register.** Peers read it at `/federation/endangered`, or at Heratio's own address, `/api/v1/endangered`, signed with the installation's key.
- **Let federation peers push flags here.** A peer of the institution, active and allowed the **Heritage at risk** surface under **Federation**, may send a flag. It is kept under **Pushed at-risk flags** and never enters the institution's own register. Accept it and it appears on the network board, marked as pushed by that peer; decline it and it does not. Where the institution requires peers to sign, an unsigned push is refused.

The public **network board** puts the institution's published register beside each peer's, read as the board opens, and the pushed flags that were accepted, ranked by score. A peer's answer that is not signed by its pinned key is not used where the institution or the peer's trust level requires a signature. Each row says which institution holds the item and whether its data was verified.

## The displaced-heritage register

Those who manage repatriation claims find **Displaced heritage** in the menu. It traces descriptions whose recorded origin and present holding place resolve to different countries or regions:

- **Origin** is the location of the earliest owner in the description's provenance. The museum fields Heratio reads (place of creation, of discovery, cultural group) come to the Workspace in phase 21.
- **Holding** is the country in the contact details of the description's repository, else the location of its latest owner.
- Both are matched against a short, well-known list of countries, peoples and cultures. "Benin City" and "Nigerian" both mean Nigeria.

A description is flagged only when both sides are known and differ. Anything the register cannot place is left out. It would rather miss an object than accuse a holder wrongly. Each traced description leads to **Register a repatriation claim**, with its origin filled in.

Where the institution shows its register of repatriation claims in the public catalogue, the displaced-heritage register is public too. Where it allows the public to lodge claims, each traced object offers that as well.

For a printout, run `php artisan workspace:heritage:displaced`.
