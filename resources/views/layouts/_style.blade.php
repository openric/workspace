{{-- OpenRiC Workspace - the styles every page shares, signed in or public. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- The look of openric.org, reused: its shared shell (public/vendor/openric/shell-v1.css, a copy of openric.org/assets/shell/v1.css: tokens and the RiC entity colours) and the base styles of its site layout (_layouts/default.html in openric-spec), served from the Workspace with its fonts so no page calls a third party. The shell's header and contact button are not used: these are the institution's pages. --}}
    <link rel="stylesheet" href="{{ asset('vendor/openric/shell-v1.css') }}">
    <style>
        @font-face { font-family: "Inter"; font-style: normal; font-weight: 400; font-display: swap; src: url("{{ asset('vendor/openric/fonts/inter-latin-400-normal.woff2') }}") format("woff2"); }
        @font-face { font-family: "Inter"; font-style: normal; font-weight: 500; font-display: swap; src: url("{{ asset('vendor/openric/fonts/inter-latin-500-normal.woff2') }}") format("woff2"); }
        @font-face { font-family: "Inter"; font-style: normal; font-weight: 600; font-display: swap; src: url("{{ asset('vendor/openric/fonts/inter-latin-600-normal.woff2') }}") format("woff2"); }
        @font-face { font-family: "Inter"; font-style: normal; font-weight: 700; font-display: swap; src: url("{{ asset('vendor/openric/fonts/inter-latin-700-normal.woff2') }}") format("woff2"); }
        @font-face { font-family: "JetBrains Mono"; font-style: normal; font-weight: 400; font-display: swap; src: url("{{ asset('vendor/openric/fonts/jetbrains-mono-latin-400-normal.woff2') }}") format("woff2"); }
        @font-face { font-family: "JetBrains Mono"; font-style: normal; font-weight: 500; font-display: swap; src: url("{{ asset('vendor/openric/fonts/jetbrains-mono-latin-500-normal.woff2') }}") format("woff2"); }
        {{-- Titles in a serif, as an archive's catalogue reads (phase 26); Source Serif 4, SIL Open Font Licence, served from here too. --}}
        @font-face { font-family: "Source Serif 4"; font-style: normal; font-weight: 600; font-display: swap; src: url("{{ asset('vendor/openric/fonts/source-serif-4-latin-600-normal.woff2') }}") format("woff2"); }
        @font-face { font-family: "Source Serif 4"; font-style: normal; font-weight: 700; font-display: swap; src: url("{{ asset('vendor/openric/fonts/source-serif-4-latin-700-normal.woff2') }}") format("woff2"); }

        /* The Workspace's names for colours (used in views' inline styles), mapped onto openric.org's tokens. */
        :root {
            --ink: var(--oric-fg); --muted: var(--oric-muted); --muted-2: var(--oric-muted-2); --line: var(--oric-border);
            --accent: var(--oric-accent); --link: var(--oric-accent-2); --accent-h: var(--oric-accent-h);
            --bg: var(--oric-bg); --surface: var(--oric-surface); --surface-2: var(--oric-surface-2); --code-bg: #eef2f7;
            --on-accent: #ffffff; --error: #b91c1c; --error-bg: #fef2f2; --ok: #047857; --ok-bg: #ecfdf5;
            --radius: var(--oric-radius);
            --page: #f5f6f8; --tint: #e8eef7; --serif: "Source Serif 4", Georgia, "Times New Roman", serif;
            --ok-badge: #dcfce7; --ok-badge-ink: #14532d; --warn-badge: #fef3c7; --warn-badge-ink: #78350f; --neutral-badge: #e2e8f0; --neutral-badge-ink: #334155;
            --shadow: 0 1px 3px rgba(15, 23, 42, .05), 0 4px 14px -6px rgba(15, 23, 42, .08);
            --shadow-lg: 0 2px 6px rgba(15, 23, 42, .06), 0 20px 40px -20px rgba(15, 23, 42, .15);
            color-scheme: light dark;
        }
        /* Dark: the shell's own dark surface colours (its data-theme="dark" variant). */
        @media (prefers-color-scheme: dark) {
            :root {
                --ink: #e5e7eb; --muted: #9ca3af; --muted-2: #cbd5e1; --line: #1f2937;
                --accent: #60a5fa; --on-accent: #0b1220; --link: #7cb3fb; --accent-h: #93c5fd;
                --bg: #0b1220; --surface: #0f172a; --surface-2: #1f2937; --code-bg: #1f2937;
                --error: #fca5a5; --error-bg: #2a1215; --ok: #6ee7b7; --ok-bg: #0f2a22;
                --shadow: 0 1px 3px rgba(0, 0, 0, .4); --shadow-lg: 0 10px 30px -10px rgba(0, 0, 0, .6);
                --page: #070d19; --tint: #16243d;
                --ok-badge: #0f2a22; --ok-badge-ink: #6ee7b7; --warn-badge: #2b2006; --warn-badge-ink: #fcd34d; --neutral-badge: #1f2937; --neutral-badge-ink: #cbd5e1;
            }
        }

        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; font-family: var(--oric-font); font-size: 16px; line-height: 1.65; color: var(--ink); background: var(--bg);
            font-feature-settings: "cv02", "cv03", "cv04", "cv11"; -webkit-font-smoothing: antialiased;
        }
        a { color: var(--link); text-decoration: none; transition: color .15s; }
        a:hover { color: var(--accent-h); text-decoration: underline; text-underline-offset: 3px; }
        /* Links inside running text are underlined, so they are not told apart by colour alone (WCAG 1.4.1). */
        main a, footer a { text-decoration: underline; text-underline-offset: 3px; }
        main details.area > summary a, main .ric a { text-decoration: none; }
        /* A long value (description._long) shows six lines until "more" is opened. */
        .long-text { display: block; }
        .long:has(> details:not([open])) > .long-text { max-height: 9em; overflow: hidden; -webkit-mask-image: linear-gradient(to bottom, #000 60%, transparent); mask-image: linear-gradient(to bottom, #000 60%, transparent); }
        .long > details > summary { cursor: pointer; color: var(--link); list-style: none; padding: .3rem 0; }
        .long > details > summary::-webkit-details-marker { display: none; }
        .long > details[open] .long-more, .long > details:not([open]) .long-less { display: none; }
        :focus-visible { outline: 3px solid var(--link); outline-offset: 2px; border-radius: 3px; }
        .skip { position: absolute; left: -999px; } .skip:focus { left: 1rem; top: .5rem; z-index: 10; background: var(--bg); padding: .5rem; }

        /* Header: the institution's name and logo, with "Workspace" as the product label, as on every OpenRiC surface. */
        /* Full-width bars, their content on the same 1180px column as openric.org's navigation. */
        header {
            margin: 0; padding: .7rem max(1.4rem, calc((100% - 1180px) / 2 + 1.4rem));
            display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .5rem 1rem;
        }
        header > a:first-child { font-weight: 700; font-size: 1.05rem; letter-spacing: -.01em; color: var(--ink); }
        header > a:first-child:hover { color: var(--accent); }
        .product { font-weight: 500; font-size: .78rem; color: var(--muted-2); background: var(--surface-2); padding: .15rem .5rem; border-radius: 10px; letter-spacing: .01em; white-space: nowrap; }
        header form { display: flex; align-items: center; gap: .5rem; margin: 0; }
        header form span { color: var(--muted-2); font-size: .9rem; }
        /* The account's menu under its name, drawn as the main menu's lists are. */
        .user-menu { position: relative; }
        .user-menu summary { cursor: pointer; list-style: none; color: var(--link); padding: .35rem .2rem; }
        .user-menu summary::-webkit-details-marker { display: none; }
        .user-menu summary::after { content: ""; display: inline-block; width: .35rem; height: .35rem; margin-left: .45rem; border-right: 1.5px solid currentColor; border-bottom: 1.5px solid currentColor; transform: rotate(45deg) translateY(-2px); opacity: .7; }
        .user-menu summary.here { font-weight: 600; }
        .user-menu ul { position: absolute; z-index: 60; top: calc(100% + .3rem); right: 0; min-width: 14rem; margin: 0; padding: .35rem; list-style: none; background: var(--bg); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow-lg); }
        .user-menu li a { display: block; padding: .45rem .7rem; border-radius: 5px; color: var(--ink); text-decoration: none; }
        .user-menu li a:hover, .user-menu li a[aria-current="page"] { background: var(--surface-2); color: var(--accent); }
        header button { margin: 0; padding: .35rem .8rem; font-size: .88rem; background: var(--bg); color: var(--ink); border-color: var(--line); box-shadow: none; }
        header button:hover { background: var(--surface-2); color: var(--accent); border-color: var(--link); box-shadow: none; }
        body > header { border-bottom: 1px solid var(--line); }
        main { max-width: 1180px; margin: 0 auto; padding: 2rem 1.4rem 4rem; }

        /* Typography, as openric.org. */
        h1 { font-size: 2.1rem; margin: 0 0 .5rem; line-height: 1.2; font-weight: 700; letter-spacing: -.02em; }
        h2 { font-size: 1.4rem; margin: 2.2rem 0 .8rem; font-weight: 600; letter-spacing: -.015em; border-bottom: 1px solid var(--line); padding-bottom: .35rem; }
        h3 { font-size: 1.1rem; margin: 1.5rem 0 .5rem; font-weight: 600; letter-spacing: -.01em; }
        h4 { font-size: 1rem; margin: 1.2rem 0 .4rem; font-weight: 600; }
        p, ul, ol { margin: .7rem 0; }
        ul, ol { padding-left: 1.5rem; }
        li { margin: .2rem 0; }
        strong { font-weight: 600; }
        small, .meta { color: var(--muted); }
        .meta { font-size: .9rem; }
        code { font-family: var(--oric-mono); font-size: .86em; background: var(--code-bg); padding: .1em .38em; border-radius: 4px; color: var(--accent-h); }
        pre { background: #0f172a; color: #e2e8f0; padding: 1rem 1.1rem; border-radius: var(--radius); overflow-x: auto; line-height: 1.55; font-size: .86rem; }
        pre code { background: none; padding: 0; color: inherit; font-size: 1em; }
        blockquote { border-left: 3px solid var(--link); margin: 1rem 0; padding: .5rem 1.1rem; color: var(--muted-2); background: var(--surface); border-radius: 0 var(--radius) var(--radius) 0; }
        hr { border: none; border-top: 1px solid var(--line); margin: 2rem 0; }
        @media (prefers-color-scheme: dark) { code { color: #bfdbfe; } }

        /* Tables, as openric.org: framed, rounded, small upper-case headings. */
        table { border-collapse: separate; border-spacing: 0; margin: 1.2rem 0; width: 100%; font-size: .94em; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
        /* A bar beside a count in a report; the count stays as text, so the bar is hidden from screen readers. */
        .bar { display: inline-block; height: .6rem; min-width: 1px; background: var(--link); border-radius: 2px; vertical-align: middle; }
        form.filters { display: flex; flex-wrap: wrap; gap: .6rem 1rem; align-items: end; }
        th, td { padding: .6rem .9rem; text-align: left; vertical-align: top; border-bottom: 1px solid var(--line); }
        tr:last-child td { border-bottom: 0; }
        th { background: var(--surface); font-weight: 600; font-size: .8em; letter-spacing: .03em; text-transform: uppercase; color: var(--muted-2); }
        tbody tr:hover { background: var(--surface); }

        /* Forms. */
        label { display: block; margin-top: 1rem; margin-bottom: .25rem; font-weight: 600; font-size: .93rem; }
        input, select, textarea {
            font: inherit; font-size: .95rem; padding: .5rem .7rem; width: 100%; max-width: 28rem;
            border: 1px solid #cbd5e1; border-radius: 6px; background: var(--bg); color: var(--ink);
            transition: border-color .15s, box-shadow .15s;
        }
        @media (prefers-color-scheme: dark) { input, select, textarea { border-color: #334155; } }
        textarea { max-width: 100%; line-height: 1.55; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--link); box-shadow: 0 0 0 3px color-mix(in srgb, var(--link) 25%, transparent); }
        input[type="checkbox"], input[type="radio"] { width: auto; accent-color: var(--accent); }
        input[type="file"] { padding: .4rem; }
        fieldset { border: 1px solid var(--line); border-radius: var(--radius); padding: .5rem 1rem 1rem; margin: 1rem 0; }
        legend { font-weight: 600; padding: 0 .35rem; }

        /* Buttons: openric.org's primary; aria-pressed="false" is its ghost. */
        button {
            font: inherit; font-size: .94rem; font-weight: 500; margin-top: 1rem; padding: .55rem 1.1rem; cursor: pointer;
            background: var(--accent); color: var(--on-accent); border: 1px solid transparent; border-radius: var(--radius);
            box-shadow: var(--shadow); transition: background .15s, box-shadow .15s, transform .15s;
        }
        button:hover { background: var(--accent-h); box-shadow: var(--shadow-lg); }
        button:active { transform: translateY(1px); }
        button[aria-pressed="false"] { background: var(--bg); color: var(--ink); border-color: var(--line); box-shadow: none; }
        button[aria-pressed="false"]:hover { border-color: var(--link); color: var(--accent); }

        /* Messages: openric.org's callouts. */
        [role="status"] { padding: .75rem 1rem; background: var(--ok-bg); color: var(--ok); border-left: 3px solid var(--oric-live); border-radius: 0 var(--radius) var(--radius) 0; }
        .error { color: var(--error); }
        div.error[role="alert"], p.error[role="alert"] { padding: .75rem 1rem; background: var(--error-bg); border-left: 3px solid var(--oric-danger); border-radius: 0 var(--radius) var(--radius) 0; }
        div.error[role="alert"] p { margin: .2rem 0; }

        /* Main menu, as openric.org's navigation: quiet links that light up on hover. */
        @media print { nav[aria-label="Main"], header form, .no-print { display: none; } }
        .main-nav { border-bottom: 1px solid var(--line); background: color-mix(in srgb, var(--bg) 85%, transparent); backdrop-filter: saturate(180%) blur(8px); -webkit-backdrop-filter: saturate(180%) blur(8px); position: sticky; top: 0; z-index: 50; }
        .main-nav ul { list-style: none; margin: 0; padding: 0; }
        .main-nav > ul { padding: .35rem max(1.4rem, calc((100% - 1180px) / 2 + 1.4rem)); }
        .main-nav > ul { display: flex; gap: .1rem .6rem; flex-wrap: wrap; align-items: center; font-size: .92rem; }
        .main-nav li { position: relative; margin: 0; }
        .main-nav > ul > li > a, .main-nav summary { display: inline-flex; align-items: center; color: var(--muted-2); padding: .4rem .6rem; border-radius: 6px; cursor: pointer; list-style: none; transition: background .15s, color .15s; }
        .main-nav summary::-webkit-details-marker { display: none; }
        .main-nav summary::after { content: ""; width: .35rem; height: .35rem; margin-left: .45rem; border-right: 1.5px solid currentColor; border-bottom: 1.5px solid currentColor; transform: rotate(45deg) translateY(-2px); opacity: .7; }
        .main-nav > ul > li > a:hover, .main-nav summary:hover, .main-nav details[open] > summary { color: var(--accent); background: var(--surface-2); text-decoration: none; }
        .main-nav summary.here, .main-nav > ul > li > a[aria-current="page"] { color: var(--accent); font-weight: 600; }
        @media (prefers-color-scheme: dark) { .main-nav summary.here, .main-nav > ul > li > a[aria-current="page"], .main-nav summary:hover, .main-nav details[open] > summary, .main-nav > ul > li > a:hover { color: #fff; } }
        .main-nav details ul { position: absolute; z-index: 60; top: calc(100% + .3rem); left: 0; min-width: 14rem; padding: .35rem; background: var(--bg); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow-lg); }
        .main-nav > ul > li:nth-last-child(-n+2) details ul { left: auto; right: 0; }
        .main-nav details a { display: block; padding: .45rem .7rem; border-radius: 5px; color: var(--ink); }
        .main-nav details a:hover { background: var(--surface-2); color: var(--accent); text-decoration: none; }
        .main-nav details a[aria-current="page"] { background: var(--surface-2); font-weight: 600; color: var(--accent); }
        .main-nav details:not([open]) > ul { display: none; }
        .main-nav .icon { width: 1.05em; height: 1.05em; margin-right: .4rem; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex: none; }

        /* Description areas as an accordion: each a card with a bar that opens and closes; all open to start. */
        details.area { border: 1px solid var(--line); border-radius: var(--radius); margin: 1rem 0; padding: 0 1rem; background: var(--bg); overflow: hidden; }
        details.area > summary { list-style: none; cursor: pointer; display: flex; align-items: baseline; gap: .75rem; flex-wrap: wrap; margin: 0 -1rem; padding: .65rem 1rem; background: var(--surface); }
        details.area > summary:hover { background: var(--surface-2); }
        details.area[open] > summary { border-bottom: 1px solid var(--line); }
        details.area > summary::-webkit-details-marker { display: none; }
        details.area > summary::before { content: ""; width: .5rem; height: .5rem; border-right: 2px solid var(--link); border-bottom: 2px solid var(--link); transform: rotate(-45deg); transition: transform .15s; align-self: center; flex: none; }
        details.area[open] > summary::before { transform: rotate(45deg); }
        details.area > summary h2 { margin: 0; font-size: 1.1rem; border: 0; padding: 0; }
        details.area[open] { padding-bottom: .6rem; }
        @media print { details.area > summary::before { display: none; } }

        /* The RiC-O term beside a field, as openric.org's inline code. */
        .ric { font-weight: normal; font-size: .78rem; color: var(--muted); margin-left: .35rem; }
        .ric a { color: var(--muted); text-decoration: none; }
        .ric code { font-size: .95em; background: var(--surface-2); color: var(--muted-2); }

        /* A record beside its hierarchy tree (Heratio's sidebar treeview); above the record on narrow screens. */
        .with-tree { display: grid; grid-template-columns: minmax(14rem, 18rem) minmax(0, 1fr); gap: 2rem; align-items: start; }
        .tree-aside { position: sticky; top: 4rem; max-height: calc(100vh - 5rem); overflow: auto; padding: .9rem 1rem; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); }
        @media (max-width: 64rem) { .with-tree { display: block; } .tree-aside { position: static; max-height: 22rem; margin-bottom: 1.25rem; } }
        /* Lists of things (search results, holdings, collections, relations) as distinct rows in alternating colours; menus, the tree and the image strip keep their own look. */
        main ul:not([class]):not(nav ul):not(.tree ul):not(.imageflow ul):not(.collections-carousel ul) { list-style: none; padding: 0; border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
        main ul:not([class]):not(nav ul):not(.tree ul):not(.imageflow ul):not(.collections-carousel ul) > li { margin: 0; padding: .6rem .85rem; border-bottom: 1px solid var(--line); }
        main ul:not([class]):not(nav ul):not(.tree ul):not(.imageflow ul):not(.collections-carousel ul) > li:last-child { border-bottom: 0; }
        main ul:not([class]):not(nav ul):not(.tree ul):not(.imageflow ul):not(.collections-carousel ul) > li:nth-child(even) { background: var(--surface); }
        main ul:not([class]):not(nav ul):not(.tree ul):not(.imageflow ul):not(.collections-carousel ul) > li > .meta { display: block; font-size: .85rem; margin-top: .15rem; }
        main ul:not([class]):not(nav ul):not(.tree ul):not(.imageflow ul):not(.collections-carousel ul) > li > .summary { display: block; margin-top: .3rem; font-size: .92rem; line-height: 1.45; }
        tbody tr:nth-child(even) { background: var(--surface); }
        /* Search results: facets beside the results, under them on a narrow screen (ADR 0009). */
        .search-layout { display: grid; grid-template-columns: 15rem 1fr; gap: 1.5rem; align-items: start; }
        .search-facets h2 { font-size: 1rem; margin: 1rem 0 .3rem; border: 0; padding: 0; }
        .search-facets .facets { list-style: none; padding: 0; margin: 0; font-size: .92rem; }
        .search-facets .facets li { margin: .2rem 0; }
        .facet-years input { max-width: 6rem; }
        .snippet { display: block; font-size: .9rem; color: var(--muted-2); margin-top: .25rem; }
        .snippet mark { background: #fef08a; color: #1f2937; padding: 0 .1rem; border-radius: 2px; }
        @media (prefers-color-scheme: dark) { .snippet mark { background: #a16207; color: #fff; } }
        @media (max-width: 52rem) { .search-layout { grid-template-columns: 1fr; } .search-facets { order: 2; } }
        /* Phones and narrow windows: nothing wider than the screen. Long IRIs and RiC-O terms wrap, wide tables scroll inside themselves, media shrink. */
        main, .main-nav { overflow-wrap: break-word; }
        code, .ric, dd, td { overflow-wrap: anywhere; }
        img, svg, video, audio, iframe { max-width: 100%; }
        img, video { height: auto; }
        @media (max-width: 40rem) {
            header { padding: .6rem .9rem; }
            .main-nav > ul { padding: .3rem .6rem; }
            main { padding: 1.25rem .9rem 3rem; }
            h1 { font-size: 1.6rem; }
            h2 { font-size: 1.25rem; }
            table { display: block; overflow-x: auto; }
            .main-nav > ul { padding: .3rem .6rem; }
            .main-nav > ul > li:has(details[open]) { flex-basis: 100%; }
            .main-nav details ul { position: static; box-shadow: none; border: 0; border-left: 2px solid var(--line); border-radius: 0; margin: .25rem 0 .25rem .6rem; }
            .main-nav > ul > li > a, .main-nav summary { padding: .55rem .6rem; }
            .main-nav details a { padding: .55rem .7rem; }
            button, input, select { min-height: 2.75rem; }
            /* The header's search takes a line of its own, so its field is not cut short. */
            header { flex-wrap: wrap; gap: .5rem; }
            header form[role="search"] { flex: 1 1 100%; flex-wrap: wrap; }
            header form[role="search"] input { flex: 1 1 10rem; max-width: none !important; }
        }

        /* Look and feel (phase 26, after The National Archives' catalogue and the mock-ups approved on 13 November 2026):
           titles in a serif; every link in a page underlined, so none is found by colour alone; four kinds of action
           (a filled button for the main one, an outlined one, a tinted one, a small chip); status as a badge; panels. */
        .title, main h1.title { font-family: var(--serif); font-weight: 600; letter-spacing: -.01em; line-height: 1.18; }
        main a, footer a { text-decoration: underline; text-decoration-thickness: 1px; text-underline-offset: 3px; }
        main a:hover, footer a:hover { text-decoration-thickness: 2px; }
        .button, .button-secondary, .button-tertiary { display: inline-flex; align-items: center; gap: .45rem; min-height: 2.6rem; margin: 0; padding: 0 1.05rem;
            border: 2px solid transparent; border-radius: var(--radius); font: inherit; font-size: .94rem; font-weight: 600; line-height: 1.2; cursor: pointer; box-shadow: none;
            text-decoration: none !important; white-space: nowrap; }
        .button { background: var(--accent); color: var(--on-accent); }
        .button:hover { background: var(--accent-h); color: var(--on-accent); }
        .button-secondary { background: var(--bg); color: var(--accent); border-color: var(--accent); }
        .button-secondary:hover { background: var(--tint); color: var(--accent-h); }
        .button-tertiary { background: var(--tint); color: var(--accent); }
        .button-tertiary:hover { background: color-mix(in srgb, var(--tint) 70%, var(--accent) 12%); color: var(--accent-h); }
        .button-danger { color: var(--error) !important; border-color: var(--error) !important; background: var(--bg) !important; }
        .chip { display: inline-flex; align-items: center; min-height: 2rem; padding: 0 .75rem; border: 1px solid #cbd5e1; border-radius: 6px; background: var(--bg);
            color: var(--accent); font-size: .82rem; font-weight: 600; text-decoration: none !important; }
        .badge { display: inline-block; padding: .18rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 600; line-height: 1.4; background: var(--neutral-badge); color: var(--neutral-badge-ink); }
        .badge.ok { background: var(--ok-badge); color: var(--ok-badge-ink); }
        .badge.warn { background: var(--warn-badge); color: var(--warn-badge-ink); }
        .badges { display: flex; flex-wrap: wrap; gap: .45rem; margin: .4rem 0 .6rem; }
        .panel { background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 1.15rem 1.35rem; margin: 1rem 0; }
        .panel > h2:first-child, .panel > h3:first-child { margin-top: 0; border: 0; padding: 0; font-size: 1.05rem; }
        .actions { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin: 1rem 0; }
        .actions form { display: inline; margin: 0; }
        /* The facts of a record at a glance (TNA's record summary). */
        dl.summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 1rem 1.6rem; margin: 0; }
        dl.summary > div { min-width: 0; }
        dl.summary dt { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        dl.summary dd { margin: .1rem 0 0; font-weight: 500; }
        /* "More": the rest of a page's actions, under one button. */
        details.more { position: relative; display: inline-block; }
        details.more > summary { list-style: none; }
        details.more > summary::-webkit-details-marker { display: none; }
        details.more > summary::after { content: ""; width: .35rem; height: .35rem; margin-left: .2rem; border-right: 2px solid currentColor; border-bottom: 2px solid currentColor; transform: rotate(45deg) translateY(-2px); }
        details.more > ul { position: absolute; z-index: 40; right: 0; top: calc(100% + .35rem); min-width: 17rem; margin: 0; padding: .35rem; list-style: none;
            background: var(--bg); border: 1px solid var(--line) !important; border-radius: 10px !important; box-shadow: var(--shadow-lg); }
        details.more > ul > li { margin: 0; padding: 0 !important; border: 0 !important; background: none !important; }
        details.more > ul a, details.more > ul button { display: block; width: 100%; margin: 0; padding: .5rem .75rem; border: 0; border-radius: 6px; background: none; box-shadow: none;
            color: var(--ink); font: inherit; font-size: .94rem; font-weight: 400; text-align: left; text-decoration: none !important; cursor: pointer; }
        details.more > ul a:hover, details.more > ul button:hover { background: var(--surface-2); color: var(--accent); }
        details.more > ul .button { display: inline-flex; width: auto; padding: 0 1.05rem; background: var(--accent); color: var(--on-accent); font-weight: 600; }
        .record-boxes { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1rem; margin: 1.2rem 0; }
        .record-boxes > .panel { margin: 0; }
        .record-boxes h2 { margin-top: 0; font-size: 1.05rem; }
        .record-boxes dl.summary { grid-template-columns: 1fr; gap: .6rem; }
        /* Links to the sections of a long page, drawn as tabs. */
        nav.sections { display: flex; flex-wrap: wrap; gap: .15rem; margin: 1.6rem 0 .4rem; border-bottom: 1px solid var(--line); }
        nav.sections a { padding: .6rem .8rem; margin-bottom: -1px; border-bottom: 3px solid transparent; color: var(--muted-2); font-weight: 600; font-size: .94rem; text-decoration: none !important; }
        nav.sections a:hover { color: var(--accent); border-bottom-color: var(--line); }
        /* The staff header in one row: the institution, the menus, a search and the account's tools; it wraps on a narrow screen. */
        .visually-hidden { position: absolute !important; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        header.site-header { padding-left: max(1.4rem, calc((100% - 1360px) / 2 + 1.4rem)); padding-right: max(1.4rem, calc((100% - 1360px) / 2 + 1.4rem)); }
        header.site-header .main-nav summary, header.site-header .main-nav > ul > li > a { padding: .4rem .45rem; font-size: .9rem; }
        header.site-header .main-nav summary::after { margin-left: .3rem; }
        header.site-header { position: sticky; top: 0; z-index: 50; background: color-mix(in srgb, var(--bg) 92%, transparent); backdrop-filter: saturate(180%) blur(8px); -webkit-backdrop-filter: saturate(180%) blur(8px);
            justify-content: flex-start; gap: .4rem .8rem; padding-top: .55rem; padding-bottom: .55rem; }
        header.site-header .brand { display: flex; align-items: center; gap: .6rem; color: var(--ink); text-decoration: none; font-size: 1rem; }
        header.site-header .main-nav { position: static; border: 0; background: none; backdrop-filter: none; -webkit-backdrop-filter: none; }
        header.site-header .main-nav > ul { padding: 0; gap: .1rem; }
        .header-tools { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; margin-left: auto; }
        .header-search input { width: 9.5rem; max-width: 100%; height: 2.5rem; margin: 0; background: var(--surface); }
        .account-tools { display: flex; align-items: center; gap: .35rem; }
        .icon-button { display: inline-flex; align-items: center; justify-content: center; gap: .2rem; height: 2.5rem; min-width: 2.6rem; padding: 0 .55rem;
            border: 1px solid var(--line); border-radius: var(--radius); color: var(--muted-2); text-decoration: none !important; font-size: .85rem; font-weight: 600; }
        .icon-button:hover { color: var(--accent); border-color: var(--link); background: var(--surface); }
        .icon-button svg { width: 1.15rem; height: 1.15rem; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .account-tools .user-menu summary { display: inline-flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; padding: 0; border-radius: 50%; background: var(--accent); color: var(--on-accent); font-weight: 700; font-size: .95rem; }
        .account-tools .user-menu summary::after { display: none; }
        .user-menu li.account-name { padding: .45rem .7rem .2rem; font-weight: 600; color: var(--ink); }
        .user-menu li.account-language { padding: .2rem .7rem .5rem; border-bottom: 1px solid var(--line); margin-bottom: .3rem; }
        .user-menu li.sign-out { border-top: 1px solid var(--line); margin-top: .3rem; padding-top: .3rem; }
        .user-menu li.sign-out form { margin: 0; }
        .user-menu li.sign-out button { display: block; width: 100%; margin: 0; padding: .45rem .7rem; border: 0; border-radius: 5px; background: none; box-shadow: none; color: var(--ink); font: inherit; text-align: left; }
        .user-menu li.sign-out button:hover { background: var(--surface-2); color: var(--accent); }
        @media (max-width: 40rem) { .header-tools { margin-left: 0; width: 100%; } .header-search { flex: 1 1 100%; } .header-search input { width: 100%; } }

        /* The public catalogue's band in the institution's own colour, white on it. */
        header.public-band { background: var(--band, #0f3e75); color: #ffffff; border-bottom: 0; gap: .8rem 1.4rem; padding-top: 1rem; padding-bottom: 1rem; }
        header.public-band a, header.public-band .brand { color: #ffffff; text-decoration: none; }
        header.public-band a:hover { color: #ffffff; text-decoration: underline; text-underline-offset: 3px; }
        header.public-band .brand { display: flex; align-items: center; gap: .8rem; flex: 1 1 18rem; }
        header.public-band .brand .title { display: block; font-size: 1.25rem; font-weight: 600; }
        header.public-band .band-sub { display: block; font-size: .84rem; opacity: .85; }
        header.public-band .band-search { flex: 1 1 22rem; display: flex; gap: .5rem; }
        header.public-band .band-search input { flex: 1; max-width: none; height: 2.75rem; border: 0; margin: 0; }
        header.public-band .band-search button { margin: 0; height: 2.75rem; background: transparent; color: #ffffff; border: 2px solid #ffffff; box-shadow: none; font-weight: 600; }
        header.public-band .band-search button:hover { background: rgba(255, 255, 255, .14); }
        header.public-band .band-links { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem 1rem; font-weight: 500; }
        header.public-band .icon-button { color: #ffffff; border-color: rgba(255, 255, 255, .4); }
        header.public-band .icon-button:hover { background: rgba(255, 255, 255, .14); color: #ffffff; }
        header.public-band .account-tools .user-menu summary { background: #ffffff; color: var(--band, #0f3e75); }
        header.public-band .user-menu ul a, header.public-band .user-menu li.sign-out button { color: var(--ink); }
        header.public-band select { background: rgba(255, 255, 255, .95); }

        /* The footer of every page: the institution, its pages and the open data, in columns that stack on a phone. */
        footer.site-footer { margin-top: 3rem; background: var(--surface); border-top: 1px solid var(--line); color: var(--muted-2); font-size: .94rem; }
        .site-footer-inner { max-width: 1180px; margin: 0 auto; padding: 2rem 1.4rem 1.2rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1.5rem 2.5rem; }
        .site-footer ul { list-style: none; margin: 0; padding: 0; }
        .site-footer li { margin: 0 0 .45rem; }
        .site-footer-name { margin: 0 0 .4rem; font-family: var(--serif); font-weight: 600; font-size: 1.15rem; color: var(--ink); }
        .site-footer-base { max-width: 1180px; margin: 0 auto; padding: .9rem 1.4rem 1.8rem; border-top: 1px solid var(--line); font-size: .86rem; color: var(--muted); }

        /* The dashboard's figures, and a line of a list with its date on the right. */
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 1rem; margin: 1.6rem 0; }
        .tile { display: flex; flex-direction: column; padding: 1rem 1.15rem; border: 1px solid var(--line); border-radius: 12px; background: var(--bg); color: var(--ink); text-decoration: none !important; }
        .tile:hover { border-color: var(--link); background: var(--surface); }
        .tile-label { font-size: .85rem; font-weight: 600; color: var(--muted-2); }
        .tile-figure { font-size: 1.9rem; font-weight: 700; color: var(--accent); line-height: 1.2; }
        .row-line { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; padding: .6rem 0; border-top: 1px solid var(--line); }
        .panel > h2 + .row-line { border-top: 0; }

        /* RiC terms are shown beside the fields only when the reader asks for them, and remembered on this browser. */
        html:not(.show-ric) .ric { display: none; }
        .ric-switch { display: inline-flex; align-items: center; gap: .45rem; margin: 0; font-size: .85rem; font-weight: 500; color: var(--muted-2); cursor: pointer; }
        .ric-switch input { width: 1.1rem; height: 1.1rem; margin: 0; }
    </style>
    <script>try { if (localStorage.getItem('openric.showRic') === '1') document.documentElement.classList.add('show-ric'); } catch (e) {}</script>
