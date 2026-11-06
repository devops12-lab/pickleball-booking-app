@php
    $meta = $documentation['meta'];
    $endpoints = collect($documentation['endpoints']);
    $authEndpoints = $endpoints->where('module', 'Authentication')->groupBy('area');
    $loginExample = $endpoints->first(fn (array $endpoint): bool => str_ends_with((string) $endpoint['route_name'], '.auth.login') && $endpoint['response']['data_documented']);
    $rateLimits = $endpoints->pluck('rate_limits')->flatten(1)->unique('name')->sortBy('name')->values();
    $logo = asset(config('api-docs.logo'));
    $authHeaders = "Authorization: Bearer YOUR_ACCESS_TOKEN\nAccept: application/json\nContent-Type: application/json";
    $successEnvelope = json_encode(['success' => true, 'message' => 'Operation completed successfully.', 'data' => new stdClass, 'meta' => ['current_page' => 1, 'total' => 1]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $errorEnvelope = json_encode(['success' => false, 'message' => 'The given data was invalid.', 'errors' => ['email' => ['The email field is required.']]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $quickstartLogin = $endpoints->firstWhere('route_name', 'players.auth.login') ?? $endpoints->first(fn (array $endpoint): bool => str_ends_with((string) $endpoint['route_name'], '.auth.login'));
    $quickstartMe = $quickstartLogin ? $endpoints->firstWhere('route_name', \Illuminate\Support\Str::replaceLast('.login', '.me', $quickstartLogin['route_name'])) : null;
    $quickstartPublic = $endpoints->first(fn (array $endpoint): bool => ! $endpoint['authentication']['required'] && $endpoint['method'] === 'GET' && $endpoint['path_parameters'] === [] && $endpoint['module'] !== 'Authentication');
    $loginPayload = $quickstartLogin ? collect($quickstartLogin['body']['example'] ?? [])->only(['email', 'password'])->all() : [];
    $quickstartSteps = array_values(array_filter([
        $quickstartPublic ? [
            'title' => 'Call a public endpoint',
            'text' => 'No token needed. Check that the base URL works.',
            'id' => $quickstartPublic['id'],
            'code' => 'curl "'.$meta['root_url'].$quickstartPublic['uri']."\" \\\n  -H \"Accept: application/json\"",
        ] : null,
        $quickstartLogin ? [
            'title' => 'Log in to get a token',
            'text' => 'Copy access_token from the response.',
            'id' => $quickstartLogin['id'],
            'code' => 'curl -X POST "'.$meta['root_url'].$quickstartLogin['uri']."\" \\\n  -H \"Accept: application/json\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '".json_encode($loginPayload, JSON_UNESCAPED_SLASHES)."'",
        ] : null,
        $quickstartMe ? [
            'title' => 'Call a protected endpoint',
            'text' => 'Send the token as a Bearer token.',
            'id' => $quickstartMe['id'],
            'code' => 'curl "'.$meta['root_url'].$quickstartMe['uri']."\" \\\n  -H \"Accept: application/json\" \\\n  -H \"Authorization: Bearer YOUR_ACCESS_TOKEN\"",
        ] : null,
    ]));
    $portalActions = [
        'login' => 'Log in',
        'register' => 'Register',
        'google' => 'Google sign-in',
        'me' => 'Current user',
        'logout' => 'Log out',
        'tokens.revoke_all' => 'Revoke all tokens',
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
        @vite(['resources/css/api-docs.css', 'resources/js/api-docs.js'])
    </head>
    <body>
        <a class="skip-link" href="#main">Skip to content</a>

        <header class="topbar">
            <button type="button" class="icon-button menu-button" data-drawer-open aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
            </button>

            <a class="brand" href="#/overview">
                <img src="{{ $logo }}" alt="CourtFlex" class="brand-logo">
                <span class="brand-title">API Documentation</span>
                <span class="badge">{{ $meta['version'] }}</span>
            </a>

            <x-docs.portal-nav current="api" />

            <div class="topbar-base">
                <span class="caption">Base URL</span>
                <code class="base-url" data-base-url>{{ $meta['base_url'] }}</code>
                <button type="button" class="copy-button" data-copy="base" aria-label="Copy base URL">Copy</button>
            </div>

            <div class="topbar-actions">
                <div class="token-chip" data-token-chip hidden>
                    <span class="token-chip-dot" aria-hidden="true"></span>
                    <span class="token-chip-label">Token set</span>
                    <button type="button" class="copy-button" data-token-clear>Clear</button>
                </div>

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
            <aside class="sidebar" id="sidebar" aria-label="API navigation">
                <div class="sidebar-head">
                    <span class="label">Navigation</span>
                    <button type="button" class="icon-button" data-drawer-close aria-label="Close navigation">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
                    </button>
                </div>

                <x-docs.portal-nav current="api" placement="sidebar" />

                <label class="search search-side">
                    <span class="sr-only">Filter endpoints</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                    <input type="search" placeholder="Filter endpoints" autocomplete="off" data-search>
                </label>

                <div class="method-filters" role="group" aria-label="Filter by HTTP method">
                    @foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method)
                        <button type="button" class="method-filter" data-method-filter="{{ $method }}" aria-pressed="false">{{ $method }}</button>
                    @endforeach
                </div>

                <div class="sidebar-tools">
                    <span class="caption" data-nav-summary>{{ $meta['endpoint_count'] }} endpoints</span>
                    <button type="button" class="link-button" data-expand-all>Expand all</button>
                </div>

                <nav class="nav">
                    <div class="nav-group">
                        <p class="nav-heading">Getting started</p>
                        <a class="nav-link" href="#/overview" data-nav="overview">Overview</a>
                        <a class="nav-link" href="#/authentication" data-nav="authentication">Authentication</a>
                    </div>

                    <div data-nav-modules></div>

                    <div class="empty-state" data-nav-empty hidden>
                        <p>No endpoints match your filters.</p>
                        <button type="button" class="button button-secondary" data-clear-filters>Clear filters</button>
                    </div>
                </nav>
            </aside>

            <div class="scrim" data-drawer-close hidden></div>

            <main id="main" class="main" tabindex="-1">
                <noscript>
                    <p class="notice">Turn on JavaScript to browse endpoints, search and copy examples.</p>
                </noscript>

                {{-- Overview --}}
                <article class="view prose" data-view="overview">
                    <header class="page-header">
                        <h1 class="display">{{ $meta['title'] }}</h1>
                        <p class="lead">{{ $meta['subtitle'] }}</p>
                    </header>

                    <dl class="facts">
                        <div>
                            <dt>Base URL</dt>
                            <dd class="fact-copy"><code>{{ $meta['base_url'] }}</code> <button type="button" class="copy-button" data-copy="base">Copy</button></dd>
                        </div>
                        <div>
                            <dt>Version</dt>
                            <dd>{{ $meta['version'] }}</dd>
                        </div>
                        <div>
                            <dt>Endpoints</dt>
                            <dd class="tabular">{{ $meta['endpoint_count'] }}</dd>
                        </div>
                        <div>
                            <dt>Format</dt>
                            <dd>JSON over HTTPS</dd>
                        </div>
                    </dl>

                    @if ($quickstartSteps !== [])
                        <section>
                            <h2>Quickstart</h2>
                            <p>Make your first calls in a minute. Each step links to its full reference.</p>
                            <ol class="steps">
                                @foreach ($quickstartSteps as $step)
                                    <li class="step">
                                        <div class="step-head">
                                            <span class="step-number tabular" aria-hidden="true">{{ $loop->iteration }}</span>
                                            <div>
                                                <h3>{{ $step['title'] }}</h3>
                                                <p>{{ $step['text'] }} <a href="#/e/{{ $step['id'] }}">View reference</a></p>
                                            </div>
                                        </div>
                                        <x-api-docs.code-block :code="$step['code']" label="cURL" copy-label="Copy cURL" />
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    @endif

                    <section>
                        <h2>Modules</h2>
                        <p>Endpoints are grouped by the portal they belong to. Each portal has its own login and its own role checks on the server.</p>
                        <ul class="module-list">
                            @foreach ($documentation['modules'] as $module)
                                <li>
                                    <a href="#/module/{{ \Illuminate\Support\Str::slug($module['name']) }}" data-module-link="{{ $module['name'] }}">{{ $module['name'] }}</a>
                                    <span class="badge tabular">{{ $module['count'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>

                    <section>
                        <h2>Making requests</h2>
                        <p>Send every request with an <code>Accept: application/json</code> header so errors are returned as JSON. Requests with a body use JSON, except file uploads, which use <code>multipart/form-data</code>.</p>
                        <x-api-docs.code-block :code="$authHeaders" label="Request headers" copy-label="Copy headers" />
                    </section>

                    <section>
                        <h2>Response format</h2>
                        <p>Every response uses the same envelope. Successful responses carry <code>data</code>, and list endpoints add a <code>meta</code> object with pagination details.</p>
                        <x-api-docs.code-block :code="$successEnvelope" label="Success" language="json" />
                        <p>Errors set <code>success</code> to <code>false</code>. Validation errors list messages per field in <code>errors</code>.</p>
                        <x-api-docs.code-block :code="$errorEnvelope" label="Error" language="json" />
                    </section>

                    <section>
                        <h2>Status codes</h2>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th scope="col">Status</th><th scope="col">Meaning</th></tr></thead>
                                <tbody>
                                    <tr><td class="tabular">200, 201</td><td>Request succeeded. 201 means a record was created.</td></tr>
                                    <tr><td class="tabular">400</td><td>The request could not be completed, for example a missing tenant context.</td></tr>
                                    <tr><td class="tabular">401</td><td>No valid access token was sent.</td></tr>
                                    <tr><td class="tabular">403</td><td>The token is valid but the account, role or tenant is not allowed.</td></tr>
                                    <tr><td class="tabular">404</td><td>The resource or endpoint does not exist.</td></tr>
                                    <tr><td class="tabular">422</td><td>Validation failed. See <code>errors</code> for each field.</td></tr>
                                    <tr><td class="tabular">429</td><td>Rate limit reached. Wait before retrying.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section>
                        <h2>Tenant context</h2>
                        <p>Facility owner and staff endpoints act on one facility account. Send its ID or slug in the <code>X-Tenant-ID</code> header. Requests without it are rejected with status 400.</p>
                        <x-api-docs.code-block code="X-Tenant-ID: 1" label="Tenant header" copy-label="Copy header" />
                    </section>

                    @if ($rateLimits->isNotEmpty())
                        <section>
                            <h2>Rate limits</h2>
                            <p>Limits are read from the limiters registered in the application. Each endpoint page shows the limit that applies to it.</p>
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th scope="col">Limiter</th><th scope="col">Authenticated</th><th scope="col">Guest</th></tr></thead>
                                    <tbody>
                                        @foreach ($rateLimits as $limit)
                                            <tr>
                                                <td><code>{{ $limit['name'] }}</code></td>
                                                <td class="tabular">{{ $limit['authenticated'] ?? '—' }}</td>
                                                <td class="tabular">{{ $limit['guest'] ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    @endif
                </article>

                {{-- Authentication --}}
                <article class="view prose" data-view="authentication" hidden>
                    <header class="page-header">
                        <p class="eyebrow">Getting started</p>
                        <h1 class="display">Authentication</h1>
                        <p class="lead">The API uses Laravel Sanctum personal access tokens. Log in through the portal that matches the account's role, then send the token as a Bearer token.</p>
                    </header>

                    <section>
                        <h2>1. Get a token</h2>
                        <p>Call a login endpoint with an email and password, or a Google ID token where Google sign-in is available. The response contains <code>access_token</code> and <code>token_type</code>.</p>
                        @if ($loginExample)
                            <x-api-docs.code-block :code="json_encode($loginExample['response']['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)" label="Login response" language="json" />
                        @endif
                    </section>

                    <section>
                        <h2>2. Send the token</h2>
                        <p>Add the token to the <code>Authorization</code> header of every protected request.</p>
                        <x-api-docs.code-block :code="$authHeaders" label="Headers" copy-label="Copy headers" />
                    </section>

                    <section>
                        <h2>Token lifetime</h2>
                        @if ($meta['token_expiration_minutes'])
                            <p>Tokens expire {{ $meta['token_expiration_minutes'] }} minutes after they are issued. Log in again to get a new one.</p>
                        @else
                            <p>Tokens do not expire on a timer. They stay valid until they are revoked by logging out, by revoking all tokens, or by a password reset.</p>
                        @endif
                        <p>Log out revokes the token used for the request. Revoke all tokens signs the account out on every device.</p>
                    </section>

                    <section>
                        <h2>Portal endpoints</h2>
                        <p>Each portal has its own authentication routes. A token from one portal is checked against the role required by the endpoint you call.</p>
                        @foreach ($authEndpoints as $area => $items)
                            <h3>{{ $area }}</h3>
                            <ul class="endpoint-links">
                                @foreach ($portalActions as $suffix => $label)
                                    @php($endpoint = $items->first(fn (array $item): bool => str_ends_with((string) $item['route_name'], '.auth.'.$suffix)))
                                    @if ($endpoint)
                                        <li>
                                            <a href="#/e/{{ $endpoint['id'] }}">
                                                <span class="method method-{{ strtolower($endpoint['method']) }}">{{ $endpoint['method'] }}</span>
                                                <span class="endpoint-link-label">{{ $label }}</span>
                                                <code>{{ $endpoint['uri'] }}</code>
                                            </a>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        @endforeach
                    </section>

                    <section>
                        <h2>Authentication errors</h2>
                        <x-api-docs.code-block :code="json_encode(['success' => false, 'message' => 'Unauthenticated.', 'errors' => []], JSON_PRETTY_PRINT)" label="401 Missing or invalid token" language="json" />
                        <x-api-docs.code-block :code="json_encode(['success' => false, 'message' => 'Invalid email or password provided.', 'errors' => []], JSON_PRETTY_PRINT)" label="401 Wrong credentials" language="json" />
                        <x-api-docs.code-block :code="json_encode(['success' => false, 'message' => 'Unauthorized: You do not possess the required role [player] for this API endpoint.', 'errors' => []], JSON_PRETTY_PRINT)" label="403 Wrong role" language="json" />
                    </section>
                </article>

                {{-- Module index and endpoint pages are rendered from the discovered data. --}}
                <article class="view" data-view="module" hidden></article>
                <article class="view" data-view="endpoint" hidden></article>
            </main>
        </div>

        <dialog class="palette" data-palette aria-label="Search documentation">
            <div class="palette-head">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                <input
                    type="search"
                    placeholder="Search endpoints, modules and guides"
                    autocomplete="off"
                    spellcheck="false"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="palette-results"
                    aria-autocomplete="list"
                    aria-label="Search endpoints"
                    data-palette-input
                >
                <button type="button" class="copy-button" data-palette-close>Close</button>
            </div>
            <ul class="palette-results" id="palette-results" role="listbox" aria-label="Matching endpoints" data-palette-results></ul>
            <p class="palette-foot caption">
                <span><kbd>↑</kbd> <kbd>↓</kbd> to move</span>
                <span><kbd>Enter</kbd> to open</span>
                <span><kbd>Esc</kbd> to close</span>
            </p>
        </dialog>

        <div class="toast" role="status" aria-live="polite" data-toast hidden></div>

        <script type="application/json" id="api-docs-data">@json($documentation)</script>
    </body>
</html>
