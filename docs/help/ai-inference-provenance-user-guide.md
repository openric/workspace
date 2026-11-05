---
title: AI and the log of inferences
category: AI assistance
order: 10
staff: true
routes: ai.*, instantiations.read-text
heratio: ai-inference-provenance-user-guide.md
---

# AI and the log of inferences

The Workspace can ask models for help: reading the text of a scanned page now, and in later releases finding names, summarising, translating, suggesting subjects and searching by meaning. Three rules hold for all of it.

- **Only through the AI gateway.** Every model is reached through the AHG AI gateway at `ai.theahg.co.za`, never directly on a server. The gateway checks the installation's key, keeps to its quota and chooses the machine that answers.
- **Only proposals.** What a model says is never written to a description. It becomes a proposal in the review queue, where a person accepts it, corrects it first, or rejects it.
- **Every call kept.** Each call is a receipt in the log of inferences, chained and signed, so it cannot be changed or removed unnoticed.

## Setting it up

AI is off until two things are true:

- The installation has a gateway key, with the gateway scope, as `AI_GATEWAY_KEY` in its `.env`.
- Whoever administers the institution ticks **Use AI for this institution** under **Admin**, then **AI**, at `/admin/ai`.

The same page sets a limit on calls a calendar month, if you want one, beside the gateway's own quota. It also names the vision models that read printed pages, if you want others than the Workspace's defaults. Until then every AI button is hidden, or says why it cannot be used.

## Reading the text of a page

On an instantiation whose master or reference copy is an image, **Read the text with AI** reads the page in the background. This follows the method Heratio proved on the Brenthurst Library cuttings, where reading the image with a vision model scored 95.0% real words, against 89.1% for Tesseract alone:

- The page is cut into strips of about 1,200 pixels, at the whitest row near each cut, so no line of text is split.
- A vision model reads each strip and is told to copy what is printed, never to correct, modernise or complete it.
- Each reading is checked against Tesseract, run on the Workspace's own server. A strip that comes out much shorter, repeats itself, shares few of Tesseract's words or drops one of its lines is read again by a second model. If it still disagrees, it is kept and flagged.
- Remarks the model adds that are not on the page, such as "[Top of image is cut off]", are removed.

The text then waits in the **proposals queue** as the instantiation's transcript, with:

- its **confidence**, the share of strips that agreed with Tesseract;
- its **evidence**, which says which models read it and which strips to check against the page;
- the model that made it.

Accept it, correct it first, or reject it, as with any proposal. Handwriting is read with its own model in a later release.

## The log of inferences

**Admin**, then **AI**, then **The log of inferences** lists every call a model was asked: when, for which service, which model and who asked. A call the gateway did not answer is listed too, with its error.

Open a receipt to see its whole trace:

- **what went in and what came out**, each as a SHA-256 hash with an excerpt;
- the confidence, what it was about and the gateway address;
- the receipt's own hash, the hash of the receipt before it and the key it was signed with;
- the proposals it made, whether each was accepted or rejected, by whom and when, and whether the reviewer corrected it before accepting it. What the reviewer wrote is kept beside what the model said.

The strips of one page are separate receipts, linked to each other.

## Checking the chain

Each receipt holds the hash of the one before it and is signed with the installation's Ed25519 key, the key that signs the audit log. **Check every receipt** on the AI page, or `php artisan workspace:ai:verify` on the server, walks the whole chain. It reports the first receipt that is missing, out of order, changed or wrongly signed. The table refuses changes and deletions, so a broken chain means someone went around the Workspace.

## What the public sees

In the RiC-O export, a relation that a model asserted says so: it is `prov:wasGeneratedBy` a `prov:Activity` associated with the model as a `prov:SoftwareAgent`. A relation that no model asserted carries no such statement, and by that absence is documented fact. This is the Inferred-Provenance profile.

## Compared with Heratio

Heratio keeps an inference record (`ahg_ai_inference`) and a separate receipt chain (`ahg-inference-receipts`), and records a correction as an override when a field is saved. The Workspace keeps the record and its receipt as one row. A correction is the reviewer's accepted value on the proposal, since no model output is ever saved to a field without review.
