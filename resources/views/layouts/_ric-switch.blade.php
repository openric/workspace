{{-- OpenRiC Workspace - the switch that shows or hides the RiC-O term beside each field (phase 26). Off by default, so a page reads as an archive's; remembered in this browser. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
<label class="ric-switch" style="display: inline-flex; align-items: center; gap: .4rem; margin: 0; font-weight: 500; font-size: .9rem; cursor: pointer">
    <input type="checkbox" data-ric-switch style="width: auto; margin: 0"> {{ __('Show RiC terms') }}
</label>
<script>
    (function () {
        var box = document.currentScript.previousElementSibling.querySelector('[data-ric-switch]');
        box.checked = document.documentElement.classList.contains('show-ric');
        box.addEventListener('change', function () {
            document.documentElement.classList.toggle('show-ric', box.checked);
            try { localStorage.setItem('openric.showRic', box.checked ? '1' : '0'); } catch (e) {}
        });
    })();
</script>
