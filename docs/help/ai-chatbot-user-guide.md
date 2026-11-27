---
title: The catalogue's assistant
category: AI assistance
order: 30
staff: true
routes: ask, discover.ask, ai.answers
heratio: ai-chatbot-user-guide.md
---

# The catalogue's assistant

The assistant answers questions about the collection in plain words, from the catalogue itself. It is there only once AI is switched on for the institution (see [AI and the log of inferences](ai-inference-provenance-user-guide.md)), and it is never the record: every answer is written by AI, says so, and names the descriptions it was written from, each a link you can follow and check.

## Two assistants

**Ask the assistant**, in the staff menu, answers from what the search finds that you may read, drafts included where your roles let you see them, and from the Workspace's own help guides, so you may also ask how to do something ("how do I publish a fonds?").

**Ask the catalogue**, in the public catalogue, answers from published descriptions only. A draft, a closed unit or anything the public may not read is never a source. It appears only where the institution offers it: an administrator ticks **Offer it in the public catalogue** under the assistant in **Admin > AI**. Until then the address does not exist.

## How an answer is made

1. Personal information in the question is masked before anything leaves the Workspace: an email address becomes `[REDACTED:email]`, and any run of nine or more digits (a telephone, identity, account or card number) becomes `[REDACTED:number]`. The question is kept masked too. Names, street addresses and shorter numbers are not masked, so the public page asks readers not to type personal details.
2. A question that tries to change the assistant, such as one telling it to ignore or show its instructions, or to pretend to be something else, is refused with a fixed answer and never reaches a model.
3. The question's words are searched for in the catalogue, and the best few descriptions found are made into short packs of their names, dates, properties and relations, as the Graph-Grounding profile gives them to any agent.
4. If nothing is found, no model is asked. The assistant says it could not find anything in the catalogue about that, and suggests other words.
5. Otherwise the masked question, the packs, the last few turns of the conversation and the institution's own instructions go to the AI gateway's chat model. The call is a receipt in the log of inferences, like every other AI call. The public assistant answers at most so many questions with the model a day, 200 unless an administrator sets another number under **Admin > AI** (0 stops it); after that it says so and points to the search.
6. The answer is checked for how far its words come from the sources. One that does not rest on them is shown with **Check this answer:** and the reason, so a reader knows to follow the links before relying on it.

The sources are listed under each answer, numbered as the answer cites them.

## When an answer is wrong

Under each answer is **Report this answer as wrong**, with room to say what is wrong. A flagged answer goes to **Flagged answers** in the staff menu, where anyone who may review proposals reads the question, the answer, its sources and the note, and records whether the answer was right or wrong. Three answers found wrong within 30 days raise an incident on the assistant in the AI register, by the nightly monitor (see [AI register and compliance](ai-compliance-user-guide.md)).

## Conversations

A reader's conversation lasts the browser session; **Start a new conversation** begins another. Conversations are deleted after the institution's retention period (30 days for an institution set up from 27 November 2026, unless an administrator sets another in **Admin > AI**; an institution set up before keeps the period it has, 90 days unless changed), every night by `php artisan workspace:chat:prune`. The log of inferences keeps only hashes of what was sent and what came back, never the words.

## What the assistant cannot do

It knows only what the catalogue's search finds for the question's words, a handful of descriptions at a time; it does not read the whole collection, the files of a unit or the pages of a scan. It does not change anything. It may be wrong, which is why it cites, says so, and can be stopped: an administrator who halts the assistant in the AI register takes both assistants away at once.
