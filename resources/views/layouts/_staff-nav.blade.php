{{-- OpenRiC Workspace - the staff menus, for a signed-in account, on the Workspace's pages and the public catalogue alike. Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems. SPDX-License-Identifier: AGPL-3.0-or-later --}}
@auth
    @php
        $user = auth()->user();
        $may = fn (string $permission) => $user->can(\App\Support\Vocab::permission($permission));
        $mayImport = collect(array_keys(\App\Exchange\Import\ImportService::FORMATS))
            ->contains(fn ($format) => $user->mayAnywhere('create', \App\Exchange\Import\ImportService::permission($format)));
        // group => [route name => [label, shown, the routes it is current for, "!" before one it is not]]; a group with nothing shown is left out.
        // As AtoM: what is described, then the working tools (its Manage), import and export (its Import), reports (Heratio's), then administration (its Admin);
        // the account's own pages (profile, jobs, access requests) are under its name in the header.
        $nav = array_filter(array_map(fn ($items) => array_filter($items, fn ($item) => $item[1]), [
            'Describe' => [
                'records.index' => [__('Archival descriptions'), $user->mayAnywhere('read', 'description'), 'records.*|!records.export-all'],
                'agents.index' => [__('Authority records'), $user->mayAnywhere('read', 'authority'), 'agents.*|!agents.duplicates'],
                'activities.index' => [__('Activities'), $user->mayAnywhere('read', 'authority'), 'activities.*'],
                'rules.index' => [__('Rules and mandates'), $user->mayAnywhere('read', 'authority'), 'rules.*'],
                'places.index' => [__('Places'), true, 'places.*'],
                'repositories.index' => [__('Repositories'), $user->mayAnywhere('read', 'repository'), 'repositories.*'],
            ],
            'Manage' => [
                'accessions.index' => [__('Accessions'), $user->mayAnywhere('read', 'accession'), 'accessions.*'],
                'agreements.index' => [__('Donor agreements'), $user->mayAnywhere('read', 'agreement'), 'agreements.*'],
                'vendors.index' => [__('Vendors'), $user->mayAnywhere('read', 'vendor'), 'vendors.*'],
                'vocabularies.index' => [__('Taxonomies'), true, 'vocabularies.*'],
                'containers.index' => [__('Containers'), $user->mayAnywhere('read', 'storage'), 'containers.*'],
                'storage.places' => [__('Physical storage'), $user->mayAnywhere('read', 'storage'), 'storage.*'],
                'rights.index' => [__('Rights'), $user->mayAnywhere('read', 'description'), 'rights.*'],
                'proposals.index' => [__('Proposals to review'), $user->mayAnywhere('review-proposals', 'description'), 'proposals.*'],
                'translation.strings' => [__('Interface wording'), $user->mayAnywhere('translate', 'description'), 'translation.*'],
                'authority.queue' => [__('Names to resolve'), $user->mayAnywhere('review-proposals', 'description'), 'authority.queue|authority.review|authority.parked|authority.create-form'],
                'agents.duplicates' => [__('Duplicate authority records'), $user->may('update', 'authority'), 'agents.duplicates'],
                'alt-text.index' => [__('Alternative text'), $user->mayAnywhere('update', 'description'), 'alt-text.*'],
            ],
            'Workflow' => [
                'workflow.dashboard' => [__('Dashboard'), true, 'workflow.dashboard'],
                'workflow.mine' => [__('My tasks'), true, 'workflow.mine|workflow.tasks.*'],
                'workflow.pool' => [__('Pool'), true, 'workflow.pool'],
                'workflow.team' => [__('Team work'), true, 'workflow.team'],
                'workflow.queues' => [__('Queues'), true, 'workflow.queues'],
                'workflow.overdue' => [__('Overdue'), true, 'workflow.overdue'],
                'workflow.history' => [__('History'), true, 'workflow.history'],
                'workflow.admin' => [__('Workflows'), $may('manage-workflows'), 'workflow.admin*'],
                'workflow.gates' => [__('Publish gates'), $may('manage-workflows'), 'workflow.gates'],
            ],
            'Import and export' => [
                'imports.index' => [__('Import'), $mayImport, 'imports.*'],
                'records.export-all' => [__('Export descriptions'), $user->mayAnywhere('read', 'description'), 'records.export-all'],
                'offline-catalogues.index' => [__('Offline catalogues'), $user->hasPermission(\App\Support\Vocab::permission('make-offline-catalogues')), 'offline-catalogues.*'],
            ],
            'Reports' => [
                'reports.index' => [__('Overview'), $user->mayAnywhere('read', 'description'), 'reports.index'],
                'reports.quality' => [__('Data quality'), $user->mayAnywhere('read', 'description'), 'reports.quality'],
                'reports.usage' => [__('Usage statistics'), $user->mayAnywhere('read', 'description'), 'reports.usage'],
                'reports.builder.index' => [__('Report builder'), $user->mayAnywhere('read', 'description'), 'reports.builder.*'],
                'iiif-collections.index' => [__('IIIF collections'), $user->mayAnywhere('publish', 'description'), 'iiif-collections.*'],
                'iiif-validation.index' => [__('IIIF validation'), $user->mayAnywhere('publish', 'description'), 'iiif-validation.*'],
                'embargoes.index' => [__('Embargoes'), $user->mayAnywhere('update', 'description'), 'embargoes.*'],
                'retention.index' => [__('Records management'), $user->mayAnywhere('delete', 'description'), 'retention.*|disposals.*|records-management.*'],
                'duplicates.index' => [__('Duplicate descriptions'), $user->mayAnywhere('delete', 'description'), 'duplicates.*'],
                'jurisdiction.narssa' => [__('Transfers to the National Archives'), $user->institution->usesPack('narssa') && $user->mayAnywhere('delete', 'description'), 'jurisdiction.narssa*'],
                'jurisdiction.permits' => [__('Export permits'), $user->institution->usesPack('export-permits') && $user->mayAnywhere('delete', 'description'), 'jurisdiction.permit*'],
                'jurisdiction.naz' => [__('National Archives of Zimbabwe'), $user->institution->usesPack('naz') && $user->mayAnywhere('delete', 'description'), 'jurisdiction.naz*'],
                'privacy.index' => [__('Privacy'), $user->hasPermission(\App\Support\Vocab::permission('manage-privacy')), 'privacy.*'],
                'iiif-workspaces.index' => [__('My viewer layouts'), true, 'iiif-workspaces.*'],
                ...collect(\App\Http\Controllers\ReportController::TITLES)->mapWithKeys(fn ($title, $list) => ['reports.'.$list => [__($title),
                    \App\Reports\ReportService::mayRead($user, $list), 'reports.'.$list]])->all(),
            ],
            // Research services (roadmap phase 19).
            'Research' => [
                'reading-room.desk' => [__('Reading room desk'), $may('manage-reading-room'), 'reading-room.desk|research.bookings.*'],
                'reading-room.researchers' => [__('Researchers'), $may('manage-reading-room'), 'reading-room.researchers*'],
                'reading-room.queues' => [__('Retrieval queues'), $may('manage-reading-room'), 'reading-room.queues*|reading-room.slips'],
                'research.reproductions.index' => [__('Reproductions'), $may('manage-reading-room'), 'research.reproductions.*'],
                'reading-room.rooms' => [__('Reading rooms'), $may('manage-reading-room'), 'reading-room.rooms*'],
                'reading-room.types' => [__('Kinds of researcher'), $may('manage-reading-room'), 'reading-room.types*'],
                'research.submissions.pending' => [__('Submissions to review'), $may('review-submissions'), 'research.submissions.pending'],
                'research.analytics' => [__('Research analytics'), $may('manage-reading-room'), 'research.analytics'],
                'research.services.settings' => [__('ORCID and DataCite'), $may('manage-reading-room'), 'research.services.settings*'],
                'clearance.index' => [__('Security clearance'), $may('manage-institution'), 'clearance.*'],
                'research.quotas' => [__('Research quotas'), $may('manage-reading-room'), 'research.quotas*'],
                'research.print-templates' => [__('Print templates'), $may('manage-reading-room'), 'research.print-templates*'],
                'feedback.index' => [__('Feedback received'), $user->mayAnywhere('update', 'description'), 'feedback.*'],
                'share-links.index' => [__('Share links'), $user->mayAnywhere('update', 'description'), 'share-links.*'],
                'repatriation.index' => [__('Repatriation claims'), $may('manage-repatriation'), 'repatriation.*'],
                'heritage.displaced' => [__('Displaced heritage'), $may('manage-repatriation'), 'heritage.displaced'],
                'scholarship.index' => [__('Discoveries'), $user->mayAnywhere('publish', 'description'), 'scholarship.index|scholarship.show'],
                'scholarship.leads' => [__('Research leads'), $user->mayAnywhere('publish', 'description'), 'scholarship.leads'],
                'languages.review' => [__('Language contributions to review'), $user->mayAnywhere('update', 'description'), 'languages.review'],
                'heritage.at-risk' => [__('Heritage at risk'), $user->mayAnywhere('update', 'description'), 'heritage.at-risk|heritage.flag|heritage.inbound'],
                'shop.orders' => [__('Orders'), $may('manage-shop'), 'shop.orders*'],
                'shop.settings' => [__('Shop settings'), $may('manage-shop'), 'shop.settings*'],
            ],
            // The institution's own pages on its public site, and each user's dashboards (roadmap phase 20a).
            'Public site' => [
                'site.pages' => [__('Static pages'), $user->mayAnywhere('publish', 'description'), 'site.pages*'],
                'site.articles' => [__('Articles'), $user->mayAnywhere('publish', 'description'), 'site.articles*|site.attachments.*|site.links.*'],
                'site.comments' => [__('Article comments'), $user->mayAnywhere('publish', 'description'), 'site.comments*'],
                'site.landing' => [__('Landing pages'), $user->mayAnywhere('publish', 'description'), 'site.landing*|landing.*'],
                'dois.index' => [__('DOIs'), $user->mayAnywhere('publish', 'description'), 'dois.*'],
                'dashboards' => [__('My dashboards'), true, 'dashboards*'],
            ],
            'Admin' => [
                'accounts.index' => [__('Users'), $may('manage-accounts'), 'accounts.*'],
                'email.index' => [__('Email'), $may('manage-accounts'), 'email.*'],
                'sign-in-settings.index' => [__('Sign-in settings'), $may('manage-accounts'), 'sign-in-settings.*'],
                'roles.index' => [__('Roles'), $may('manage-roles'), 'roles.*'],
                'audit' => [__('Audit log'), $may('view-audit-log'), 'audit'],
                'search.statistics' => [__('Search statistics'), $may('view-audit-log'), 'search.statistics'],
                'intake.index' => [__('Intake'), $may('manage-institution'), 'intake.*'],
                'operations.index' => [__('Operations'), $may('manage-institution'), 'operations.*|fixity.*'],
                'formats.index' => [__('File formats'), $may('manage-institution'), 'formats.*'],
                'models.defaults' => [__('3D models'), $may('manage-institution'), 'models.defaults*'],
                'preservation.index' => [__('Preservation'), $may('manage-institution'), 'preservation.*'],
                'archivematica.index' => [__('Archivematica'), $may('manage-institution'), 'archivematica.*'],
                'federation.admin' => [__('Federation'), $may('manage-institution'), 'federation.*'],
                'webhooks.index' => [__('Webhooks'), $may('manage-institution'), 'webhooks.*'],
                'labels.templates' => [__('Label templates'), $may('manage-institution'), 'labels.templates*'],
                'functions-docs.index' => [__('Code reference'), $may('manage-institution'), 'functions-docs.*'],
                'authority.settings' => [__('Resolving names'), $may('manage-institution'), 'authority.settings*'],
                'ai.index' => [__('AI'), $may('manage-institution'), 'ai.*'],
                'display.index' => [__('Display'), $may('manage-institution'), 'display.*'],
                'dropdowns.index' => [__('Dropdown manager'), $may('manage-institution'), 'dropdowns.*'],
                'custom-fields.index' => [__('Own fields and form templates'), $may('manage-institution'), 'custom-fields.index|custom-fields.template*|custom-fields.definitions*'],
                'settings.edit' => [__('Settings'), $may('manage-institution'), 'settings.*'],
            ],
        ]));
        // An icon beside each main item, drawn here (24 by 24, stroked); the word stays, so the icon is hidden from screen readers.
        $icons = [
            'Home' => '<path d="M3 11l9-8 9 8"/><path d="M5 9.5V21h5v-6h4v6h5V9.5"/>',
            'Describe' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 13h7M9 17h5"/>',
            'Manage' => '<path d="M3 4h18v5H3z"/><path d="M5 9v11h14V9"/><path d="M10 13h4"/>',
            'Workflow' => '<path d="M4 6h6v4H4zM14 14h6v4h-6z"/><path d="M7 10v4a2 2 0 0 0 2 2h5"/>',
            'Import and export' => '<path d="M7 4v13M3 13l4 4 4-4"/><path d="M17 20V7M13 11l4-4 4 4"/>',
            'Reports' => '<path d="M4 20V10M10 20V4M16 20v-7M2 20h20"/>',
            'Research' => '<path d="M2 5c3-1.5 7-1.5 10 1 3-2.5 7-2.5 10-1v14c-3-1.5-7-1.5-10 1-3-2.5-7-2.5-10-1z"/><path d="M12 6v14"/>',
            'Public site' => '<path d="M3 5h18v14H3z"/><path d="M3 9h18M8 13h8M8 16h5"/>',
            'Help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14"/><path d="M12 17.5v.5"/>',
            'Admin' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
            'Public catalogue' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3.5 3.5 3.5 14.5 0 18M12 3c-3.5 3.5-3.5 14.5 0 18"/>',
        ];
        $icon = fn (string $item) => new \Illuminate\Support\HtmlString('<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'.$icons[$item].'</svg>');
        $current = function (array $item): bool {
            $patterns = collect(explode('|', $item[2]));
            $not = $patterns->filter(fn ($p) => str_starts_with($p, '!'))->map(fn ($p) => substr($p, 1));

            return $patterns->reject(fn ($p) => str_starts_with($p, '!'))->contains(fn ($p) => request()->routeIs($p))
                && ! $not->contains(fn ($p) => request()->routeIs($p));
        };
    @endphp
    <nav aria-label="Main" class="main-nav">
        <ul>
            <li><a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>{{ $icon('Home') }}{{ __('Home') }}</a></li>
            @foreach($nav as $group => $items)
                @php($open = collect($items)->contains($current))
                <li><details name="main-nav">
                    <summary @class(['here' => $open])>{{ $icon($group) }}{{ __($group) }}</summary>
                    <ul>
                        @foreach($items as $name => $item)
                            <li><a href="{{ route($name) }}" @if($current($item)) aria-current="page" @endif>{{ $item[0] }}</a></li>
                        @endforeach
                    </ul>
                </details></li>
            @endforeach
            <li><a href="{{ route('discover.home') }}">{{ $icon('Public catalogue') }}{{ __('Public catalogue') }}</a></li>
            {{-- Help for the screen in hand, in a new tab (Heratio's contextual help; phase 20b). --}}
            <li><a href="{{ route('help.context', ['route' => request()->route()?->getName()]) }}" target="_blank" rel="noopener" aria-label="{{ __('Help for this page (opens in a new tab)') }}">{{ $icon('Help') }}{{ __('Help') }}</a></li>
        </ul>
    </nav>
    <script>
        // Close an open menu on Escape or a click outside it; the menus work without this.
        document.addEventListener('click', e => document.querySelectorAll('.main-nav details[open]').forEach(d => d.contains(e.target) || d.removeAttribute('open')));
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            const d = document.querySelector('.main-nav details[open]');
            if (d) { d.removeAttribute('open'); d.querySelector('summary').focus(); }
        });
    </script>
@endauth
