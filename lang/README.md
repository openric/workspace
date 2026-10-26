# Interface translations

The interface's text in each language other than English (WS-005; roadmap phase 1), keyed by the English string as Laravel's JSON translations are. `config('workspace.interface_languages')` lists the languages the interface offers; `tests/Feature/InterfaceLanguageTest.php` fails when a translated string has no entry here, or when an entry drops one of its `:placeholders`.

## Wording

The English follows PSIS in its words and South African English in its spelling (Authorised, not PSIS's Authorized), the AHG's AtoM, which uses the established archival wording (AtoM's, after the ICA standards): for example Log in, Users, Taxonomies, Creator(s), Access points, Physical storage, and the standards' area names (Identity area, Context area). Where PSIS has no string for what a screen says, the Workspace's own wording stays. One exception: Activities stays. PSIS's nearest term, Functions, names a narrower thing than a RiC activity.

The Afrikaans takes PSIS's translation where it is correct archival Afrikaans, and keeps the correct term where it is not. Examples:
- "Teken uit", not PSIS's "Teken af";
- "Besit" for holdings, not "Stoorplek";
- "gesagsrekord", not "owerheid rekord";
- compounds written together, such as Konteksarea, not "Konteks area".

## af.json (Afrikaans)

- 62 are PSIS's (AtoM's) Afrikaans for PSIS's English, taken where it is correct archival Afrikaans; four were corrected (Address: Adres, not "Addres"; All: Alle, not "Almal"; Description identifier: Beskrywingsidentifiseerder; Clipboard ID: Knipbord-ID), as was the capital W of Bevestig wagwoord. They are AtoM translators' work under the same notice as below.
- 1122 were written for the Workspace, in its own wording where PSIS has none, for review by Dr Johan Pieterse.
- 27 come from Heratio's `lang/af.json` (heratio-dev `4131171e4`, AGPL-3.0-or-later, Johan Pieterse, Plain Sailing Information Systems), where the Workspace's English matches Heratio's. Heratio has "Bestuur" for both Admin and Manage; Admin is "Administrasie" here.
- 16 of those 27 are Access to Memory's Afrikaans translation, which Heratio took from AtoM's XLIFF files:

  Change password, Clipboard, Containers, Description, Email, Hierarchy, Home, Identifiers, Language, Password, Places, Search, Settings, created, place, repository.

  They are © Artefactual Systems Inc. and the AtoM translators, under the GNU Affero General Public License 3.0 (https://github.com/artefactual/atom). This notice travels with them.

Heratio's file also has the other strings of Heratio's interface in 68 languages. The Workspace takes a string from it only when its own English is the same.

## af/validation.php

Laravel's validation messages in Afrikaans, for the rules the Workspace uses, and the Afrikaans names of the common fields; written for the Workspace (neither Heratio nor PSIS has them), for review by Dr Johan Pieterse. A rule not listed falls back to Laravel's English.

## Other languages

An institution chooses the languages it offers under Settings, Interface languages: any language with a file here, and English always. Nothing in the code lists them (`App\Support\InterfaceLanguages`). A language is named in itself, from `languages.json`, which is Heratio's list from its culture switcher (heratio-dev `4131171e4`, `ahg-theme-b5`).

The files below hold only the translations PSIS and Heratio already have for the Workspace's English, word for word: PSIS's (AtoM's) first, then Heratio's. Every other string is shown in English until the language is translated in full. None of them is reviewed. Those from PSIS are AtoM translators' work, © Artefactual Systems Inc. and the AtoM translators, AGPL-3.0; those from Heratio include machine translations made for Heratio.

| Language | Tag | Strings of 1213 |
|---|---|---|
| Deutsch | `de` | 222 |
| Español | `es` | 229 |
| Français | `fr` | 212 |
| Oshikwanyama | `kj` | 36 |
| isiNdebele (Northern) / Sindebele | `nd` | 36 |
| Nederlands | `nl` | 227 |
| isiNdebele (Southern) | `nr` | 126 |
| Sepedi | `nso` | 232 |
| Português | `pt` | 231 |
| chiShona | `sn` | 36 |
| siSwati | `ss` | 232 |
| Sesotho | `st` | 232 |
| Setswana | `tn` | 230 |
| Xitsonga | `ts` | 225 |
| Umbundu | `umb` | 36 |
| Tshivenda | `ve` | 190 |
| isiXhosa | `xh` | 233 |
| isiZulu | `zu` | 234 |

: Translation files other than Afrikaans

Not given a file yet: Kiswahili and Nepali, which have no translation of the Workspace's English in PSIS or Heratio; Arabic, which waits for right-to-left layout; and Khoisan, a family of languages rather than one.
