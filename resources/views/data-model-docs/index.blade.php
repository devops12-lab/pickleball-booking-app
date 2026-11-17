@php
    $meta = $documentation['meta'];
    $domains = $documentation['domains'];
    $logo = asset($meta['logo']);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="{{ $meta['subtitle'] }}">

        <title>{{ $meta['title'] }}</title>

        <link rel="icon" type="image/png" href="{{ $logo }}">
        <link rel="apple-touch-icon" href="{{ $logo }}">

        <script>
            (() => {
                const stored = localStorage.getItem('api-docs-theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.dataset.theme = stored ?? (prefersDark ? 'dark' : 'light');
            })();
        </script>

        @fonts('inter')
        @vite(['resources/css/api-docs.css', 'resources/css/data-model-docs.css', 'resources/js/data-model-docs.js'])
    </head>
    <body>
        <a class="skip-link" href="#main">Skip to content</a>

        <header class="topbar">
            <button type="button" class="icon-button menu-button" data-drawer-open aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
            </button>

            <a class="brand" href="#/overview">
                <img src="{{ $logo }}" alt="CourtFlex" class="brand-logo">
                <span class="brand-title">Data Model</span>
            </a>

            <x-docs.portal-nav current="data-model" />

            <div class="topbar-actions">
                <button type="button" class="search-trigger" data-palette-open aria-haspopup="dialog" aria-label="Search tables">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <span class="search-trigger-label">Search tables</span>
                    <kbd data-shortcut-label>Ctrl K</kbd>
                </button>

                <button type="button" class="icon-button" data-theme-toggle aria-label="Switch to dark theme">
                    <svg class="icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" /></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></svg>
                </button>
            </div>
        </header>

        <div class="layout">
            <aside class="sidebar" id="sidebar" aria-label="Schema navigation">
                <div class="sidebar-head">
                    <span class="label">Navigation</span>
                    <button type="button" class="icon-button" data-drawer-close aria-label="Close navigation">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
                    </button>
                </div>

                <x-docs.portal-nav current="data-model" placement="sidebar" />

                <label class="search">
                    <span class="sr-only">Filter tables</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <input type="search" placeholder="Filter tables" autocomplete="off" data-search>
                </label>

                <div class="sidebar-tools">
                    <span class="caption" data-nav-summary>{{ $meta['table_count'] }} tables</span>
                    <button type="button" class="link-button" data-expand-all>Expand all</button>
                </div>

                <nav class="nav" data-nav>
                    <div class="nav-group">
                        <a class="nav-link" href="#/overview" data-nav="overview">Overview</a>
                    </div>

                    @foreach ($domains as $domain)
                        <div class="nav-module" data-nav-domain="{{ $domain['slug'] }}">
                            <button type="button" class="nav-module-toggle" aria-expanded="false" data-toggle="{{ $domain['slug'] }}">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
                                {{ $domain['name'] }}
                                <span class="badge tabular">{{ count($domain['tables']) }}</span>
                            </button>
                            <div class="nav-items" hidden>
                                <a class="nav-link" href="#/domain/{{ $domain['slug'] }}" data-nav="domain-{{ $domain['slug'] }}">All tables</a>
                                @foreach ($domain['tables'] as $table)
                                    <a class="nav-link nav-table-link" href="#/table/{{ $table['slug'] }}" data-nav="table-{{ $table['slug'] }}" data-table="{{ $table['name'] }}">
                                        {{ $table['name'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </nav>

                <div class="empty-state" data-nav-empty hidden>
                    <p>No tables match your filter.</p>
                    <button type="button" class="button button-secondary" data-clear-filters>Clear filters</button>
                </div>
            </aside>

            <div class="scrim" data-drawer-close hidden></div>

            <main id="main" class="main" tabindex="-1">
                <noscript>
                    <p class="notice">Turn on JavaScript to browse tables, search and navigate.</p>
                </noscript>

                {{-- Overview --}}
                <article class="view prose" data-view="overview">
                    <header class="page-header">
                        <h1 class="display">{{ $meta['title'] }}</h1>
                        <p class="lead">{{ $meta['subtitle'] }}</p>
                    </header>

                    <dl class="facts">
                        <div>
                            <dt>Domains</dt>
                            <dd class="tabular">{{ $meta['domain_count'] }}</dd>
                        </div>
                        <div>
                            <dt>Tables</dt>
                            <dd class="tabular">{{ $meta['table_count'] }}</dd>
                        </div>
                        <div>
                            <dt>Columns</dt>
                            <dd class="tabular">{{ $meta['column_count'] }}</dd>
                        </div>
                        <div>
                            <dt>Source</dt>
                            <dd>Parsed from migration files</dd>
                        </div>
                    </dl>

                    <section>
                        <h2>Domains</h2>
                        <p>Tables are grouped by functional domain. Click a domain to see all its tables.</p>
                        <div class="domain-grid">
                            @foreach ($domains as $domain)
                                <a href="#/domain/{{ $domain['slug'] }}" class="domain-card">
                                    <h3>{{ $domain['name'] }}</h3>
                                    <span class="domain-card-count tabular">{{ count($domain['tables']) }} {{ Str::plural('table', count($domain['tables'])) }}</span>
                                    <ul class="domain-card-tables">
                                        @foreach ($domain['tables'] as $table)
                                            <li>{{ $table['name'] }}</li>
                                        @endforeach
                                    </ul>
                                </a>
                            @endforeach
                        </div>
                    </section>
                </article>

                {{-- Domain detail view — rendered by JS --}}
                <article class="view" data-view="domain" hidden></article>

                {{-- Single table view — rendered by JS --}}
                <article class="view" data-view="table" hidden></article>
            </main>
        </div>

        <dialog class="palette" data-palette aria-label="Search tables">
            <div class="palette-head">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                <input
                    type="search"
                    placeholder="Search domains, tables and columns"
                    autocomplete="off"
                    spellcheck="false"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="palette-results"
                    aria-autocomplete="list"
                    aria-label="Search tables"
                    data-palette-input
                >
                <button type="button" class="copy-button" data-palette-close>Close</button>
            </div>
            <ul class="palette-results" id="palette-results" role="listbox" aria-label="Matching items" data-palette-results></ul>
            <p class="palette-foot caption">
                <span><kbd>↑</kbd> <kbd>↓</kbd> to move</span>
                <span><kbd>Enter</kbd> to open</span>
                <span><kbd>Esc</kbd> to close</span>
            </p>
        </dialog>

        <div class="toast" role="status" aria-live="polite" data-toast hidden></div>

        <script type="application/json" id="data-model-docs-data">@json($documentation)</script>
    </body>
</html>
