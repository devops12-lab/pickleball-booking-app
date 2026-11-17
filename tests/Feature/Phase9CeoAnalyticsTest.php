<?php

use App\Models\Advertisement;
use App\Models\Booking;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::create([
        'name' => 'Analytics Venue',
        'slug' => 'analytics-venue',
        'business_email' => 'analytics@courtflex.test',
        'status' => 'active',
    ]);

    $this->admin = User::create([
        'name' => 'CEO Admin',
        'email' => 'ceo@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->admin->id,
        'role_id' => Role::where('name', 'system_admin')->first()->id,
        'status' => 'active',
    ]);

    $this->player = User::create([
        'name' => 'Player',
        'email' => 'player@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->player->id,
        'role_id' => Role::where('name', 'player')->first()->id,
        'status' => 'active',
    ]);

    $this->adminToken = $this->admin->createToken('test')->plainTextToken;
    $this->playerToken = $this->player->createToken('test')->plainTextToken;
});

// ──────────────────────────────────────────────────────────────
// Overview
// ──────────────────────────────────────────────────────────────

test('admin can retrieve analytics overview', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->getJson('/api/v1/admin/analytics/overview');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'users' => ['total', 'new_in_period'],
                'facilities' => ['total', 'active'],
                'bookings' => ['total', 'in_period'],
                'revenue' => ['total_collected', 'in_period'],
                'subscriptions' => ['active', 'trialing'],
                'clubs' => ['total'],
                'tournaments' => ['total'],
                'advertisements' => ['active', 'total_impressions', 'total_clicks'],
            ],
        ]);
});

test('non-admin cannot access analytics', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->playerToken}",
    ])->getJson('/api/v1/admin/analytics/overview');

    $response->assertForbidden();
});

// ──────────────────────────────────────────────────────────────
// User Analytics
// ──────────────────────────────────────────────────────────────

test('admin can retrieve user analytics', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->getJson('/api/v1/admin/analytics/users?period=monthly');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['total', 'active', 'suspended', 'by_role', 'new_in_period', 'growth_rate_percent', 'trend'],
        ]);
});

// ──────────────────────────────────────────────────────────────
// Booking Analytics
// ──────────────────────────────────────────────────────────────

test('admin can retrieve booking analytics', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->getJson('/api/v1/admin/analytics/bookings');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['total', 'in_period', 'by_status', 'guest_bookings', 'registered_bookings', 'avg_value', 'trend'],
        ]);
});

// ──────────────────────────────────────────────────────────────
// Revenue Analytics
// ──────────────────────────────────────────────────────────────

test('admin can retrieve revenue analytics', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->getJson('/api/v1/admin/analytics/revenue');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['total_collected', 'booking_revenue', 'subscription_revenue', 'total_in_period', 'pending_payments', 'mrr', 'arr', 'trend'],
        ]);
});

// ──────────────────────────────────────────────────────────────
// Subscription Analytics
// ──────────────────────────────────────────────────────────────

test('admin can retrieve subscription analytics', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->getJson('/api/v1/admin/analytics/subscriptions');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['total', 'by_status', 'plan_distribution', 'new_in_period', 'cancelled_in_period', 'churn_rate_percent'],
        ]);
});

// ──────────────────────────────────────────────────────────────
// Date Filtering
// ──────────────────────────────────────────────────────────────

test('analytics endpoints accept date range parameters', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->getJson('/api/v1/admin/analytics/users?start_date=2026-01-01&end_date=2026-12-31&period=quarterly');

    $response->assertOk()
        ->assertJsonPath('data.total', 2); // admin + player
});

// ──────────────────────────────────────────────────────────────
// Remaining Category Endpoints
// ──────────────────────────────────────────────────────────────

test('admin can retrieve facility analytics', function (): void {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->getJson('/api/v1/admin/analytics/facilities');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['total', 'active', 'inactive', 'avg_courts_per_facility', 'new_in_period', 'trend'],
        ]);
});

test('admin can retrieve club and tournament analytics', function (): void {
    $clubResponse = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->getJson('/api/v1/admin/analytics/clubs');

    $clubResponse->assertOk()
        ->assertJsonStructure([
            'data' => ['total', 'active', 'total_memberships', 'new_in_period', 'trend'],
        ]);

    $tournamentResponse = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->getJson('/api/v1/admin/analytics/tournaments');

    $tournamentResponse->assertOk()
        ->assertJsonStructure([
            'data' => ['total', 'by_status', 'total_registrations', 'in_period', 'trend'],
        ]);
});

test('admin can retrieve advertisement analytics', function (): void {
    Advertisement::create([
        'title' => 'Test Ad',
        'type' => 'banner',
        'placement' => 'dashboard',
        'target_audience' => 'all',
        'start_date' => now()->subDay(),
        'end_date' => now()->addMonth(),
        'status' => 'published',
        'created_by' => $this->admin->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->adminToken}",
    ])->getJson('/api/v1/admin/analytics/advertisements');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['total_ads', 'active_ads', 'total_impressions', 'total_clicks', 'overall_ctr', 'top_ads', 'by_placement'],
        ]);
});
