@php
    $meta = $documentation['meta'];
    $initial = $documentation['initial'];
    $logo = asset(config('api-docs.logo', 'images/courtflex_logo.png'));
    $clientPayload = [
        'meta' => $meta,
        'categories' => $documentation['categories'],
        'documents' => $documentation['documents'],
        'initial' => $initial,
    ];
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
        @vite(['resources/css/api-docs.css', 'resources/css/workflow-docs.css', 'resources/js/workflow-docs.js'])
    </head>
    <body>
        <a class="skip-link" href="#main">Skip to content</a>

        <header class="topbar">
            <button type="button" class="icon-button menu-button" data-drawer-open aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
            </button>

            <a class="brand" href="{{ route('workflow-docs') }}">
                <img src="{{ $logo }}" alt="CourtFlex" class="brand-logo">
                <span class="brand-title">Backend workflows</span>
            </a>

            <x-docs.portal-nav current="workflows" />

            <div class="topbar-actions">
                <button type="button" class="search-trigger" data-palette-open aria-haspopup="dialog" aria-label="Search documentation">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <span class="search-trigger-label">Search documentation</span>
                    <kbd data-shortcut-label>Ctrl K</kbd>
                </button>

                <button type="button" class="icon-button" data-theme-toggle aria-label="Switch to dark theme">
                    <svg class="icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" /></svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" /></svg>
                </button>
            </div>
        </header>

        <div class="layout">
            <aside class="sidebar" id="sidebar" aria-label="Workflow documentation navigation">
                <div class="sidebar-head">
                    <span class="label">Navigation</span>
                    <button type="button" class="icon-button" data-drawer-close aria-label="Close navigation">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
                    </button>
                </div>

                <x-docs.portal-nav current="workflows" placement="sidebar" />

                <label class="search search-side">
                    <span class="sr-only">Filter documents</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <input type="search" placeholder="Filter documents" autocomplete="off" data-filter>
                </label>

                <div class="method-filters" role="group" aria-label="Filter by status">
                    @foreach ($meta['statuses'] as $status)
                        <button type="button" class="method-filter status-filter" data-status-filter="{{ $status }}" aria-pressed="false">{{ $status }}</button>
                    @endforeach
                </div>

                <div class="sidebar-tools">
                    <span class="caption" data-nav-summary>{{ $meta['document_count'] }} documents</span>
                    <button type="button" class="link-button" data-expand-all>Expand all</button>
                </div>

                <nav class="nav" data-nav>
                    @foreach ($documentation['categories'] as $category)
                        <div class="nav-module">
                            <p class="nav-heading">{{ $category['name'] }}</p>
                            @foreach ($category['documents'] as $document)
                                <a class="nav-link" href="#/doc/{{ $document['slug'] }}">{{ $document['title'] }}</a>
                            @endforeach
                        </div>
                    @endforeach
                </nav>

                <div class="empty-state" data-nav-empty hidden>
                    <p>No documents match your filters.</p>
                    <button type="button" class="button button-secondary" data-clear-filters>Clear filters</button>
                </div>
            </aside>

            <div class="scrim" data-drawer-close hidden></div>

            <main id="main" class="main" tabindex="-1">
                <div class="view doc-layout" data-doc-view>
                    <article class="doc" data-doc aria-live="polite">
                        @if ($initial)
                            <header class="doc-header">
                                <p class="eyebrow">{{ $initial['category'] }}</p>
                                <h1 class="display">{{ $initial['title'] }}</h1>
                                <div class="badges">
                                    <span class="status-badge" data-status="{{ \Illuminate\Support\Str::slug($initial['status']) }}">{{ $initial['status'] }}</span>
                                    <span class="badge">{{ $initial['source'] }}</span>
                                </div>
                            </header>
                            <div class="doc-prose">{!! $initial['html'] !!}</div>
                        @else
                            <div class="empty-state">
                                <h1 class="display">No documents found</h1>
                                <p>Add Markdown files to the {{ config('workflow-docs.source_directory') }} directory to publish them here.</p>
                            </div>
                        @endif
                    </article>
                    <aside class="doc-toc" data-toc aria-label="On this page"></aside>
                </div>
            </main>
        </div>

        <dialog class="palette" data-palette aria-label="Search documentation">
            <div class="palette-head">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                <input
                    type="search"
                    placeholder="Search documents, headings and terms"
                    autocomplete="off"
                    spellcheck="false"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="palette-results"
                    aria-autocomplete="list"
                    aria-label="Search documentation"
                    data-palette-input
                >
                <button type="button" class="copy-button" data-palette-close>Close</button>
            </div>
            <ul class="palette-results" id="palette-results" role="listbox" aria-label="Matching documents" data-palette-results></ul>
            <p class="palette-foot caption">
                <span><kbd>↑</kbd> <kbd>↓</kbd> to move</span>
                <span><kbd>Enter</kbd> to open</span>
                <span><kbd>Esc</kbd> to close</span>
            </p>
        </dialog>

        <div class="toast" role="status" aria-live="polite" data-toast hidden></div>

        <script type="application/json" id="workflow-docs-data">@json($clientPayload)</script>
    </body>
</html>
