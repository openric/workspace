# Interface translations

The interface's text in each language other than English (WS-005; roadmap phase 1), keyed by the English string as Laravel's JSON translations are. `config('workspace.interface_languages')` lists the languages the interface offers; `tests/Feature/InterfaceLanguageTest.php` fails when a translated string has no entry here, or when an entry drops one of its `:placeholders`.

## af.json (Afrikaans)

- 167 strings were written for the Workspace, in its own wording. They are to be reviewed by an Afrikaans-speaking archivist.
- 32 come from Heratio's `lang/af.json` (heratio-dev `4131171e4`, AGPL-3.0-or-later, Johan Pieterse, Plain Sailing Information Systems), where the Workspace's English matches Heratio's. One was changed: Heratio has "Bestuur" for both Admin and Manage, so Admin is "Administrasie" here.
- 19 of those 32 are Access to Memory's Afrikaans translation, which Heratio took from AtoM's XLIFF files:

  Access, Change password, Clipboard, Containers, Description, Email, Hierarchy, Home, Identifiers, Language, Notes, Password, Places, Relationships, Search, Settings, created, place, repository.

  They are © Artefactual Systems Inc. and the AtoM translators, under the GNU Affero General Public License 3.0 (https://github.com/artefactual/atom). This notice travels with them.

Heratio's file also has the other strings of Heratio's interface in 68 languages. The Workspace takes a string from it only when its own English is the same.
