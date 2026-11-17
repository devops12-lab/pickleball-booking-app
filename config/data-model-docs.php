<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data Model Documentation Portal
    |--------------------------------------------------------------------------
    |
    | Settings for the documentation portal served at /docs-data-model. The
    | schema is discovered by parsing migration files, so these options
    | control visibility, domain grouping and presentation.
    |
    */

    'enabled' => (bool) env('DATA_MODEL_DOCS_ENABLED', true),

    'path' => 'docs-data-model',

    /*
    | Extra middleware for the documentation page (it already runs in the
    | "web" group). Add "auth" or a gate middleware to make it private.
    */
    'middleware' => [],

    'title' => 'Data Model Reference',

    'subtitle' => 'Complete database schema — tables, columns, types, indexes, and foreign keys.',

    'logo' => 'images/courtflex_logo.png',

    'migrations_path' => database_path('migrations'),

    /*
    | The discovered schema is cached so repeated page loads skip the
    | migration parsing. Run "php artisan cache:clear" after adding or
    | editing migrations while caching is on.
    */
    'cache' => [
        'enabled' => (bool) env('DATA_MODEL_DOCS_CACHE', env('APP_ENV') === 'production'),
        'ttl' => 3600,
    ],

    /*
    | Domain grouping — table name patterns mapped to domain labels. Tables
    | that don't match any domain are placed into "Other".
    */
    /*
    | Per-column description overrides. Keyed as "table.column" → description.
    | These take priority over auto-generated descriptions. Only add entries
    | where the convention-based description is insufficient.
    */
    'column_descriptions' => [
        'users' => [
            'email_verified_at' => 'Timestamp when email ownership was confirmed',
        ],
        'courts' => [
            'surface_type' => 'Court surface material (e.g., concrete, wood, sport court)',
        ],
        'bookings' => [
            'started_at' => 'Scheduled start time for the booking',
        ],
    ],

    'domains' => [
        'Users & Authentication' => [
            'users', 'password_reset_tokens', 'sessions', 'personal_access_tokens',
            'user_auth_providers', 'user_suspensions', 'email_verification_codes',
            'owner_signup_codes', 'user_permissions',
        ],
        'Multi-Tenancy & RBAC' => [
            'tenants', 'roles', 'permissions', 'role_permissions',
            'tenant_users', 'staff_invitations',
        ],
        'Subscriptions & Billing' => [
            'subscription_plans', 'tenant_subscriptions', 'subscription_invoices',
            'platform_settings',
        ],
        'Facilities & Courts' => [
            'facilities', 'courts', 'court_operating_hours', 'court_blocked_schedules',
            'court_price_rules', 'court_images', 'court_fronts', 'venue_profiles',
            'storefront_social_links',
        ],
        'Player Profiles' => [
            'player_profiles', 'player_statistics', 'player_rankings',
            'geo_locations', 'notification_preferences',
        ],
        'Bookings' => [
            'bookings', 'booking_participants', 'booking_status_histories',
            'booking_receipts', 'guest_booking_codes',
        ],
        'Payments' => [
            'payments', 'payment_methods', 'payment_verifications',
            'refunds', 'tenant_payment_methods',
        ],
        'Clubs & Community' => [
            'clubs', 'club_members', 'club_invitations', 'club_membership_plans',
            'club_member_events', 'club_membership_payments',
        ],
        'Open Play' => [
            'open_play_sessions', 'open_play_session_courts', 'open_play_participants',
            'open_play_queue_games', 'open_play_fee_payments', 'open_play_fee_refunds',
        ],
        'Tournaments' => [
            'tournaments', 'tournament_categories', 'tournament_participants',
            'tournament_matches', 'tournament_brackets',
        ],
        'Matches & Scoring' => [
            'matches', 'match_players', 'match_events', 'match_confirmations',
            'match_rating_changes', 'match_results', 'rating_rules', 'rating_adjustments',
        ],
        'Advertisements' => [
            'advertisements', 'advertisement_requests',
            'advertisement_impressions', 'advertisement_clicks',
        ],
        'Platform & Admin' => [
            'announcements', 'app_notifications', 'audit_logs', 'form_drafts',
        ],
        'Database Management' => [
            'database_backups', 'database_backup_schedules',
            'database_restore_logs', 'database_backup_audit_logs',
        ],
        'System' => [
            'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        ],
    ],

];
