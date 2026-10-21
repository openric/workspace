{{-- OpenRiC Workspace - the styles every page shares, signed in or public. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
    <style>
        @media print { nav[aria-label="Main"], header form, .no-print { display: none; } }
        :root { --ink: #1f2933; --muted: #52606d; --line: #cbd2d9; --accent: #10373e; --bg: #ffffff; --error: #9b1c1c; }
        @media (prefers-color-scheme: dark) { :root { --ink: #e4e7eb; --muted: #9aa5b1; --line: #3e4c59; --accent: #7fc4cf; --bg: #111a1d; --error: #f8b4b4; } }
        body { font: 16px/1.5 system-ui, sans-serif; color: var(--ink); background: var(--bg); margin: 0; }
        header, main { max-width: 60rem; margin: 0 auto; padding: 1rem; }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--line); }
        a { color: var(--accent); }
        label { display: block; margin-top: 1rem; font-weight: 600; }
        input { font: inherit; padding: .5rem; width: 100%; max-width: 24rem; box-sizing: border-box; border: 1px solid var(--line); background: var(--bg); color: var(--ink); }
        button { font: inherit; margin-top: 1rem; padding: .5rem 1rem; background: var(--accent); color: var(--bg); border: 0; cursor: pointer; }
        .error { color: var(--error); }
        button[aria-pressed="false"] { background: transparent; color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent); }
        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: .4rem; border-bottom: 1px solid var(--line); }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        .main-nav { max-width: 60rem; margin: 0 auto; padding: .5rem 1rem; border-bottom: 1px solid var(--line); }
        .main-nav ul { list-style: none; margin: 0; padding: 0; }
        .main-nav > ul { display: flex; gap: .25rem 1.25rem; flex-wrap: wrap; align-items: center; }
        .main-nav li { position: relative; }
        .main-nav summary { cursor: pointer; color: var(--accent); padding: .25rem 0; }
        .main-nav summary.here, .main-nav [aria-current="page"] { font-weight: 700; }
        .main-nav details ul { position: absolute; z-index: 10; top: 100%; left: 0; min-width: 13rem; padding: .35rem 0; background: var(--bg); border: 1px solid var(--line); box-shadow: 0 4px 12px rgb(0 0 0 / .12); }
        .main-nav > ul > li:nth-last-child(-n+2) details ul { left: auto; right: 0; }
        .main-nav details a { display: block; padding: .35rem .9rem; text-decoration: none; }
        .main-nav details a:hover { text-decoration: underline; }
        .main-nav details:not([open]) > ul { display: none; }
        /* Phones and narrow windows: nothing wider than the screen. Long IRIs and RiC-O terms wrap, wide tables scroll inside themselves, media shrink. */
        main, .main-nav { overflow-wrap: break-word; }
        code, .ric, dd, td { overflow-wrap: anywhere; }
        img, svg, video, audio, iframe { max-width: 100%; }
        img, video { height: auto; }
        select, textarea { max-width: 100%; box-sizing: border-box; }
        header { flex-wrap: wrap; gap: .5rem 1rem; }
        @media (max-width: 40rem) {
            header, main { padding: .75rem; }
            h1 { font-size: 1.6rem; }
            table { display: block; overflow-x: auto; }
            .main-nav > ul > li:has(details[open]) { flex-basis: 100%; }
            .main-nav details ul { position: static; box-shadow: none; border: 0; border-left: 2px solid var(--line); margin: .25rem 0 .25rem .25rem; }
            .main-nav summary, .main-nav a { padding: .5rem 0; display: inline-block; }
            .main-nav details a { display: block; padding: .5rem .9rem; }
            button, input, select { min-height: 2.75rem; }
        }
        .main-nav .icon { width: 1.1em; height: 1.1em; vertical-align: -.18em; margin-right: .35rem; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        /* Description areas as an accordion: each a bar that opens and closes; all open to start, and in print. */
        details.area { border: 1px solid var(--line); margin: 1rem 0; }
        details.area > summary { list-style: none; cursor: pointer; display: flex; align-items: baseline; gap: .75rem; flex-wrap: wrap; padding: .5rem .75rem; background: color-mix(in srgb, var(--accent) 8%, var(--bg)); }
        details.area > summary::-webkit-details-marker { display: none; }
        details.area > summary::before { content: ""; width: .5rem; height: .5rem; border-right: 2px solid var(--accent); border-bottom: 2px solid var(--accent); transform: rotate(-45deg); transition: transform .15s; align-self: center; flex: none; }
        details.area[open] > summary::before { transform: rotate(45deg); }
        details.area > summary h2 { margin: 0; font-size: 1.2rem; }
        details.area > :not(summary) { margin-left: .75rem; margin-right: .75rem; }
        details.area[open] { padding-bottom: .5rem; }
        @media print { details.area > summary::before { display: none; } }
        .ric { font-weight: normal; font-size: .8rem; color: var(--muted); margin-left: .35rem; } .ric a { color: var(--muted); }
    </style>
