---
title: Own fields and form templates
category: Describing
order: 60
staff: true
routes: custom-fields.*
heratio: custom-fields-user-guide.md
---

# Own fields and form templates

No standard covers everything an institution records. Own fields let you add fields of your own to any kind of description, without changing the Workspace; form templates let you decide which fields a form shows, and in what order, for a kind of record or a part of the catalogue.

The screens are under **Admin**, **Own fields and form templates** (`/custom-fields`). They need the right to administer the institution.

## Adding a field

Choose the kind of description it belongs to: archival descriptions, authority records, repositories, accessions, activities and the rest. Then give it:

- a **label** for the form, and a **key**, its name in exports, which cannot change once the field holds values;
- the **kind of value**: one line of text, several lines, a number, a date, yes or no, a term from a vocabulary, terms from a vocabulary, or a web address;
- for a term, the **vocabulary** its terms come from;
- a **default**, a **help** text shown under it, and a **check**, a regular expression a value must match;
- whether it is **required**, **repeatable** (so it can hold more than one value), **searchable**, shown to the public, and **exported**;
- a **group**, to put related fields under one heading, and its **order**.

A field can be switched off, which hides it from forms and pages but keeps every value it holds. One that holds no values can be removed.

## Filling them in

An own field appears on the edit page of each description of its kind, under **More about it**, grouped as you set. It shows on the description's page once it has a value, and on the public page too unless you keep it from the public. A field marked to be exported goes out with the description in the CSV export, so what you record there can leave the Workspace with the rest; a field kept from the public is left out of what the public receives.

## Form templates

A form template lists the fields a form shows, in order; a field left out is not on the form. Use one to give a photograph collection a short form, or a series of council minutes the fields it always needs.

Each template applies where its assignments say: to a level of description, a repository, or a collection by its persistent IRI and, if you choose, everything inside it. Where more than one could apply, the one with the lowest priority number wins. One template can be the default for a kind of description, used where no other applies.
