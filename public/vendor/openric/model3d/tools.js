// OpenRiC Workspace - a 3D model's toolbars (roadmap phase 21), after Heratio's ahg-3d-model _model3d-viewer.blade.php
// (#666): an animation player shown when the model has animations; saved views, published ones and a signed-in reader's
// own, from the model's bookmarks address; a link to the exact view in the address's hash; and model-viewer's
// auto-rotation and autoplay left off for a reader who prefers reduced motion.
// Changed from Heratio: the texts come from the page, in the reader's language (data-text); saved views are a plain list
// rather than a Bootstrap dropdown; the CSRF token is the page's meta tag or the form's hidden field.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
(function () {
  'use strict';
  if (window.__openric3dTools) { return; }
  window.__openric3dTools = true;
  var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function text(box, key) { try { return JSON.parse(box.getAttribute('data-text') || '{}')[key] || key; } catch (e) { return key; } }
  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    var i = document.querySelector('input[name="_token"]');
    return m ? m.getAttribute('content') : (i ? i.value : '');
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[<>&"']/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmt(t) { t = Math.max(0, t || 0); var m = Math.floor(t / 60), s = Math.floor(t % 60); return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s; }
  function state(viewer) {
    return {
      orbit: viewer.getCameraOrbit ? viewer.getCameraOrbit().toString() : '',
      target: viewer.getCameraTarget ? viewer.getCameraTarget().toString() : '',
      fov: viewer.getFieldOfView ? viewer.getFieldOfView() + 'deg' : ''
    };
  }
  function apply(viewer, s) {
    if (!s) return;
    var decay = null;
    if (REDUCED) { try { decay = viewer.interpolationDecay; viewer.interpolationDecay = 0; } catch (e) {} }
    if (s.orbit) viewer.cameraOrbit = s.orbit;
    if (s.target) viewer.cameraTarget = s.target;
    if (s.fov) viewer.fieldOfView = s.fov;
    if (s.anim) viewer.animationName = s.anim;
    if (decay !== null) window.requestAnimationFrame(function () { try { viewer.interpolationDecay = decay; } catch (e) {} });
  }

  function animation(box, viewer) {
    var bar = box.querySelector('.model3d-anim');
    if (!bar) return;
    var play = bar.querySelector('.model3d-anim-play'), name = bar.querySelector('.model3d-anim-name'), scrub = bar.querySelector('.model3d-anim-scrub'), time = bar.querySelector('.model3d-anim-time');
    var held = false;
    viewer.addEventListener('load', function () {
      var list = viewer.availableAnimations || [];
      if (!list.length) return;
      bar.hidden = false;
      name.innerHTML = list.map(function (n) { return '<option>' + esc(n) + '</option>'; }).join('');
      name.hidden = list.length < 2;
      if (REDUCED) viewer.pause();
    });
    play.addEventListener('click', function () { viewer.paused ? viewer.play() : viewer.pause(); });
    viewer.addEventListener('play', function () { play.textContent = text(box, 'Pause'); });
    viewer.addEventListener('pause', function () { play.textContent = text(box, 'Play'); });
    name.addEventListener('change', function () { viewer.animationName = name.value; viewer.currentTime = 0; });
    scrub.addEventListener('input', function () { held = true; });
    scrub.addEventListener('change', function () { viewer.currentTime = (scrub.value / 1000) * (viewer.duration || 0); held = false; });
    (function tick() {
      if (!held && viewer.duration) { scrub.value = Math.round((viewer.currentTime / viewer.duration) * 1000); time.textContent = fmt(viewer.currentTime) + ' / ' + fmt(viewer.duration); }
      window.requestAnimationFrame(tick);
    })();
  }

  function bookmarks(box, viewer) {
    var url = box.getAttribute('data-bookmarks');
    var list = box.querySelector('.model3d-views'), save = box.querySelector('.model3d-save');
    if (!url || !list) return;
    function load() {
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.ok ? r.json() : { bookmarks: [] }; }).then(function (data) {
        var rows = data.bookmarks || [];
        list.innerHTML = rows.length ? rows.map(function (b) {
          return '<li><button type="button" class="model3d-go" data-i="' + esc(b.id) + '">' + esc(b.name) + '</button>' + (b.is_public ? ' <small>(' + esc(text(box, 'for everyone')) + ')</small>' : '')
            + (b.mine ? ' <button type="button" class="model3d-del" data-i="' + esc(b.id) + '" aria-label="' + esc(text(box, 'Delete')) + '">&times;</button>' : '') + '</li>';
        }).join('') : '<li>' + esc(text(box, 'No saved views yet')) + '</li>';
        list.querySelectorAll('.model3d-go').forEach(function (b) {
          b.addEventListener('click', function () {
            var hit = rows.find(function (x) { return String(x.id) === b.getAttribute('data-i'); });
            if (hit) apply(viewer, { orbit: hit.camera_orbit, target: hit.camera_target, fov: hit.field_of_view });
          });
        });
        list.querySelectorAll('.model3d-del').forEach(function (b) {
          b.addEventListener('click', function () {
            if (!window.confirm(text(box, 'Delete this saved view?'))) return;
            fetch(url + '/' + b.getAttribute('data-i') + '/delete', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' } }).then(load);
          });
        });
      });
    }
    if (save) {
      save.addEventListener('click', function () {
        var name = window.prompt(text(box, 'Name this view'));
        if (!name) return;
        var s = state(viewer);
        var pub = box.querySelector('.model3d-public');
        fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
          body: JSON.stringify({ name: name, camera_orbit: s.orbit, camera_target: s.target, field_of_view: s.fov, is_public: !!(pub && pub.checked) }) }).then(load);
      });
    }
    load();
  }

  function share(box, viewer) {
    var id = box.getAttribute('data-model'), btn = box.querySelector('.model3d-share');
    var key = '3d-' + id;
    var hash = (window.location.hash || '').replace(/^#/, '').split('&').map(function (p) { return p.split('='); }).find(function (kv) { return kv[0] === key; });
    if (hash && hash[1]) {
      try {
        var s = JSON.parse(decodeURIComponent(escape(atob(hash[1]))));
        viewer.loaded ? apply(viewer, s) : viewer.addEventListener('load', function () { apply(viewer, s); }, { once: true });
      } catch (e) {}
    }
    if (!btn) return;
    btn.addEventListener('click', function () {
      var s = state(viewer);
      if (viewer.animationName) s.anim = viewer.animationName;
      var link = window.location.origin + window.location.pathname + window.location.search + '#' + key + '=' + btoa(unescape(encodeURIComponent(JSON.stringify(s))));
      try { history.replaceState(null, '', link); } catch (e) {}
      var done = function () { btn.textContent = text(box, 'Link copied'); setTimeout(function () { btn.textContent = text(box, 'Link to this view'); }, 1800); };
      navigator.clipboard && navigator.clipboard.writeText ? navigator.clipboard.writeText(link).then(done, function () { window.prompt(text(box, 'Copy this link'), link); })
        : window.prompt(text(box, 'Copy this link'), link);
    });
  }

  function init() {
    document.querySelectorAll('.model3d[data-model]').forEach(function (box) {
      var viewer = box.querySelector('model-viewer');
      if (!viewer) return;
      if (REDUCED) { viewer.removeAttribute('auto-rotate'); viewer.removeAttribute('autoplay'); }
      animation(box, viewer);
      bookmarks(box, viewer);
      share(box, viewer);
    });
  }
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init) : init();
})();
