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
        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: .4rem; border-bottom: 1px solid var(--line); }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        .ric { font-weight: normal; font-size: .8rem; color: var(--muted); margin-left: .35rem; } .ric a { color: var(--muted); }
    </style>
