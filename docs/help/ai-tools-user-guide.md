---
title: Names, summaries and condition from AI
category: AI assistance
order: 20
staff: true
routes: records.ask, instantiations.assess-condition
heratio: ai-tools-user-guide.md
---

# Names, summaries and condition from AI

Once AI is switched on for the institution (see [AI and the log of inferences](ai-inference-provenance-user-guide.md)), three more kinds of help appear for anyone who may change a description. Each is asked of a model through the AI gateway in the background. Each answer waits in the **proposals queue**, where you accept it, correct it first, or reject it. Each call is a receipt in the log of inferences, and the proposal links to it.

## Finding names

**Find names with AI**, on a unit of description, sends the unit's text to the gateway's named-entity model (spaCy). That text is everything it describes, followed by the transcripts of its pages. What comes back is proposed as follows:

- **People and organisations** become proposed authority records, to link to an existing record or make a new one when you accept;
- **places** become proposed places;
- **dates** become proposed dates.

Each proposal shows the passage it was found in. Where spaCy gives a score, the proposal carries it as its confidence. Where spaCy gives none, which is usual, it has no confidence rather than an invented one. A name the unit already has waiting or accepted is not proposed twice. Kinds of name the Workspace does not take, such as titles of works, are left out.

At most 20,000 characters are read in one call. A longer description is cut there.

## Summarising the pages

**Summarise its pages with AI** asks a language model for a summary of 30 to 200 words of the transcripts of the unit's pages, in the description's language. Facts, dates and names are kept. The summary is proposed as the unit's **scope and content** (ISAD(G) 3.3.1), with evidence saying how many pages it came from. A unit with no transcripts has nothing to summarise: read its pages first.

The model is the Workspace's default (`qwen3:8b`) unless the AI page names another. Any reasoning the model writes before its answer is removed.

## Assessing condition

**Assess its condition with AI**, on an instantiation with a picture, sends the picture to a vision model. The model is asked to judge only what it can see. It returns:

- a **grade** from the condition-grade vocabulary: excellent, good, fair, poor or unacceptable;
- the **damage** it sees, such as foxing, tears, staining or mould;
- a note on where the damage is.

This is proposed as the instantiation's **physical characteristics**, for example "Condition: fair. Damage seen: foxing, a tear at the lower edge." Accepting it writes it to the instantiation, not to the unit.

The vision model is not a trained condition model, and its evidence says so. A trained model behind the gateway will replace it when the gateway has one (ArchiveHeritageGroup/ahg-ai#5). Always check the object itself before accepting.

## Compared with Heratio

Heratio finds names with spaCy, falling back to a language model when spaCy is unavailable. It writes them to a table of its own for review, and summarises through its LLM service. The Workspace finds names through the gateway's spaCy route only, so every name is from one known model, and sends them to the same review queue as any other proposal. Heratio's condition check calls a server directly and was never finished; the Workspace goes through the gateway.
