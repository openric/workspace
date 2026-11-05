---
title: Scoring the evidence for a name
category: AI assistance
order: 40
staff: true
routes: authority.review
heratio: authority-resolution-evidence-scoring.md
---

# Scoring the evidence for a name

Each candidate for a name begins with how alike the two names are: 1 for the same name, otherwise Heratio's character similarity. It is taken on the names as written and on their words in order, and the better of the two counts, so "Botha, Maria" and "Maria Botha" score 1. Then each kind of evidence says one of four things:

- it **agrees**, adding 0.10;
- it **contradicts**, taking away 0.30;
- it **does not agree**: there was something to compare and nothing matched;
- it has **nothing to go on**.

The last two add nothing. The score is kept between 0 and 1, and candidates are ranked by score, then by name similarity, then by name.

## For people and organisations

- **Dates**: a year near the name falls within the candidate's years of existence. These are read from the candidate's dates, or else from its dates of existence (ISAAR(CPF) 5.2.1).
- **Places**: a place named near the name is a place the candidate is related to, or one its description names (5.2.3).
- **Relations**: a person or organisation named near the name is one the candidate is related to.
- **Words of role**: a word of role near the name, such as "daughter of", appears in the candidate's history, functions, mandates or legal status.
- **Contradiction**: a year near the name falls after the candidate's last year. Someone who died in 1760 did not write in 1902.

## For places

- **People named near it**: a person or organisation named near the place is related to the candidate.
- **Broader places**: a place named near it is one of the candidate's broader places, such as Transvaal for Pretoria.
- **Scale**: words around the name and in the candidate's name are of the same scale (a city, a district, a country). A facility such as a church or school, where the text speaks of a town or region, contradicts.
- **Places the fonds uses**: the candidate is one of the three places most related to units of the same fonds, at least twice.
- **Contradiction**: the place's own dates end before a year near the name.

The evidence a candidate was ranked by is kept with every decision, so it can be seen later what the archivist saw.
