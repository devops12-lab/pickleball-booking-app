<?php

use App\Exceptions\SubscriptionLimitExceededException;
use App\Models\Court;
use App\Models\Facility;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\SubscriptionEntitlementService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ownerRole = Role::where('name', 'owner')->first();

    $this->plan = SubscriptionPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 999.00,
        'yearly_price' => 9990.00,
        'billing_interval' => 'monthly',
        'max_courts' => 3,
        'max_staff' => 5,
        'features' => ['guest_booking', 'reports'],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->tenant = Tenant::create([
        'name' => 'Entitlement Venue',
        'slug' => 'entitlement-venue',
        'business_email' => 'ent@courtflex.test',
        'status' => 'active',
        'subscription_plan_id' => $this->plan->id,
    ]);

    TenantSubscription::create([
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->plan->id,
        'billing_interval' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $this->owner = User::create([
        'name' => 'Plan Owner',
        'email' => 'owner@entitlement.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);

    $this->facility = Facility::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Main Facility',
        'slug' => 'main-facility',
        'address' => '1 Test St',
        'city' => 'Manila',
        'is_active' => true,
    ]);
});

// ──────────────────────────────────────────────────────────────
// Entitlement Service
// ──────────────────────────────────────────────────────────────

test('entitlement service allows creation when under limit', function (): void {
    $service = app(SubscriptionEntitlementService::class);

    expect($service->canCreate($this->tenant, 'court'))->toBeTrue();
    expect($service->isSubscriptionActive($this->tenant))->toBeTrue();
});

test('entitlement service blocks creation when at limit', function (): void {
    foreach (range(1, 3) as $i) {
        Court::create([
            'facility_id' => $this->facility->id,
            'tenant_id' => $this->tenant->id,
            'name' => "Court {$i}",
            'hourly_rate' => 300,
            'is_active' => true,
        ]);
    }

    $service = app(SubscriptionEntitlementService::class);

    expect($service->canCreate($this->tenant, 'court'))->toBeFalse();
});

test('checkOrFail throws SubscriptionLimitExceededException when at limit', function (): void {
    foreach (range(1, 3) as $i) {
        Court::create([
            'facility_id' => $this->facility->id,
            'tenant_id' => $this->tenant->id,
            'name' => "Court {$i}",
            'hourly_rate' => 300,
            'is_active' => true,
        ]);
    }

    $service = app(SubscriptionEntitlementService::class);

    $service->checkOrFail($this->tenant, 'court');
})->throws(SubscriptionLimitExceededException::class);

test('entitlement usage returns correct counts', function (): void {
    Court::create([
        'facility_id' => $this->facility->id,
        'tenant_id' => $this->tenant->id,
        'name' => 'Court 1',
        'hourly_rate' => 300,
        'is_active' => true,
    ]);

    $service = app(SubscriptionEntitlementService::class);
    $usage = $service->usage($this->tenant);

    expect($usage['court']['current'])->toBe(1);
    expect($usage['court']['limit'])->toBe(3);
    expect($usage['court']['remaining'])->toBe(2);
});

test('hasFeature checks plan features array', function (): void {
    $service = app(SubscriptionEntitlementService::class);

    expect($service->hasFeature($this->tenant, 'guest_booking'))->toBeTrue();
    expect($service->hasFeature($this->tenant, 'advanced_analytics'))->toBeFalse();
});

// ──────────────────────────────────────────────────────────────
// Owner Court Creation Entitlement Integration
// ──────────────────────────────────────────────────────────────

test('owner court creation is blocked when subscription limit reached', function (): void {
    foreach (range(1, 3) as $i) {
        Court::create([
            'facility_id' => $this->facility->id,
            'tenant_id' => $this->tenant->id,
            'name' => "Court {$i}",
            'hourly_rate' => 300,
            'is_active' => true,
        ]);
    }

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/courts', [
        'facility_id' => $this->facility->id,
        'name' => 'Court 4',
        'hourly_rate' => 400,
    ]);

    $response->assertStatus(403);
});

test('owner court creation succeeds when under limit', function (): void {
    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/courts', [
        'facility_id' => $this->facility->id,
        'name' => 'Court 1',
        'hourly_rate' => 300,
    ]);

    $response->assertStatus(201);
});

// ──────────────────────────────────────────────────────────────
// Admin Plan CRUD
// ──────────────────────────────────────────────────────────────

test('admin can view a plan by slug', function (): void {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    $adminRole = Role::where('name', 'system_admin')->first();
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $admin->id,
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->getJson('/api/v1/admin/subscription-plans/starter');

    $response->assertOk()
        ->assertJsonPath('data.slug', 'starter')
        ->assertJsonPath('data.yearly_price', '9990.00');
});

test('admin cannot delete plan with active subscribers', function (): void {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin2@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    $adminRole = Role::where('name', 'system_admin')->first();
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $admin->id,
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->deleteJson('/api/v1/admin/subscription-plans/starter');

    $response->assertStatus(409);
});

test('admin can disable plan without active subscribers', function (): void {
    $emptyPlan = SubscriptionPlan::create([
        'name' => 'Legacy',
        'slug' => 'legacy',
        'price' => 0,
        'billing_interval' => 'monthly',
        'max_courts' => 1,
        'max_staff' => 1,
        'is_active' => true,
    ]);

    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin3@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    $adminRole = Role::where('name', 'system_admin')->first();
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $admin->id,
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    $token = $admin->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->deleteJson('/api/v1/admin/subscription-plans/legacy');

    $response->assertOk();
    expect($emptyPlan->fresh()->is_active)->toBeFalse();
});
