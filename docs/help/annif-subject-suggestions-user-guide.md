---
title: Subjects suggested by Annif
category: AI assistance
order: 70
staff: true
routes: ai.annif-trial
---

# Subjects suggested by Annif

Annif is open-source subject indexing software from the National Library of Finland. Given the text of a description, it says which concepts of a vocabulary the description is about, each with a score. The Workspace asks it through the AI gateway, and every subject it suggests waits for a person to accept it.

## Setting it up

Under **Admin**, then **AI**, the **Subjects suggested by Annif** settings hold:

- **Annif project**, the project behind the gateway trained on your vocabulary, such as `openric-riverside-subjects`;
- **The vocabulary it suggests from**, one of the institution's subject, place or other descriptive vocabularies;
- **At most so many subjects a record** (5 unless you set another number), and **how sure it must be** (0.20).

AI must be on as well. The project itself is created and trained on the gateway's side, from the vocabulary and the institution's own descriptions. For an archive with too few indexed descriptions to train on, Annif's language-model backend matches the vocabulary's labels instead.

## Suggesting subjects

On a description's page, **Suggest subjects with Annif** sends its title, its scope and content and the transcripts of its pages to the project. Each suggestion that is a concept of the chosen vocabulary appears in the review queue as a proposed subject, with Annif's score and the receipt of the call. A concept the record already has as a subject is not suggested again, nor is one a reviewer rejected, nor anything outside the vocabulary.

Accept it and the concept becomes the record's subject (`rico:hasOrHadSubject`). The relation records that a model proposed it and that you accepted it. Reject it and it is not suggested again.

## Teaching it

Each night the subjects reviewers accepted are taught back to the project, with the text they were accepted for, so it learns where its backend can. `php artisan workspace:annif:learn` does it at once.

## How far to trust it

**Try Annif now**, at the foot of the AI page, tries the project on up to 50 published records that already have subjects of the vocabulary:

- **Precision** is how many of its first five suggestions were subjects the record has.
- **Recall** is how many of the subjects the record has it found.

Each trial is kept, so you can see whether training helps. Try it before relying on its suggestions. `php artisan workspace:annif:trial` does the same.
