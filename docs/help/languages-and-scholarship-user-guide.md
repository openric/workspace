---
title: Languages of the collection, discoveries and research leads
category: Search and discovery
order: 70
staff: false
routes: discover.languages*, discover.transcribe*, discover.research-leads*, scholarship.*, languages.review
heratio: language-revival-corpus-user-guide.md
---

# Languages of the collection, discoveries and research leads

## The languages of the collection

**Languages of the collection**, under **Ways in** in the public catalogue, lists every language the published records are described in, with how many records are described in it and how many terms and places are named in it. The language with the most records comes first. Afrikaans is always placed just before Dutch, as Heratio places it.

A language's page gathers what the collection holds in it:

- the records described in it;
- the subject terms of the institution's vocabularies and the places named in it, a starting word-list;
- the transcripts of pages in it, from published records;
- the transcriptions people contributed;
- the community glossary.

Where the institution has AI switched on, a passage can be translated by machine. The translation is marked as such: it is never an official or authoritative one.

Heritage and endangered languages belong to the communities that speak them. The page is a resource for speakers, learners and researchers, not a claim of authority over the language.

## Contributing

Anyone can add a word to a language's glossary, with what it means, an example and a source. Every published record has a link to **Transcribe or translate** it. A contribution there is a transcription, a correction, a translation or a note, in a language, which is the language of the record's title unless you choose another. A contributor is named only if they give a name and tick that they want to be credited. Otherwise the contribution appears anonymously and no name is kept.

Nothing appears until it is reviewed. Under **Language contributions to review**, anyone who may change descriptions approves a contribution or a glossary entry, or marks it not to be published.

## Discoveries

Under **Discoveries**, those who may publish descriptions can have a model look for connections a cataloguer might not have spotted. The model is shown only the links around a published record, one and two steps out in its relationship graph, as the public may see them, and is told to use nothing else and to invent nothing. A discovery keeps those links, what the model drew from them, a confidence score and the receipt of the call. The score comes from the number of links, a second step and whether the model found a lead, as in Heratio:

- 70 and above: high confidence;
- 40 and above: moderate confidence;
- below 40: tentative.

**Refresh** looks at the most connected published records first, as many as you set. A record's own page has **Connections and research leads**, where it can be discovered on its own. **Search the federation peers** searches the institution's peers for records that share its access points (its title and the names it is linked to). A model then says in one sentence why each might connect, from those shared terms alone. The answer is kept for a day.

With AI off, a discovery still shows its links, without leads. Discoveries are never public.

## Research leads

**Research leads** promotes the strongest discoveries, the most confident first, into leads for a curator. Each lead says why it might matter: from the facts of its links, or, if you tick it, in a model's words. A lead is pending until a curator publishes it. A curator can also dismiss it or send it back to pending.

The public catalogue's **Research leads** shows only published leads on records the public catalogue shows. Each lead shows its links and is marked as drawn by AI. A lead is a starting point for enquiry, not a finding. Check every one against the records it cites.

For the command line: `workspace:scholarship:discover`, `workspace:scholarship:generate`, `workspace:scholarship:leads` and `workspace:scholarship:federated`.
