{{-- OpenRiC Workspace - a record set's name-first explorer (roadmap phase 27), after the Smuts and Brenthurst explorers and Heratio's explorer page (ahg-api resources/explorer/index.html), whose data it reads: search a name, see it drawn with the units that carry it and the names printed beside it, the picture and text alongside. Double-click to drill down; right-click for the options. One quiet screen, no application frame. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('Explore: :title', ['title' => $data['site']['title']]) }}</title>
<style>
:root { --paper: #f4efe4; --panel: #fbf8f1; --band: #ede5d4; --ink: #2b2620; --muted: #6f665a; --rule: #d9cfbd; --accent: #653332; --gold: #E1B34F; --green: #10373E; }
* { box-sizing: border-box; }
html, body { margin: 0; height: 100%; }
body { background: var(--paper); color: var(--ink); font: 15px/1.55 Georgia, 'Times New Roman', serif; display: flex; flex-direction: column; }
a { color: var(--accent); }
header { padding: 14px 20px 10px; border-bottom: 1px solid var(--rule); }
header .over { font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: var(--muted); }
header h1 { margin: 2px 0; font-size: 23px; font-weight: normal; }
header .sub { font-size: 13px; color: var(--muted); font-style: italic; }
header .sub a { font-style: normal; }
.bar { background: var(--band); display: flex; flex-wrap: wrap; gap: 8px 10px; align-items: center; padding: 10px 20px; border-bottom: 1px solid var(--rule); }
.search { position: relative; flex: 1 1 300px; max-width: 520px; }
.search input { width: 100%; padding: 8px 12px; font: inherit; border: 1px solid var(--rule); border-radius: 3px; background: #fff; color: var(--ink); }
.sugg { position: absolute; z-index: 20; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid var(--rule); border-top: 0; max-height: 340px; overflow: auto; display: none; }
.sugg div { padding: 6px 12px; cursor: pointer; display: flex; gap: 8px; align-items: baseline; }
.sugg div:hover, .sugg div.on { background: var(--paper); }
.sugg small, .list small, .meta { color: var(--muted); }
.chip { display: inline-flex; align-items: center; gap: 6px; padding: 2px 10px; border: 1px solid var(--rule); border-radius: 999px; font-size: 13px; cursor: pointer; background: #fff; user-select: none; }
.chip.off { opacity: .4; }
.dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex: none; }
#trail { width: 100%; font-size: 13px; color: var(--muted); min-height: 1.2em; }
#trail a { cursor: pointer; text-decoration: none; }
#trail a:hover { text-decoration: underline; }
main { flex: 1; display: grid; grid-template-columns: minmax(0, 1fr) 420px; min-height: 0; }
#graph { min-height: 0; position: relative; }
#hint { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: var(--muted); font-style: italic; pointer-events: none; text-align: center; padding: 40px; }
aside { border-left: 1px solid var(--rule); background: var(--panel); overflow: auto; padding: 16px 20px 30px; }
aside h2 { font-size: 21px; font-weight: normal; margin: 4px 0 2px; }
aside h3 { font-size: 12px; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); font-weight: normal; margin: 18px 0 6px; }
.meta { font-size: 13px; }
.list a { display: block; padding: 6px 0; border-bottom: 1px dotted var(--rule); color: var(--ink); text-decoration: none; cursor: pointer; }
.list a:hover { color: var(--accent); }
.tags { display: flex; flex-wrap: wrap; gap: 6px; }
.btn { display: inline-block; margin: 10px 8px 0 0; padding: 6px 12px; border: 1px solid var(--green); color: var(--green); background: transparent; border-radius: 3px; text-decoration: none; font: inherit; font-size: 14px; cursor: pointer; }
img.evidence { display: block; max-width: 100%; max-height: 340px; margin: 10px 0; border: 1px solid var(--rule); background: #fff; cursor: zoom-in; }
p.excerpt { font-size: 14px; margin: 8px 0 12px; }
pre.transcript { white-space: pre-wrap; font: 14px/1.5 Georgia, serif; background: #fff; border: 1px solid var(--rule); padding: 10px 12px; max-height: 50vh; overflow: auto; }
#timeline { height: 70px; border-top: 1px solid var(--rule); background: var(--panel); }
#timeline svg { width: 100%; height: 100%; display: block; }
footer { padding: 8px 20px; font-size: 12px; color: var(--muted); border-top: 1px solid var(--rule); display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
#menu { position: fixed; z-index: 50; display: none; min-width: 240px; background: #fff; border: 1px solid var(--rule); box-shadow: 0 4px 14px rgba(0,0,0,.15); padding: 4px 0; font-size: 14px; }
#menu button, #menu a { display: block; width: 100%; text-align: left; padding: 6px 14px; border: 0; background: none; font: inherit; color: var(--ink); cursor: pointer; text-decoration: none; }
#menu button:hover, #menu a:hover, #menu button:focus, #menu a:focus { background: var(--paper); outline: none; }
#menu hr { border: 0; border-top: 1px solid var(--rule); margin: 4px 0; }
#menu .head { padding: 4px 14px 6px; font-size: 12px; color: var(--muted); }
#zoom { position: fixed; inset: 0; z-index: 60; background: rgba(20,18,15,.92); display: none; overflow: hidden; cursor: grab; }
#zoom img { position: absolute; left: 50%; top: 50%; transform-origin: 0 0; max-width: none; user-select: none; }
#zoom .tools { position: absolute; top: 10px; right: 12px; display: flex; gap: 6px; }
#zoom .tools button { font: inherit; padding: 4px 10px; border: 1px solid #888; background: #222; color: #eee; border-radius: 3px; cursor: pointer; }
form.settings label { display: block; margin: 8px 0 2px; font-size: 13px; }
form.settings textarea, form.settings input[type=number] { width: 100%; font: inherit; padding: 6px; border: 1px solid var(--rule); }
.status { background: #e8f0e8; padding: 6px 10px; border: 1px solid #b9cfb9; }
@media (max-width: 900px) { main { grid-template-columns: 1fr; grid-template-rows: 55vh auto; } aside { border-left: 0; border-top: 1px solid var(--rule); } }
</style>
</head>
<body>
<header>
    <div class="over">{{ $data['site']['scope'] }}</div>
    <h1>{{ $data['site']['title'] }}</h1>
    <div class="sub">{{ __(':records units and :names names, places and events.', ['records' => number_format(count($data['records'])), 'names' => number_format(count($data['entities']))]) }}
        <a href="{{ route('discover.record', $set->id) }}">{{ __('The description') }}</a> &middot; <a href="{{ route('discover.graph', ['records', $set->id]) }}">{{ __('The RiC graph') }}</a></div>
</header>
<div class="bar">
    <div class="search"><input id="q" placeholder="{{ __('Search a person, place, event or title') }}" autocomplete="off" aria-label="{{ __('Search a person, place, event or title') }}"><div class="sugg" id="sugg" role="listbox"></div></div>
    <div id="chips"></div>
    <div id="trail" hidden aria-label="{{ __('Where you have been') }}"></div>
</div>
<main>
    <div id="graph" aria-label="{{ __('The graph; the panel beside it lists the same connections') }}"><div id="hint">{{ __('Search for a name, or pick one beside, to see how it connects across the records. Double-click to drill down; right-click for more.') }}</div></div>
    <aside id="side" aria-live="polite"></aside>
</main>
<div id="timeline"></div>
<footer>
    <span>{{ __('Built from the catalogue on :date.', ['date' => $data['site']['generated']]) }}</span>
    <span>{{ $settings->unreviewed ? __('Names come from the catalogue and from the text, including names not yet reviewed: they are given only as printed.') : __('Names come from the catalogue and from reviewed names found in the text.') }}</span>
</footer>
<div id="menu" role="menu"></div>
<div id="zoom" aria-hidden="true"><img alt=""><div class="tools"><button type="button" data-z="in">+</button><button type="button" data-z="out">-</button><button type="button" data-z="fit">{{ __('Fit') }}</button><button type="button" data-z="close">{{ __('Close') }}</button></div></div>

@if($editable)
<template id="settings-form">
    <h3>{{ __('Explorer settings (staff)') }}</h3>
    <form class="settings" method="post" action="{{ route('explorer.settings', $set->id) }}">
        @csrf
        <label for="hide">{{ __('General words to hide, one a line or separated by commas') }}</label>
        <textarea id="hide" name="hide" rows="4">{{ $settings->hide }}</textarea>
        <label for="min_mentions">{{ __('The fewest units a name must be on') }}</label>
        <input id="min_mentions" type="number" name="min_mentions" min="1" max="1000" value="{{ $settings->min_mentions ?: 1 }}">
        <label><input type="checkbox" name="unreviewed" value="1" @checked($settings->unreviewed)> {{ __('Show names found in the text that are not yet reviewed') }}</label>
        <label for="min_confidence">{{ __('Of those, only names found with at least this confidence (0 to 1; empty for all)') }}</label>
        <input id="min_confidence" type="number" name="min_confidence" min="0" max="1" step="0.01" value="{{ $settings->min_confidence }}">
        <button class="btn" type="submit">{{ __('Save the settings') }}</button>
    </form>
</template>
@endif

@php($words = [
    'types' => ['name' => __('People and organisations'), 'place' => __('Places'), 'event' => __('Events'), 'subject' => __('Subjects'), 'genre' => __('Genres')],
    'general' => __('General words'), 'units' => __('units'), 'on' => __('On :n units'), 'printedAs' => __('Given as: '), 'alongside' => __('Appears alongside'),
    'start' => __('Where to start'), 'startNote' => __('The names on most units. Click one, or search above.'), 'none' => __('No match'),
    'centre' => __('Centre on this'), 'expand' => __('Drill down: add what it connects to'), 'only' => __('Show only :type'), 'all' => __('Show every kind again'),
    'pages' => __('List the units that name it'), 'openPage' => __('Open its page'), 'ricGraph' => __('Show in the RiC graph'), 'hide' => __('Hide this name from the view'),
    'hideList' => __('Add to the hide list (staff)'), 'openRecord' => __('Open the record'), 'image' => __('Open the image'), 'transcript' => __('Read the transcript'),
    'names' => __('Names on this unit'), 'download' => __('Download the image'), 'fit' => __('Fit to the screen'), 'back' => __('Back'), 'restart' => __('Start again'),
    'showHidden' => __('Show the names hidden from the view'), 'png' => __('Save this view as a picture'), 'linked' => __('Linked with'), 'loading' => __('Reading the transcript...'),
    'noText' => __('The transcript cannot be read here.'), 'untitled' => __('(untitled)'), 'trail' => __('You came by: '),
])
<script src="{{ asset('vendor/graph/cytoscape.min.js') }}"></script>
<script type="application/json" id="explorer-data">@json($data)</script>
<script>
(function () {
const D = JSON.parse(document.getElementById('explorer-data').textContent);
const T = @json($words);
const TYPES = { name: [T.types.name, '#9a3f1c'], place: [T.types.place, '#3f6b5a'], event: [T.types.event, '#a8741a'], subject: [T.types.subject, '#10373E'], genre: [T.types.genre, '#7a6a8a'] };
// A type the page does not know (from another system's data) is left out, not an error.
const D_ENTITIES = D.entities.filter(e => TYPES[e.type]); D.entities.length = 0; D.entities.push(...D_ENTITIES);
const REC = new Map(D.records.map(r => [r.id, r])), ENT = new Map(D.entities.map(e => [e.id, e]));
const on = new Set(Object.keys(TYPES)), muted = new Set();
let general = false, current = null, trail = [];
const vis = e => e && on.has(e.type) && (general || !e.hidden) && !muted.has(e.id);
const fmt = n => n.toLocaleString();
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const ref = r => [r.ref && r.ref !== r.title ? esc(r.ref) : '', esc(r.date)].filter(Boolean).join(' &middot; ');
const graphOf = url => { const m = /\/discover\/(agents|places|activities|records)\/([0-9a-f-]{36})/.exec(url || ''); return m ? `/discover/graph/${m[1]}/${m[2]}` : null; };

const chips = document.getElementById('chips');
const drawChips = () => { chips.innerHTML = ''; Object.entries(TYPES).forEach(([t, [label, col]]) => {
    const n = D.entities.filter(e => e.type === t).length; if (!n) return;
    const el = document.createElement('span'); el.className = 'chip' + (on.has(t) ? '' : ' off'); el.innerHTML = `<span class="dot" style="background:${col}"></span>${esc(label)} <small>${fmt(n)}</small>`;
    el.onclick = () => { on.has(t) ? on.delete(t) : on.add(t); redraw(); }; chips.appendChild(el); });
  const nHidden = D.entities.filter(e => e.hidden).length;
  if (nHidden) { const el = document.createElement('span'); el.className = 'chip' + (general ? '' : ' off'); el.innerHTML = `<span class="dot" style="background:#9b9182"></span>${esc(T.general)} <small>${fmt(nHidden)}</small>`;
    el.onclick = () => { general = !general; redraw(); }; chips.appendChild(el); } };
const redraw = () => { drawChips(); current ? focus(current, false) : welcome(); };

const cy = cytoscape({ container: document.getElementById('graph'), minZoom: 0.15, maxZoom: 3, wheelSensitivity: 0.3,
  style: [
    { selector: 'node', style: { 'label': 'data(label)', 'font-family': 'Georgia, serif', 'font-size': 11, 'color': '#2b2620', 'text-wrap': 'wrap', 'text-max-width': 130,
      'text-valign': 'bottom', 'text-margin-y': 4, 'width': 'data(size)', 'height': 'data(size)', 'background-color': 'data(color)' } },
    { selector: 'node.rec', style: { 'shape': 'round-rectangle', 'background-color': '#ffffff', 'border-width': 1.5, 'border-color': '#9b9182', 'font-size': 9, 'color': '#6f665a', 'text-valign': 'center', 'text-margin-y': 0 } },
    { selector: 'node.hover', style: { 'label': 'data(full)', 'z-index': 99, 'text-background-color': '#ffffff', 'text-background-opacity': 1, 'text-background-padding': 3 } },
    { selector: 'node.centre', style: { 'font-size': 15, 'font-weight': 'bold', 'border-width': 3, 'border-color': '#E1B34F' } },
    { selector: 'node.added', style: { 'border-width': 2, 'border-color': '#a8741a', 'border-style': 'dashed' } },
    { selector: 'edge', style: { 'width': 1, 'line-color': '#cfc4ae', 'curve-style': 'haystack', 'opacity': .8 } },
    { selector: 'edge.added', style: { 'line-style': 'dashed', 'line-color': '#a8741a' } },
  ] });
window.explorer = { cy }; // for a test or a browser console
cy.on('tap', 'node', ev => { hideMenu(); focus(ev.target.id()); });
cy.on('dbltap', 'node', ev => expand(ev.target.id()));
cy.on('mouseover', 'node.rec', ev => ev.target.addClass('hover'));
cy.on('mouseout', 'node.rec', ev => ev.target.removeClass('hover'));
cy.on('tap', ev => { if (ev.target === cy) hideMenu(); });

const entNode = (e, centre) => ({ data: { id: e.id, label: e.name, color: TYPES[e.type][1], size: centre ? 46 : Math.min(36, 14 + 3 * Math.sqrt(e.recs.length)) }, classes: centre ? 'centre' : '' });
const recNode = (r, centre) => ({ data: { id: r.id, label: r.ref || (r.title || '').slice(0, 24), full: `${r.ref || ''}\n${(r.title || '').slice(0, 70)}`, color: '#fff', size: centre ? 44 : 34 }, classes: 'rec' + (centre ? ' centre' : '') });

// The neighbourhood of one node: a name's units and the names they share with it; a unit's names and a few other units of each.
function around(id) {
  const els = [], edges = [], co = new Map();
  if (ENT.has(id)) {
    const e = ENT.get(id);
    const shown = e.recs.length <= 40 ? e.recs : e.recs.slice().sort((a, b) => REC.get(b).ents.length - REC.get(a).ents.length).slice(0, 40);
    shown.forEach(rid => { const r = REC.get(rid); els.push(recNode(r)); edges.push([id, rid]);
      r.ents.forEach(k => { if (k !== id && vis(ENT.get(k))) co.set(k, (co.get(k) || []).concat(rid)); }); });
    [...co.entries()].sort((a, b) => b[1].length - a[1].length).slice(0, 30).forEach(([k, rids]) => { els.push(entNode(ENT.get(k))); rids.forEach(rid => edges.push([rid, k])); });
  } else if (REC.has(id)) {
    const r = REC.get(id);
    r.ents.forEach(k => { const e = ENT.get(k); if (!vis(e)) return; els.push(entNode(e)); edges.push([id, k]);
      e.recs.filter(x => x !== id).slice(0, 6).forEach(x => { els.push(recNode(REC.get(x))); edges.push([k, x]); }); });
  }
  return { els, edges, co };
}
function focus(id, push = true) {
  if (!ENT.has(id) && !REC.has(id)) return;
  current = id; document.getElementById('hint').style.display = 'none';
  const isEnt = ENT.has(id), centre = isEnt ? entNode(ENT.get(id), true) : recNode(REC.get(id), true);
  const { els, edges, co } = around(id), seen = new Set([id]), out = [centre];
  els.forEach(el => { if (!seen.has(el.data.id)) { seen.add(el.data.id); out.push(el); } });
  edges.forEach(([a, b], i) => out.push({ data: { id: `${a}|${b}|${i}`, source: a, target: b } }));
  cy.elements().remove(); cy.add(out);
  cy.layout({ name: 'concentric', concentric: n => n.hasClass('centre') ? 3 : (n.hasClass('rec') ? 2 : 1), levelWidth: () => 1,
              minNodeSpacing: 34, spacingFactor: 1.1, animate: true, animationDuration: 350 }).run();
  if (push) { if (trail[trail.length - 1] !== id) trail.push(id); history.replaceState(null, '', '#' + encodeURIComponent(id)); }
  drawTrail(); isEnt ? showEntity(ENT.get(id), co) : showRecord(REC.get(id)); drawTimeline();
}
// Drill down: what the node connects to is added around it, and what was drawn stays.
function expand(id) {
  if (!cy.getElementById(id).length) return focus(id);
  const { els, edges } = around(id), before = new Set(cy.nodes().map(n => n.id())), add = [];
  els.forEach(el => { if (!before.has(el.data.id) && !add.some(a => a.data.id === el.data.id)) add.push({ ...el, classes: (el.classes || '') + ' added' }); });
  edges.forEach(([a, b], i) => { if (!cy.edges(`[source = "${a}"][target = "${b}"], [source = "${b}"][target = "${a}"]`).length) add.push({ data: { id: `x|${a}|${b}|${Date.now()}|${i}`, source: a, target: b }, classes: 'added' }); });
  if (!add.length) return;
  const p = cy.getElementById(id).position(); add.forEach(el => { if (!el.data.source) el.position = { x: p.x + (Math.random() - .5) * 60, y: p.y + (Math.random() - .5) * 60 }; });
  cy.add(add);
  cy.layout({ name: 'cose', animate: true, animationDuration: 500, randomize: false, fit: true, nodeRepulsion: () => 9000, idealEdgeLength: () => 70 }).run();
  drawTimeline();
}
const drawTrail = () => { const el = document.getElementById('trail'); el.hidden = trail.length < 2; if (trail.length < 2) { el.innerHTML = ''; return; }
  el.innerHTML = esc(T.trail) + trail.slice(-8).map((id, i, a) => { const label = ENT.has(id) ? ENT.get(id).name : (REC.get(id).title || T.untitled);
    return i === a.length - 1 ? `<strong>${esc(label)}</strong>` : `<a data-crumb="${esc(id)}">${esc(label)}</a>`; }).join(' &rsaquo; '); };
document.getElementById('trail').addEventListener('click', ev => { const a = ev.target.closest('[data-crumb]'); if (!a) return;
  trail = trail.slice(0, trail.lastIndexOf(a.dataset.crumb) + 1); focus(a.dataset.crumb, false); history.replaceState(null, '', '#' + encodeURIComponent(a.dataset.crumb)); });
const back = () => { if (trail.length > 1) { trail.pop(); const id = trail[trail.length - 1]; focus(id, false); history.replaceState(null, '', '#' + encodeURIComponent(id)); } };

const side = document.getElementById('side'), form = document.getElementById('settings-form');
const settings = () => form ? form.innerHTML : '';
const linkRec = rid => { const r = REC.get(rid); return `<a data-go="${esc(rid)}">${esc(r.title || T.untitled)}<br><small>${ref(r)}</small></a>`; };
const chip = k => { const x = ENT.get(k); return `<span class="chip" data-go="${esc(k)}"><span class="dot" style="background:${TYPES[x.type][1]}"></span>${esc(x.name)}`; };
function showEntity(e, co) {
  const top = [...co.entries()].sort((a, b) => b[1].length - a[1].length).slice(0, 18), g = graphOf(e.url);
  side.innerHTML = `<div class="meta"><span class="dot" style="background:${TYPES[e.type][1]}"></span> ${esc(TYPES[e.type][0])}</div>
    <h2>${esc(e.name)}</h2>${e.variants.length > 1 ? `<div class="meta">${esc(T.printedAs)}${e.variants.map(esc).join('; ')}</div>` : ''}
    ${e.url ? `<a class="btn" href="${esc(e.url)}">${esc(T.openPage)}</a>` : ''}${g ? `<a class="btn" href="${esc(g)}">${esc(T.ricGraph)}</a>` : ''}
    <h3>${esc(T.on.replace(':n', fmt(e.recs.length)))}</h3><div class="list">${e.recs.slice(0, 500).map(linkRec).join('')}</div>
    ${top.length ? `<h3>${esc(T.alongside)}</h3><div class="tags">${top.map(([k, c]) => chip(k) + ` <small>${c.length}</small></span>`).join('')}</div>` : ''}` + settings();
}
function showRecord(r, withText = false) {
  side.innerHTML = `<div class="meta">${ref(r)}</div><h2>${esc(r.title || T.untitled)}</h2>
    ${r.img ? `<img class="evidence" src="${esc(r.img)}" alt="${esc(r.title || '')}" loading="lazy" data-zoom="${esc(r.img)}" onerror="this.remove()">` : ''}
    ${r.text ? `<p class="excerpt">${esc(r.text)}</p>` : ''}
    <a class="btn" href="${esc(r.url)}">${esc(T.openRecord)}</a>${r.textUrl ? `<button class="btn" type="button" data-read="${esc(r.id)}">${esc(T.transcript)}</button>` : ''}
    <a class="btn" href="${esc(graphOf(r.url) || '#')}">${esc(T.ricGraph)}</a>
    <div id="transcript"></div>
    ${r.ents.filter(k => vis(ENT.get(k))).length ? `<h3>${esc(T.linked)}</h3><div class="tags">${r.ents.filter(k => vis(ENT.get(k))).map(k => chip(k) + '</span>').join('')}</div>` : ''}` + settings();
  if (withText) readText(r);
}
function readText(r) {
  const box = document.getElementById('transcript'); if (!box || !r.textUrl) return;
  box.innerHTML = `<p class="meta">${esc(T.loading)}</p>`;
  fetch(r.textUrl, { credentials: 'same-origin' }).then(res => res.ok ? res.text() : Promise.reject()).then(t => { box.innerHTML = `<h3>${esc(T.transcript)}</h3><pre class="transcript">${esc(t)}</pre>`; box.scrollIntoView({ block: 'nearest' }); },
    () => { box.innerHTML = `<p class="meta">${esc(T.noText)}</p>`; });
}
side.addEventListener('click', ev => {
  const go = ev.target.closest('[data-go]'); if (go) return focus(go.dataset.go);
  const rd = ev.target.closest('[data-read]'); if (rd) return readText(REC.get(rd.dataset.read));
  const z = ev.target.closest('[data-zoom]'); if (z) return zoomOpen(z.dataset.zoom);
});
function welcome() {
  const top = D.entities.filter(vis).sort((a, b) => b.recs.length - a.recs.length).slice(0, 30);
  side.innerHTML = `<h2>${esc(T.start)}</h2><div class="meta">${esc(T.startNote)}</div>
    <div class="tags" style="margin-top:12px">${top.map(x => chip(x.id) + ` <small>${fmt(x.recs.length)}</small></span>`).join('')}</div>` + settings();
}

// The right-click menu: on a name, on a unit, and on the empty graph.
const menu = document.getElementById('menu');
const hideMenu = () => { menu.style.display = 'none'; };
function showMenu(x, y, items, head) {
  menu.innerHTML = (head ? `<div class="head">${esc(head)}</div>` : '') + items.map((it, i) => it === '-' ? '<hr>' : it.href
    ? `<a role="menuitem" href="${esc(it.href)}" ${it.download ? 'download' : ''}>${esc(it.label)}</a>` : `<button role="menuitem" type="button" data-i="${i}">${esc(it.label)}</button>`).join('');
  menu.style.display = 'block';
  const w = menu.offsetWidth, h = menu.offsetHeight;
  menu.style.left = Math.min(x, innerWidth - w - 4) + 'px'; menu.style.top = Math.min(y, innerHeight - h - 4) + 'px';
  menu.onclick = ev => { const b = ev.target.closest('[data-i]'); if (b) { hideMenu(); items[+b.dataset.i].run(); } else if (ev.target.closest('a')) hideMenu(); };
  const first = menu.querySelector('button, a'); if (first) first.focus();
}
cy.on('cxttap', ev => {
  const { x, y } = ev.originalEvent ? { x: ev.originalEvent.clientX, y: ev.originalEvent.clientY } : { x: 100, y: 100 };
  if (ev.target === cy) return showMenu(x, y, [
    { label: T.fit, run: () => cy.fit(undefined, 30) }, { label: T.back, run: back }, { label: T.restart, run: () => { trail = []; current = null; cy.elements().remove(); document.getElementById('hint').style.display = ''; history.replaceState(null, '', location.pathname); drawTrail(); welcome(); drawTimeline(); } },
    ...(muted.size ? [{ label: T.showHidden, run: () => { muted.clear(); redraw(); } }] : []),
    { label: T.png, run: () => { const a = document.createElement('a'); a.href = cy.png({ full: true, scale: 2, bg: '#f4efe4' }); a.download = 'explorer.png'; a.click(); } },
  ]);
  const id = ev.target.id();
  if (ENT.has(id)) {
    const e = ENT.get(id), g = graphOf(e.url);
    showMenu(x, y, [
      { label: T.centre, run: () => focus(id) }, { label: T.expand, run: () => expand(id) },
      { label: T.pages, run: () => focus(id) }, '-',
      { label: T.only.replace(':type', TYPES[e.type][0].toLowerCase()), run: () => { on.clear(); on.add(e.type); redraw(); } },
      ...(on.size < Object.keys(TYPES).length ? [{ label: T.all, run: () => { Object.keys(TYPES).forEach(t => on.add(t)); redraw(); } }] : []),
      { label: T.hide, run: () => { muted.add(id); if (current === id) back(); redraw(); } },
      ...(form ? [{ label: T.hideList, run: () => { const t = side.querySelector('#hide'); if (t) { t.value = (t.value.trim() ? t.value.trim() + '\n' : '') + e.name; t.focus(); t.scrollIntoView({ block: 'center' }); } } }] : []), '-',
      ...(e.url ? [{ label: T.openPage, href: e.url }] : []), ...(g ? [{ label: T.ricGraph, href: g }] : []),
    ], e.name);
  } else if (REC.has(id)) {
    const r = REC.get(id);
    showMenu(x, y, [
      { label: T.centre, run: () => focus(id) }, { label: T.expand, run: () => expand(id) }, { label: T.names, run: () => focus(id) }, '-',
      ...(r.img ? [{ label: T.image, run: () => zoomOpen(r.img) }] : []),
      ...(r.textUrl ? [{ label: T.transcript, run: () => { focus(id); showRecord(r, true); } }] : []), '-',
      { label: T.openRecord, href: r.url }, { label: T.ricGraph, href: graphOf(r.url) },
      ...(r.img ? [{ label: T.download, href: r.img + (r.img.includes('?') ? '&' : '?') + 'download=1', download: true }] : []),
    ], r.title || r.ref);
  }
});
document.getElementById('graph').addEventListener('contextmenu', ev => ev.preventDefault());
document.addEventListener('keydown', ev => { if (ev.key === 'Escape') { hideMenu(); zoomClose(); } });
document.addEventListener('click', ev => { if (!menu.contains(ev.target)) hideMenu(); });

// The picture full screen: wheel or buttons to zoom, drag to move.
const zoom = document.getElementById('zoom'), zimg = zoom.querySelector('img');
let zs = 1, zx = 0, zy = 0, drag = null;
const zApply = () => { zimg.style.transform = `translate(${zx}px, ${zy}px) scale(${zs})`; };
const zFit = () => { const s = Math.min(innerWidth / zimg.naturalWidth, innerHeight / zimg.naturalHeight) * .95 || 1; zs = s; zx = -zimg.naturalWidth * s / 2; zy = -zimg.naturalHeight * s / 2; zApply(); };
const zBy = f => { zx *= f; zy *= f; zs *= f; zApply(); };
function zoomOpen(src) { zimg.onload = zFit; zimg.src = src; zoom.style.display = 'block'; zoom.setAttribute('aria-hidden', 'false'); }
function zoomClose() { zoom.style.display = 'none'; zoom.setAttribute('aria-hidden', 'true'); }
zoom.addEventListener('wheel', ev => { ev.preventDefault(); zBy(ev.deltaY < 0 ? 1.15 : 1 / 1.15); }, { passive: false });
zoom.addEventListener('mousedown', ev => { if (ev.target === zimg) { drag = [ev.clientX - zx, ev.clientY - zy]; ev.preventDefault(); } });
addEventListener('mousemove', ev => { if (drag) { zx = ev.clientX - drag[0]; zy = ev.clientY - drag[1]; zApply(); } });
addEventListener('mouseup', () => { drag = null; });
zoom.querySelector('.tools').addEventListener('click', ev => { const z = ev.target.dataset.z; if (z === 'in') zBy(1.25); if (z === 'out') zBy(.8); if (z === 'fit') zFit(); if (z === 'close') zoomClose(); });

// The search over every name and title.
const q = document.getElementById('q'), sugg = document.getElementById('sugg');
const index = [...D.entities.map(e => ({ id: e.id, label: e.name, sub: `${TYPES[e.type][0]} - ${fmt(e.recs.length)} ${T.units}`, keys: [e.name, ...e.variants].join(' ').toLowerCase(), color: TYPES[e.type][1], weight: e.recs.length })),
               ...D.records.map(r => ({ id: r.id, label: r.title || T.untitled, sub: r.ref, keys: ((r.title || '') + ' ' + (r.ref || '')).toLowerCase(), color: '#fff', weight: 0 }))];
let pick = 0, hits = [];
q.addEventListener('input', () => {
  const s = q.value.trim().toLowerCase(); pick = 0;
  if (s.length < 2) { sugg.style.display = 'none'; return; }
  hits = index.filter(x => x.keys.includes(s)).sort((a, b) => b.weight - a.weight).slice(0, 14);
  sugg.innerHTML = hits.map((h, i) => `<div role="option" data-i="${i}" class="${i === 0 ? 'on' : ''}"><span class="dot" style="background:${h.color};border:1px solid #9b9182"></span>${esc(h.label)} <small>${esc(h.sub)}</small></div>`).join('') || `<div><small>${esc(T.none)}</small></div>`;
  sugg.style.display = 'block';
});
q.addEventListener('keydown', ev => {
  if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') { pick = Math.max(0, Math.min(hits.length - 1, pick + (ev.key === 'ArrowDown' ? 1 : -1))); [...sugg.children].forEach((d, i) => d.classList.toggle('on', i === pick)); ev.preventDefault(); }
  if (ev.key === 'Enter' && hits[pick]) { focus(hits[pick].id); sugg.style.display = 'none'; q.blur(); }
  if (ev.key === 'Escape') sugg.style.display = 'none';
});
sugg.addEventListener('mousedown', ev => { const d = ev.target.closest('[data-i]'); if (d) { focus(hits[+d.dataset.i].id); sugg.style.display = 'none'; } });
q.addEventListener('blur', () => setTimeout(() => { sugg.style.display = 'none'; }, 150));

// The timeline: every unit with a readable date; those in view stand out.
const MONTHS = ['january','february','march','april','may','june','july','august','september','october','november','december'];
function when(s) {
  let m = /^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?/.exec(s || ''); if (m) return Date.UTC(+m[1], (+m[2] || 1) - 1, +m[3] || 1);
  m = /(\d{1,2})(?:\s*-\s*\d{1,2})?\s+([a-z]+)\s+(\d{4})/i.exec(s || ''); if (m && MONTHS.includes(m[2].toLowerCase())) return Date.UTC(+m[3], MONTHS.indexOf(m[2].toLowerCase()), +m[1]);
  m = /([a-z]+)\s+(\d{4})/i.exec(s || ''); if (m && MONTHS.includes(m[1].toLowerCase())) return Date.UTC(+m[2], MONTHS.indexOf(m[1].toLowerCase()), 1);
  m = /(\d{4})/.exec(s || ''); return m ? Date.UTC(+m[1], 0, 1) : null;
}
const DATED = D.records.map(r => [r, when(r.date)]).filter(([, t]) => t !== null);
function drawTimeline() {
  const el = document.getElementById('timeline');
  const ts = DATED.map(([, t]) => t), lo = Math.min(...ts), hi = Math.max(...ts);
  if (DATED.length < 2 || hi === lo) { el.style.display = 'none'; return; }
  const W = el.clientWidth || 1000, pad = 30, x = v => pad + (W - 2 * pad) * (v - lo) / Math.max(1, hi - lo);
  const shown = new Set(cy.nodes('.rec').map(n => n.id())), years = hi - lo > 3 * 365 * 864e5, days = hi - lo < 62 * 864e5;
  const ticks = []; for (let d = new Date(lo); d.getTime() <= hi && ticks.length < 400; years ? d.setUTCFullYear(d.getUTCFullYear() + 1, 0, 1) : days ? d.setUTCDate(d.getUTCDate() + 1) : d.setUTCMonth(d.getUTCMonth() + 1, 1)) ticks.push(new Date(d));
  const every = Math.ceil(ticks.length / 16), label = m => years ? m.getUTCFullYear() : m.toLocaleString(undefined, days ? { day: 'numeric', month: 'short', timeZone: 'UTC' } : { month: 'short', year: 'numeric', timeZone: 'UTC' });
  el.innerHTML = `<svg viewBox="0 0 ${W} 70" preserveAspectRatio="none"><line x1="${pad}" x2="${W - pad}" y1="40" y2="40" stroke="#d9cfbd"/>
    ${ticks.filter((_, i) => i % every === 0).map(m => `<text x="${x(m.getTime())}" y="62" font-size="11" fill="#6f665a" text-anchor="middle" font-family="Georgia, serif">${label(m)}</text>`).join('')}
    ${DATED.map(([r, t]) => `<line data-go="${esc(r.id)}" x1="${x(t)}" x2="${x(t)}" y1="${shown.has(r.id) ? 8 : 26}" y2="40" stroke="${shown.has(r.id) ? '#653332' : '#cfc4ae'}" stroke-width="3" style="cursor:pointer"><title>${esc((r.date || '') + ' - ' + (r.title || ''))}</title></line>`).join('')}
  </svg>`;
}
document.getElementById('timeline').addEventListener('click', ev => { const t = ev.target.closest('[data-go]'); if (t) focus(t.dataset.go); });
addEventListener('resize', drawTimeline);
addEventListener('hashchange', () => { const h = decodeURIComponent(location.hash.slice(1)); if (h !== current && (ENT.has(h) || REC.has(h))) focus(h); });

drawChips();
const start = decodeURIComponent(location.hash.slice(1));
if (start && (ENT.has(start) || REC.has(start))) focus(start); else { welcome(); drawTimeline(); }
@if(session('status'))side.insertAdjacentHTML('afterbegin', `<p class="status">${esc(@json(session('status')))}</p>`);@endif
})();
</script>
</body>
</html>
