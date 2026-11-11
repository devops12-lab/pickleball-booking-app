<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Documentation Portal
    |--------------------------------------------------------------------------
    |
    | Settings for the developer portal served at /docs-api-endpoints. The
    | endpoint list is discovered from the registered Laravel routes, so
    | these options only control visibility, grouping and presentation.
    |
    */

    'enabled' => (bool) env('API_DOCS_ENABLED', true),

    'path' => 'docs-api-endpoints',

    /*
    | Extra middleware for the documentation page (it already runs in the
    | "web" group). Add "auth" or a gate middleware to make it private.
    */
    'middleware' => [],

    'title' => 'CourtFlex API Documentation',

    'subtitle' => 'Explore, understand, and integrate with the CourtFlex REST API.',

    'version' => 'v1',

    /*
    | Public root URL shown in the docs (e.g. https://api.courtflex.com).
    | Leave empty to use the host of the incoming request, which follows
    | Laravel's trusted proxy settings. Set it for staging or production
    | when the app runs behind a proxy whose headers are not trusted.
    */
    'base_url' => env('API_DOCS_BASE_URL'),

    /*
    | Only routes whose URI starts with this prefix are documented. Every
    | other route (web pages, framework internals, storage, health checks,
    | Boost, Sanctum CSRF) is excluded by this allowlist.
    */
    'prefix' => 'api/v1',

    'logo' => 'images/courtflex_logo.png',

    /*
    | Internal endpoints are only listed when "show_internal" is true. By
    | default they are visible in the local environment only.
    */
    'show_internal' => (bool) env('API_DOCS_SHOW_INTERNAL', env('APP_ENV', 'production') === 'local'),

    'internal_routes' => [
        'api/v1/admin/*',
    ],

    /*
    | URI patterns or route names that are never documented.
    */
    'hidden_routes' => [
        //
    ],

    /*
    | Show controller, Form Request and API Resource class names.
    */
    'show_implementation' => (bool) env('API_DOCS_SHOW_IMPLEMENTATION', true),

    /*
    | The interactive "Try it" console sends requests from the viewer's own
    | browser, with the viewer's own token. It is never available for
    | internal endpoints.
    */
    'try_it' => [
        'enabled' => (bool) env('API_DOCS_TRY_IT', env('APP_ENV', 'production') !== 'production'),
    ],

    /*
    | The discovered documentation is cached under a key derived from the
    | route table and this config, so adding or changing routes always
    | produces fresh output. Run "php artisan cache:clear" after editing
    | controllers, Form Requests or Resources while caching is on.
    */
    'cache' => [
        'enabled' => (bool) env('API_DOCS_CACHE', env('APP_ENV', 'production') === 'production'),
        'ttl' => 3600,
    ],

    /*
    | Sidebar categories. The first pattern that matches a route URI wins.
    | Routes that match nothing fall into "Other".
    */
    'modules' => [
        'api/v1/*/auth/*' => 'Authentication',
        'api/v1/public/*' => 'Public discovery',
        'api/v1/admin/*' => 'System administration',
        'api/v1/owners/*' => 'Facility owners',
        'api/v1/staff/*' => 'Facility staff',
        'api/v1/players/*' => 'Players',
        'api/v1/organizers/*' => 'Club organizers',
    ],

    /*
    | Labels for the role portal segment that follows the API prefix.
    */
    'areas' => [
        'public' => 'Public',
        'admin' => 'System admin',
        'owners' => 'Facility owner',
        'staff' => 'Facility staff',
        'players' => 'Player',
        'organizers' => 'Club organizer',
    ],

    /*
    | Class namespaces stripped from the implementation details.
    */
    'namespaces' => [
        'controllers' => 'App\\Http\\Controllers\\Api\\V1\\',
        'requests' => 'App\\Http\\Requests\\',
        'resources' => 'App\\Http\\Resources\\',
    ],

];
