# openric.org look

The Workspace reuses the look of openric.org. These files are copies, served from the Workspace so no page calls a third party.

| File | Source | Licence |
| --- | --- | --- |
| `shell-v1.css` | openric.org's shared shell, `assets/shell/v1.css` in the openric-spec repository (last changed 2026-10-15, commit 3386cc2): the design tokens and the canonical RiC entity colours | AGPL-3.0-or-later |
| `fonts/inter-latin-{400,500,600,700}-normal.woff2` | Inter, @fontsource/inter 5.1.0, the type openric.org sets | OFL-1.1 (`fonts/OFL-Inter.txt`) |
| `fonts/jetbrains-mono-latin-{400,500}-normal.woff2` | JetBrains Mono, @fontsource/jetbrains-mono 5.1.0, openric.org's code type | OFL-1.1 (`fonts/OFL-JetBrains-Mono.txt`) |

`resources/views/layouts/_style.blade.php` loads them and carries openric.org's base styles from `_layouts/default.html` (typography, tables, buttons, callouts, navigation), with the Workspace's own parts (menus, description areas) built on the same tokens and a dark scheme from the shell's dark colours.

**What is not used:** the shell's script `v1.js`, which adds openric.org's header, footer and a contact button. The Workspace's pages belong to the institution running it.

**To update:** copy the new `assets/shell/v1.css` over `shell-v1.css`, note the commit here, and check a record page, the public catalogue and the menus in light and dark.
