// OpenRiC Workspace - the offline catalogue's viewer (roadmap phase 11c), after Heratio's ahg-portable-export viewer.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Reads window.CATALOGUE from data/catalogue.js: a page opened from disk may load a script beside it, never fetch a
// file. In an editable package what the reader adds is kept in localStorage and saved as PSIS's researcher exchange
// file (format 1.0), which the Workspace imports as a draft submission.
(function () {
  'use strict';
  var C = window.CATALOGUE;
  var listEl = document.getElementById('records');
  var detailEl = document.getElementById('detail');
  var qEl = document.getElementById('q');
  if (!C) {
    detailEl.innerHTML = '<p class="empty">The descriptions could not be read: data/catalogue.js is missing. Keep the folder together.</p>';
    return;
  }
  document.getElementById('institution').textContent = C.institution;
  var EDITABLE = C.mode === 'editable';
  var byId = {}, agents = {}, repositories = {}, depth = {};
  C.records.forEach(function (r) { byId[r.id] = r; });
  C.agents.forEach(function (a) { agents[a.id] = a; });
  C.repositories.forEach(function (r) { repositories[r.id] = r; });
  function level(r) {
    if (depth[r.id] !== undefined) return depth[r.id];
    var d = 0, p = r.parent, guard = 0;
    while (p && byId[p] && guard++ < 50) { d++; p = byId[p].parent; }
    return (depth[r.id] = d);
  }
  var current = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[<>&"']/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function text(r) { return [r.title, r.ref, r.level].concat(r.fields.map(function (f) { return f[1]; })).join(' ').toLowerCase(); }

  function render(filter) {
    var f = (filter || '').toLowerCase().trim();
    var shown = C.records.filter(function (r) { return !f || text(r).indexOf(f) !== -1; });
    listEl.innerHTML = shown.map(function (r) {
      return '<li><button type="button" data-id="' + esc(r.id) + '" style="padding-left:' + (0.8 + (f ? 0 : level(r)) * 1) + 'rem"' + (current === r.id ? ' aria-current="true"' : '') + '>'
        + esc(r.title) + (r.ref ? '<span class="ident">' + esc(r.ref) + '</span>' : '') + '</button></li>';
    }).join('') || '<li class="empty">Nothing matches.</li>';
  }
  qEl.addEventListener('input', function () { render(this.value); });
  listEl.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-id]');
    if (b) { current = b.getAttribute('data-id'); render(qEl.value); show(current); }
  });
  detailEl.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-record], a[data-agent]');
    if (!a) return;
    e.preventDefault();
    if (a.hasAttribute('data-record')) { current = a.getAttribute('data-record'); render(qEl.value); show(current); }
    else showAgent(a.getAttribute('data-agent'));
  });

  function dl(rows) {
    return rows.length ? '<dl>' + rows.map(function (p) { return '<dt>' + esc(p[0]) + '</dt><dd' + (p[2] ? ' lang="' + esc(p[2]) + '"' : '') + '>' + p[1] + '</dd>'; }).join('') + '</dl>' : '';
  }

  function show(id) {
    var r = byId[id];
    if (!r) { detailEl.innerHTML = '<p class="empty">Not found.</p>'; return; }
    var images = r.files.filter(function (f) { return f.use === 'reference' && /^image\//.test(f.mime || ''); });
    if (!images.length) images = r.files.filter(function (f) { return f.use === 'thumbnail'; });
    var others = r.files.filter(function (f) { return images.indexOf(f) === -1 && f.use !== 'thumbnail'; });
    var rows = [];
    if (r.ref) rows.push(['Reference code', esc(r.ref)]);
    if (r.level) rows.push(['Level of description', esc(r.level)]);
    r.dates.forEach(function (d) { rows.push(['Date', esc(d)]); });
    r.creators.forEach(function (c) { rows.push(['Creator', agents[c.id] ? '<a href="#" data-agent="' + esc(c.id) + '">' + esc(c.name) + '</a>' : esc(c.name)]); });
    if (repositories[r.repository]) rows.push(['Repository', esc(repositories[r.repository].name)]);
    if (r.parent && byId[r.parent]) rows.push(['Part of', '<a href="#" data-record="' + esc(r.parent) + '">' + esc(byId[r.parent].title) + '</a>']);
    r.fields.forEach(function (f) { rows.push([f[0], esc(f[1]), f[2]]); });
    var children = C.records.filter(function (c) { return c.parent === r.id; });
    if (children.length) rows.push(['Contains', children.map(function (c) { return '<a href="#" data-record="' + esc(c.id) + '">' + esc(c.title) + '</a>'; }).join('<br>')]);
    detailEl.innerHTML = '<h2>' + esc(r.title) + '</h2>'
      + (images.length ? '<div class="files">' + images.map(function (f) { return '<figure><a href="' + esc(f.path) + '"><img src="' + esc(f.path) + '" alt="' + esc(f.name) + '"></a><figcaption>' + esc(f.name) + '</figcaption></figure>'; }).join('') + '</div>' : '')
      + (others.length ? '<p>Files: ' + others.map(function (f) { return '<a href="' + esc(f.path) + '">' + esc(f.name) + '</a> (' + esc(f.use) + ', ' + Math.round((f.size || 0) / 1024) + ' KB)'; }).join(', ') + '</p>' : '')
      + dl(rows) + '<p class="ident">' + esc(r.iri) + '</p>'
      + (EDITABLE ? capturePanel(r) : '');
    if (EDITABLE) wireCapture(r);
    detailEl.focus();
  }

  function showAgent(id) {
    var a = agents[id];
    if (!a) return;
    var made = C.records.filter(function (r) { return r.creators.some(function (c) { return c.id === id; }); });
    var rows = a.fields.map(function (f) { return [f[0], esc(f[1]), f[2]]; });
    if (made.length) rows.push(['Created', made.map(function (r) { return '<a href="#" data-record="' + esc(r.id) + '">' + esc(r.title) + '</a>'; }).join('<br>')]);
    detailEl.innerHTML = '<h2>' + esc(a.name) + '</h2>' + dl(rows) + '<p class="ident">' + esc(a.iri) + '</p>';
  }

  // What the reader adds, in an editable package (Heratio's offline capture).
  var PFX = 'openric-offline:' + C.sync.package_id + ':';
  function key(kind, id) { return PFX + kind + ':' + id; }
  function getArr(k) { try { return JSON.parse(localStorage.getItem(k) || '[]'); } catch (e) { return []; } }
  function setArr(k, v) { try { v.length ? localStorage.setItem(k, JSON.stringify(v)) : localStorage.removeItem(k); } catch (e) { alert('Your browser could not keep that: it may be full. Save for return, then clear.'); } updateCount(); }

  function entries(arr, label, kind) {
    return arr.length ? arr.map(function (e, i) { return '<div class="entry"><span>' + label(e) + '</span><button type="button" data-kind="' + kind + '" data-i="' + i + '" aria-label="Remove">&times;</button></div>'; }).join('') : '<p>None yet.</p>';
  }
  function capturePanel(r) {
    var note = localStorage.getItem(key('note', r.id)) || '';
    var srcs = getArr(key('src', r.id)), sugs = getArr(key('sug', r.id)), files = getArr(key('file', r.id));
    var tab = function (t, label, on) { return '<button role="tab" type="button" data-t="' + t + '" aria-selected="' + on + '">' + label + '</button>'; };
    return '<div class="cap"><h3>Your work on this record</h3><div role="tablist">'
      + tab('notes', 'Notes', true) + tab('src', 'Sources (' + srcs.length + ')', false) + tab('sug', 'Suggestions (' + sugs.length + ')', false) + tab('file', 'Files (' + files.length + ')', false) + '</div>'
      + '<div role="tabpanel" data-p="notes"><label for="c-note">Your research notes on this record</label><textarea id="c-note">' + esc(note) + '</textarea><button class="btn" id="c-note-save" type="button">Save note</button> <span id="c-note-flag" role="status"></span></div>'
      + '<div role="tabpanel" data-p="src" hidden><label for="c-src-title">Title</label><input id="c-src-title"><label for="c-src-author">Author</label><input id="c-src-author">'
      + '<label for="c-src-year">Year</label><input id="c-src-year"><label for="c-src-url">URL or shelf mark</label><input id="c-src-url"><button class="btn" id="c-src-add" type="button">Add source</button>'
      + entries(srcs, function (s) { return esc(s.title + (s.author ? ', ' + s.author : '') + (s.year ? ' (' + s.year + ')' : '')); }, 'src') + '</div>'
      + '<div role="tabpanel" data-p="sug" hidden><label for="c-sug-field">Field (for example Title, Dates, Scope and content)</label><input id="c-sug-field"><label for="c-sug-text">Your suggested correction or addition</label><textarea id="c-sug-text"></textarea>'
      + '<button class="btn" id="c-sug-add" type="button">Add suggestion</button>' + entries(sugs, function (s) { return esc(s.field + ': ' + s.text); }, 'sug') + '</div>'
      + '<div role="tabpanel" data-p="file" hidden><label for="c-file">Files you gathered (keep them small: they are kept in this browser)</label><input type="file" id="c-file" multiple>'
      + entries(files, function (f) { return esc(f.name + ' (' + Math.round((f.size || 0) / 1024) + ' KB)'); }, 'file') + '</div></div>';
  }
  function wireCapture(r) {
    var q = function (s) { return detailEl.querySelector(s); };
    detailEl.querySelectorAll('[role=tab]').forEach(function (b) {
      b.addEventListener('click', function () {
        detailEl.querySelectorAll('[role=tab]').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
        detailEl.querySelectorAll('[role=tabpanel]').forEach(function (p) { p.hidden = p.getAttribute('data-p') !== b.getAttribute('data-t'); });
      });
    });
    q('#c-note-save').addEventListener('click', function () {
      var v = q('#c-note').value;
      try { v.trim() ? localStorage.setItem(key('note', r.id), v) : localStorage.removeItem(key('note', r.id)); } catch (e) { alert('Your browser could not keep that.'); }
      updateCount(); q('#c-note-flag').textContent = 'Saved';
    });
    q('#c-src-add').addEventListener('click', function () {
      var t = q('#c-src-title').value.trim();
      if (!t) return;
      var arr = getArr(key('src', r.id));
      arr.push({ title: t, author: q('#c-src-author').value.trim(), year: q('#c-src-year').value.trim(), url: q('#c-src-url').value.trim() });
      setArr(key('src', r.id), arr); show(r.id);
    });
    q('#c-sug-add').addEventListener('click', function () {
      var field = q('#c-sug-field').value.trim(), t = q('#c-sug-text').value.trim();
      if (!field || !t) return;
      var arr = getArr(key('sug', r.id)); arr.push({ field: field, text: t }); setArr(key('sug', r.id), arr); show(r.id);
    });
    q('#c-file').addEventListener('change', function () {
      var list = this.files; if (!list || !list.length) return;
      var arr = getArr(key('file', r.id)), pending = list.length;
      Array.prototype.forEach.call(list, function (file) {
        var reader = new FileReader();
        reader.onload = function () { arr.push({ name: file.name, type: file.type, size: file.size, data: reader.result }); if (--pending === 0) { setArr(key('file', r.id), arr); show(r.id); } };
        reader.readAsDataURL(file);
      });
    });
    detailEl.querySelectorAll('.entry button').forEach(function (x) {
      x.addEventListener('click', function () {
        var k = key(x.getAttribute('data-kind'), r.id), arr = getArr(k);
        arr.splice(parseInt(x.getAttribute('data-i'), 10), 1); setArr(k, arr); show(r.id);
      });
    });
  }

  // PSIS's researcher exchange file, format 1.0: a note names its record by its persistent IRI.
  function exchange() {
    var notes = [], files = [];
    C.records.forEach(function (r) {
      var title = r.title + (r.ref ? ' (' + r.ref + ')' : '');
      var n = localStorage.getItem(key('note', r.id));
      if (n && n.trim()) notes.push({ title: 'Note on ' + title, note: n, reference_iri: r.iri });
      getArr(key('src', r.id)).forEach(function (s) {
        notes.push({ title: 'Source for ' + title, note: [s.title, s.author, s.year, s.url].filter(Boolean).join('; '), reference_iri: r.iri });
      });
      getArr(key('sug', r.id)).forEach(function (s) {
        notes.push({ title: 'Suggested ' + s.field + ' for ' + title, note: s.text, reference_iri: r.iri });
      });
      getArr(key('file', r.id)).forEach(function (f) {
        files.push({ title: f.name, notes: 'Gathered offline for ' + title + ', ' + r.iri, files: [{ filename: f.name, data: f.data, caption: 'Gathered offline for ' + title }] });
      });
    });
    var collections = [];
    if (notes.length) collections.push({ type: 'notes', items: notes });
    if (files.length) collections.push({ type: 'files', items: files });
    return { format_version: '1.0', source_config: { title: C.sync.title, package_id: C.sync.package_id, institution: C.sync.institution },
      export_options: { include_images: true }, generated_at: new Date().toISOString(), collections: collections };
  }
  function count() { return exchange().collections.reduce(function (n, c) { return n + c.items.length; }, 0); }
  function updateCount() { var el = document.getElementById('synccount'); if (el) { var n = count(); el.textContent = n + (n === 1 ? ' change' : ' changes'); } }

  if (EDITABLE) {
    document.getElementById('syncbar').hidden = false;
    updateCount();
    document.getElementById('syncsave').addEventListener('click', function () {
      if (count() === 0) { alert('You have added nothing yet.'); return; }
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([JSON.stringify(exchange(), null, 2)], { type: 'application/json' }));
      a.download = 'researcher-exchange.json';
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
    });
    document.getElementById('syncreset').addEventListener('click', function () {
      if (!confirm('Remove everything you added in this package? It cannot be undone.')) return;
      var keys = [];
      for (var i = 0; i < localStorage.length; i++) { var k = localStorage.key(i); if (k.indexOf(PFX) === 0) keys.push(k); }
      keys.forEach(function (k) { localStorage.removeItem(k); });
      updateCount(); if (current) show(current);
    });
  }
  detailEl.setAttribute('tabindex', '-1');
  render('');
})();
