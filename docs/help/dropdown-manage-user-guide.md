---
title: Dropdown manager
category: Administration
order: 70
staff: true
routes: dropdowns.*
heratio: dropdown-manage-user-guide.md
---

# Dropdown manager

Every list a form offers in the Workspace, from accession statuses to seat types, is a SKOS vocabulary that ships with it, one Turtle file in `resources/vocabularies` for each. None is a database enum. The Dropdown manager, at `/admin/dropdowns`, lets an institution make those lists its own without changing them for anyone else on the installation.

It is under **Admin**, and only an account that may administer the institution opens it.

## What an institution can change

In every list it can:

- give any term its own wording in English, Afrikaans and the language you are working in, while leaving the others as bundled;
- number the terms, so its forms offer them in that order, and terms without a number come after those with one;
- choose a default, which a form offers first.

Some lists are also **open**, and in those it can add terms of its own and hide terms it never uses. The index has an **Open** column that says which lists are open.

A hidden term is no longer offered, but whatever already uses it keeps it. Hiding takes nothing away from existing descriptions.

## Fixed and open lists

A list is fixed when the Workspace's own code relies on its terms, or a standard defines them. Examples are the publication states, the levels of description, the relation types and the date qualifiers that EAD writes out. Hiding one of those terms, or adding another, would break something, so the manager refuses both and still lets you reword and reorder them.

The open lists are named in `config/workspace.php` as `open_vocabularies`. A test checks that no code names a term of an open list. It also checks that every form which sends a term's code into an open list looks it up in a way that finds the institution's own terms too, so a list can only be made open where that is safe.

## Adding a term of your own

Open an open list and use **Add a term of our own**. Give its label and the language of the label (a BCP 47 tag, such as `en` or `af`). You may also give a code, which is otherwise made from the label, and a bundled term it narrows.

The new term gets an IRI under the institution's own base IRI, such as `https://riverside.example.org/vocab/repository-type/church-archive`, not one under `workspace.openric.org`. Its scope note records that it is the institution's own term and, where it narrows one, which bundled term. Another institution never sees it, and may add a term with the same code for itself.

**Remove** takes a term of your own out of the list. It is deprecated rather than deleted, so a description that uses it still shows it.

## What does not change

A bundled term keeps its IRI, its code and its RiC-O mapping whatever an institution calls it, so a harvester or another system still recognises it. The wording you choose is what your staff and your public catalogue read. Where an export writes a term's label as text, as EAD, Dublin Core and the CSV exports do, it writes your wording beside the unchanged IRI or code. The published vocabularies themselves, at `/vocab/`, always keep their bundled labels.

Every save is in the audit log.

## Compared with Heratio

Heratio keeps its lists in one `ahg_dropdown` table that an administrator edits for the whole installation, with a colour and an icon for each term. In the Workspace the lists are the bundled vocabularies themselves. An institution changes them only for itself, and its own terms are concepts with IRIs of their own. Colours and icons are not carried over.

## Related

- [Vocabularies](vocabularies-skos-concept-schemes.md) holds the subjects, places and other lists you describe with, and lets you build your own.
