---
title: Translating descriptions and the interface
category: AI assistance
order: 50
staff: true
routes: records.translate, records.translate.start, translation.*
heratio: translation-user-guide.md
---

# Translating descriptions and the interface

## Translating a description

**Translate with AI**, on a unit of description, opens a page to translate it with the AI gateway's translation model (NLLB-200). It is for anyone who may change or translate the description, once AI is on for the institution (see [AI and the log of inferences](ai-inference-provenance-user-guide.md)).

- **From**: a language the unit is described in.
- **Into**: one of 21 languages:
  - South Africa's eleven official languages;
  - Northern Ndebele and chiShona;
  - Dutch, French, German, Spanish, Portuguese, Swahili, Arabic and Italian.
- **Elements**: any text element of ISAD(G) that has text in the language translated from. Those the institution chose on the AI page are ticked; otherwise the title and scope and content are.

Each element is translated on its own, in the background. Each translation waits in the **proposals queue** as the element in the other language, with the model that made it and a link to its receipt. Accept it, correct it first, or reject it. Accepting it writes the element as a translation: the original stays, and the unit then has the element in both languages.

An element that already has a value in the language translated into is left alone, as is a translation already waiting. Nothing is overwritten without review.

## The interface in the institution's own words

**Interface wording**, at `/interface-strings`, lists every string of the Workspace's interface in each language it offers. Each shows the Workspace's wording and the institution's own, if it has given one. Find strings by what they contain, or show only those not yet translated.

- Anyone who may translate **proposes** a wording. It waits under **Changes waiting** for whoever administers the institution, who accepts or rejects it.
- Whoever administers the institution may also **save** a wording at once.
- **Leave a wording empty** to go back to the Workspace's.
- **History** shows every change to a string: from what, to what, by whom and whether it was accepted.
- **Machine suggestion**, when AI is on, asks the gateway to translate the English string into the language shown. The suggestion appears on the page, to use or not.

The institution's wording is shown only to its own staff and on its own public catalogue. Another institution on the same installation keeps the Workspace's wording, or its own.

## Compared with Heratio

- **Drafts.** Heratio keeps machine translations as drafts in a table of their own, applied or rejected. In the Workspace they are proposals, so they are reviewed in the same queue as every other proposal and each names its receipt.
- **Interface strings.** Heratio's are set for the whole installation. In the Workspace each institution sets its own.
