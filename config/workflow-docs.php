<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backend Workflow Documentation
    |--------------------------------------------------------------------------
    |
    | Settings for the knowledge base served at /docs-work-flow. Documents are
    | discovered from the Markdown files in the "path" directory only. Any
    | other file on disk can never be loaded through this portal.
    |
    */

    'enabled' => (bool) env('WORKFLOW_DOCS_ENABLED', true),

    'path' => 'docs-work-flow',

    /*
    | Extra middleware for the page and its document endpoint (both already
    | run in the "web" group). Add "auth" to make the knowledge base private.
    */
    'middleware' => [],

    'title' => 'CourtFlex Backend Workflows',

    'subtitle' => 'How the CourtFlex Laravel backend handles requests, business processes and data.',

    /*
    | Directory (relative to the project root) that holds the Markdown files.
    */
    'source_directory' => 'docs',

    'max_depth' => 4,

    'max_file_size' => 512 * 1024,

    /*
    | Parsed documents are cached under a key that includes each file's
    | modification time and size, so editing a Markdown file refreshes it
    | automatically. "php artisan cache:clear" also resets everything.
    */
    'cache' => [
        'enabled' => (bool) env('WORKFLOW_DOCS_CACHE', true),
        'ttl' => 86400,
    ],

    /*
    | Sidebar categories, in display order.
    */
    'categories' => [
        'Getting started',
        'System architecture',
        'Authentication & authorization',
        'Facility & court management',
        'Booking management',
        'Payment processing',
        'Clubs & open play',
        'Tournaments & rankings',
        'Platform administration',
        'Database architecture',
        'Security & error handling',
        'Background jobs & notifications',
        'API reference',
        'Project status',
    ],

    /*
    | Allowed implementation statuses.
    */
    'statuses' => [
        'Implemented',
        'Partially Implemented',
        'Planned',
        'Needs Verification',
        'Deprecated',
    ],

    /*
    | Metadata for Markdown files that have no front matter of their own.
    | Keys are paths relative to the source directory. "notes" record
    | conflicts found when the file was compared with the source code.
    */
    'documents' => [
        'DEVELOPMENT_SETUP.md' => [
            'category' => 'Getting started',
            'status' => 'Needs Verification',
            'order' => 20,
            'notes' => [
                'States the API listens on port 8000 and documents seeded demo accounts. Passwords are redacted on this page.',
            ],
        ],
        'POSTMAN_TESTING_GUIDE.md' => [
            'category' => 'Getting started',
            'status' => 'Needs Verification',
            'order' => 30,
            'notes' => [
                'Demo passwords and tokens in the examples are redacted on this page.',
            ],
        ],
        'REACT_API_INTEGRATION.md' => [
            'category' => 'Getting started',
            'status' => 'Needs Verification',
            'order' => 40,
            'notes' => [
                'Frontend guidance. The CORS settings it references match config/cors.php (FRONTEND_ALLOWED_ORIGINS, CORS_SUPPORTS_CREDENTIALS).',
            ],
        ],
        'ARCHITECTURE.md' => [
            'category' => 'System architecture',
            'status' => 'Needs Verification',
            'order' => 20,
            'notes' => [
                'Describes a policy authorization layer. The 7 policies in app/Policies are never called: no controller uses authorize(), Gate or can().',
                'States tenant.context can resolve the tenant from "user tenancy". IdentifyTenant only reads the X-Tenant-ID header, a {tenant} route parameter or a tenant_id input.',
                'States FileUploadService uses SHA-256 file names. It uses Str::random(40).',
                'Lists PolicyLayer between controllers and services in the diagram; see the verified diagram in "System architecture".',
            ],
        ],
        'PLAN.md' => [
            'category' => 'System architecture',
            'status' => 'Planned',
            'order' => 30,
            'notes' => [
                'Original plan. It lists a notifications table and RequirePermission enforcement; neither exists in the current routes or migrations.',
                'Mentions a users.google_id column. Google identities are stored in user_auth_providers.',
                'Names a CourtFlexFoundationSeeder, which does not exist. Demo data comes from DemoDataSeeder.',
            ],
        ],
        'MULTI_TENANCY.md' => [
            'category' => 'Authentication & authorization',
            'status' => 'Needs Verification',
            'order' => 30,
            'notes' => [
                'The sequence diagram includes RequirePermission. No route uses the permission or staff.permission middleware.',
                'States every administrative and financial mutation is audit-logged. AuditLogService is only called for logins and payment verification.',
                'BelongsToTenant only adds a tenant() relation and a forTenant() scope; it does not apply a global scope.',
            ],
        ],
        'RBAC_PERMISSION_MATRIX.md' => [
            'category' => 'Authentication & authorization',
            'status' => 'Partially Implemented',
            'order' => 40,
            'notes' => [
                'Roles and permissions are seeded and User::hasPermission() evaluates them, but no route or controller checks individual permissions. Access is decided by role middleware and controller ownership checks.',
            ],
        ],
        'DATABASE_SCHEMA.md' => [
            'category' => 'Database architecture',
            'status' => 'Needs Verification',
            'order' => 20,
            'notes' => [
                'Lists booking status pending_payment. Bookings are created with status "pending".',
                'Lists payment status "rejected". PaymentVerificationService::reject() sets "failed".',
                'tenants.subscription_plan_id is indexed but has no foreign key constraint.',
            ],
        ],
        'API_DOCUMENTATION.md' => [
            'category' => 'API reference',
            'status' => 'Needs Verification',
            'order' => 10,
            'notes' => [
                'Hand-written route list. /docs-api-endpoints is generated from the live route table and is the authoritative reference.',
            ],
        ],
        'IMPLEMENTATION_ROADMAP.md' => [
            'category' => 'Project status',
            'status' => 'Needs Verification',
            'order' => 20,
            'notes' => [
                'Marks "Policy-Based Authorization Layer" as completed. The policies exist but are never invoked.',
                'States 87 passing tests. The suite currently has more tests; run php artisan test for the current count.',
            ],
        ],
    ],

    /*
    | Values that must never be shown, even if a Markdown file contains them.
    | Each pattern's first capture group is kept and the rest is replaced.
    */
    'redactions' => [
        '/((?:password|passwd|secret|api[_-]?key|access[_-]?token)\w*["\']?\s*[:=]\s*)"[^"\n]+"/i' => '$1"[redacted]"',
        '/((?<![\w\/-])(?:password|passwd|secret)\w*\s*[:=]\s*)`[^`\n]+`/i' => '$1`[redacted]`',
        // Password-shaped values in code spans or strings: letters, digits and a symbol, no spaces or slashes.
        '/`(?=[^`\s]*[A-Za-z])(?=[^`\s]*\d)(?=[^`\s]*[!@#$%^&])[^`\s\/*]{8,64}`/' => '`[redacted]`',
        '/"(?=[^"\s]*[A-Za-z])(?=[^"\s]*\d)(?=[^"\s]*[!@#$%^&])[^"\s\/*]{8,64}"/' => '"[redacted]"',
        '/(Bearer\s+)(?!YOUR_|\{|<|\$)[A-Za-z0-9|._\-]{16,}/' => '$1[redacted]',
    ],

    /*
    | Inline code that looks like a project path is checked against these
    | directories and marked as verified or unresolved.
    */
    'source_roots' => ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'tests'],

];
