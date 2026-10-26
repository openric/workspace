{{-- OpenRiC Workspace - the hierarchy tree beside a unit of description (Heratio's _treeview sidebar). Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Needs $tree (TreeService::window) and $branch (route name of the branch endpoint: records.tree-branch or discover.record.tree). Branches open with buttons
     that say whether they are open (aria-expanded); their units load as they open, a page at a time, as Heratio's do. --}}
@php
    $nodeHtml = function (array $node, bool $current = false, string $inner = '', bool $open = false) use ($branch) {
        $toggle = $node['hasChildren']
            ? '<button type="button" class="tree-toggle" aria-expanded="'.($open ? 'true' : 'false').'" data-branch="'.e(route($branch, $node['id'])).'" aria-label="'.e(($open ? 'Close ' : 'Open ').$node['title']).'"></button>'
            : '<span class="tree-leaf" aria-hidden="true"></span>';
        $link = $current ? '<strong aria-current="page">'.e($node['title']).'</strong>' : '<a href="'.e($node['url']).'">'.e($node['title']).'</a>';

        return '<li>'.$toggle.$link.($node['meta'] !== '' ? ' <small>'.e($node['meta']).'</small>' : '').($inner !== '' ? '<ul'.($open ? '' : ' hidden').' data-loaded="1">'.$inner.'</ul>' : '').'</li>';
    };
    $more = fn (string $parentId, int $offset) => '<li class="tree-more"><button type="button" data-branch="'.e(route($branch, $parentId)).'" data-offset="'.$offset.'">More</button></li>';
    // From the current unit up: its children (or the units either side of it), then each unit above wrapping what is below.
    $current = $tree['current'];
    $inner = implode('', array_map(fn ($n) => $nodeHtml($n), $tree['children'])).($tree['more'] ? $more($current['id'], \App\Description\TreeService::PAGE) : '');
    $level = implode('', array_map(fn ($n) => $nodeHtml($n), $tree['before']))
        .$nodeHtml($current, true, $inner, $inner !== '')
        .implode('', array_map(fn ($n) => $nodeHtml($n), $tree['after']));
    foreach (array_reverse($tree['ancestors']) as $ancestor) {
        $level = $nodeHtml($ancestor, false, $level, true);
    }
@endphp
<nav class="tree" aria-label="{{ __('Hierarchy') }}">
    <details class="tree-panel" open>
        <summary><h2>{{ __('Hierarchy') }}</h2></summary>
        <ul class="tree-root">{!! $level !!}</ul>
    </details>
</nav>
@once
    <style>
        .tree h2 { font-size: 1rem; margin: 0; border: 0; padding: 0; display: inline; }
        .tree-panel > summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: .5rem; }
        .tree-panel > summary::-webkit-details-marker { display: none; }
        .tree-panel > summary::before { content: ""; width: .45rem; height: .45rem; border-right: 2px solid var(--link); border-bottom: 2px solid var(--link); transform: rotate(-45deg); transition: transform .15s; flex: none; }
        .tree-panel[open] > summary::before { transform: rotate(45deg); }
        .tree-panel[open] > summary { margin-bottom: .5rem; }
        /* Collapsed: the tree folds to its heading and the record takes the width. */
        .with-tree:has(.tree-panel:not([open])) { grid-template-columns: max-content minmax(0, 1fr); }
        .with-tree:has(.tree-panel:not([open])) .tree-aside { max-height: none; }
        .tree ul { list-style: none; margin: 0; padding-left: 1.1rem; font-size: .9rem; line-height: 1.45; }
        .tree .tree-root { padding-left: 0; }
        .tree li { margin: .25rem 0; position: relative; padding-left: 1.3rem; }
        .tree li > .tree-toggle, .tree li > .tree-leaf { position: absolute; left: 0; top: .15rem; }
        .tree small { color: var(--muted); font-size: .78rem; }
        .tree a { text-decoration: none !important; }
        .tree a:hover { text-decoration: underline !important; }
        .tree strong[aria-current] { color: var(--accent); }
        .tree-toggle, .tree-leaf { display: inline-block; width: 1.1rem; height: 1.1rem; margin: 0 .2rem 0 0; padding: 0; vertical-align: -.15rem; border: 0; background: none; box-shadow: none; color: var(--muted-2); }
        .tree-toggle::before { content: ""; display: block; width: .4rem; height: .4rem; margin: .3rem auto; border-right: 2px solid currentColor; border-bottom: 2px solid currentColor; transform: rotate(-45deg); transition: transform .15s; }
        .tree-toggle[aria-expanded="true"]::before { transform: rotate(45deg); }
        .tree-toggle:hover { background: none; color: var(--accent); box-shadow: none; }
        .tree-more button { margin: 0; padding: .1rem .6rem; font-size: .8rem; background: var(--bg); color: var(--link); border: 1px solid var(--line); box-shadow: none; }
    </style>
    <script>
        // The panel stays as the reader left it, where the browser keeps such a choice.
        document.querySelectorAll('.tree-panel').forEach(panel => {
            try { if (localStorage.getItem('workspace.tree') === 'closed') panel.open = false; } catch (e) {}
            panel.addEventListener('toggle', () => { try { localStorage.setItem('workspace.tree', panel.open ? 'open' : 'closed'); } catch (e) {} });
        });
        // Heratio's lazy treeview: a branch's units load when it is first opened, "More" brings the next page.
        (function () {
            const esc = s => String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
            const li = n => '<li>' + (n.hasChildren ? '<button type="button" class="tree-toggle" aria-expanded="false" data-branch="' + esc(n.branch) + '" aria-label="Open ' + esc(n.title) + '"></button>' : '<span class="tree-leaf" aria-hidden="true"></span>')
                + '<a href="' + esc(n.url) + '">' + esc(n.title) + '</a>' + (n.meta ? ' <small>' + esc(n.meta) + '</small>' : '') + '</li>';
            async function load(url, offset) {
                const r = await fetch(url + (url.includes('?') ? '&' : '?') + 'offset=' + offset, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
                return r.ok ? r.json() : {items: [], more: false};
            }
            const render = (data, url, offset) => data.items.map(n => li({...n, branch: url.replace(/[^/]+\/(tree-branch|tree)$/, n.id + '/$1')})).join('')
                + (data.more ? '<li class="tree-more"><button type="button" data-branch="' + esc(url) + '" data-offset="' + (offset + data.items.length) + '">More</button></li>' : '');
            document.addEventListener('click', async e => {
                const toggle = e.target.closest('.tree-toggle');
                const more = e.target.closest('.tree-more button');
                if (toggle) {
                    const item = toggle.closest('li');
                    let group = item.querySelector(':scope > ul');
                    const open = toggle.getAttribute('aria-expanded') !== 'true';
                    if (open && !group) {
                        group = document.createElement('ul');
                        group.innerHTML = render(await load(toggle.dataset.branch, 0), toggle.dataset.branch, 0);
                        item.appendChild(group);
                    }
                    if (group) group.hidden = !open;
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    toggle.setAttribute('aria-label', (open ? 'Close ' : 'Open ') + (item.querySelector(':scope > a, :scope > strong')?.textContent || ''));
                } else if (more) {
                    const offset = parseInt(more.dataset.offset, 10);
                    const holder = more.closest('li');
                    holder.insertAdjacentHTML('beforebegin', render(await load(more.dataset.branch, offset), more.dataset.branch, offset));
                    holder.remove();
                }
            });
        })();
    </script>
@endonce
