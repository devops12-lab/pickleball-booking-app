<?php

use App\Models\Role;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ownerRole = Role::where('name', 'owner')->first();

    $this->starterPlan = SubscriptionPlan::create([
        'name' => 'Starter',
        'slug' => 'starter',
        'price' => 999.00,
        'yearly_price' => 9990.00,
        'billing_interval' => 'monthly',
        'max_courts' => 3,
        'max_staff' => 5,
        'features' => ['guest_booking'],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $this->proPlan = SubscriptionPlan::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'price' => 2499.00,
        'yearly_price' => 24990.00,
        'billing_interval' => 'monthly',
        'max_courts' => 10,
        'max_staff' => 20,
        'features' => ['guest_booking', 'reports', 'analytics'],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->tenant = Tenant::create([
        'name' => 'Billing Venue',
        'slug' => 'billing-venue',
        'business_email' => 'billing@courtflex.test',
        'status' => 'active',
    ]);

    $this->owner = User::create([
        'name' => 'Billing Owner',
        'email' => 'owner@billing.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);
});

// ──────────────────────────────────────────────────────────────
// Subscribe
// ──────────────────────────────────────────────────────────────

test('owner can subscribe to a plan and receives an invoice', function (): void {
    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/subscription/subscribe', [
        'plan_id' => $this->starterPlan->id,
        'billing_interval' => 'monthly',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['subscription', 'invoice']]);

    $this->assertDatabaseHas('tenant_subscriptions', [
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->starterPlan->id,
        'billing_interval' => 'monthly',
        'status' => 'pending',
    ]);

    $this->assertDatabaseHas('subscription_invoices', [
        'tenant_id' => $this->tenant->id,
        'amount' => '999.00',
        'status' => 'pending',
    ]);
});

test('owner cannot subscribe when already active', function (): void {
    TenantSubscription::create([
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->starterPlan->id,
        'billing_interval' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/subscription/subscribe', [
        'plan_id' => $this->proPlan->id,
        'billing_interval' => 'monthly',
    ]);

    $response->assertStatus(409);
});

// ──────────────────────────────────────────────────────────────
// Upgrade / Downgrade
// ──────────────────────────────────────────────────────────────

test('owner can upgrade to a higher plan immediately', function (): void {
    TenantSubscription::create([
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->starterPlan->id,
        'billing_interval' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);
    $this->tenant->update(['subscription_plan_id' => $this->starterPlan->id]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/subscription/upgrade', [
        'plan_id' => $this->proPlan->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('tenant_subscriptions', [
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->proPlan->id,
    ]);
});

test('owner cannot upgrade to a lower-priced plan', function (): void {
    TenantSubscription::create([
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->proPlan->id,
        'billing_interval' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);
    $this->tenant->update(['subscription_plan_id' => $this->proPlan->id]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/subscription/upgrade', [
        'plan_id' => $this->starterPlan->id,
    ]);

    $response->assertStatus(422);
});

test('owner can request a downgrade scheduled for period end', function (): void {
    TenantSubscription::create([
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->proPlan->id,
        'billing_interval' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);
    $this->tenant->update(['subscription_plan_id' => $this->proPlan->id]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/subscription/downgrade', [
        'plan_id' => $this->starterPlan->id,
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('tenant_subscriptions', [
        'tenant_id' => $this->tenant->id,
        'pending_plan_id' => $this->starterPlan->id,
    ]);
});

// ──────────────────────────────────────────────────────────────
// Cancel
// ──────────────────────────────────────────────────────────────

test('owner can cancel subscription with a reason', function (): void {
    TenantSubscription::create([
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->starterPlan->id,
        'billing_interval' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->postJson('/api/v1/owners/subscription/cancel', [
        'reason' => 'Too expensive',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('tenant_subscriptions', [
        'tenant_id' => $this->tenant->id,
        'status' => 'cancelled',
        'cancellation_reason' => 'Too expensive',
    ]);
});

// ──────────────────────────────────────────────────────────────
// Billing Interval Change
// ──────────────────────────────────────────────────────────────

test('owner can switch billing interval for next renewal', function (): void {
    TenantSubscription::create([
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->starterPlan->id,
        'billing_interval' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->putJson('/api/v1/owners/subscription/billing-interval', [
        'billing_interval' => 'yearly',
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('tenant_subscriptions', [
        'tenant_id' => $this->tenant->id,
        'pending_billing_interval' => 'yearly',
    ]);
});

// ──────────────────────────────────────────────────────────────
// View / Usage
// ──────────────────────────────────────────────────────────────

test('owner can view subscription and usage', function (): void {
    TenantSubscription::create([
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->starterPlan->id,
        'billing_interval' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->getJson('/api/v1/owners/subscription');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['subscription', 'usage']]);
});

test('owner can view invoices', function (): void {
    $sub = TenantSubscription::create([
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->starterPlan->id,
        'billing_interval' => 'monthly',
        'status' => 'active',
        'starts_at' => now(),
    ]);

    SubscriptionInvoice::create([
        'tenant_id' => $this->tenant->id,
        'tenant_subscription_id' => $sub->id,
        'invoice_number' => 'INV-TEST00001',
        'amount' => 999.00,
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    $token = $this->owner->createToken('test')->plainTextToken;

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-Tenant-ID' => (string) $this->tenant->id,
    ])->getJson('/api/v1/owners/subscription/invoices');

    $response->assertOk()
        ->assertJsonCount(1, 'data.data');
});

// ──────────────────────────────────────────────────────────────
// Scheduled Commands
// ──────────────────────────────────────────────────────────────

test('generate-renewals creates invoices for expiring subscriptions', function (): void {
    $sub = TenantSubscription::create([
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->starterPlan->id,
        'billing_interval' => 'monthly',
        'status' => 'active',
        'starts_at' => now()->subMonth(),
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDays(3),
    ]);

    $this->artisan('subscriptions:generate-renewals')->assertSuccessful();

    $this->assertDatabaseHas('subscription_invoices', [
        'tenant_subscription_id' => $sub->id,
        'amount' => '999.00',
        'status' => 'pending',
    ]);
});

test('process-expiration expires cancelled subscriptions past period end', function (): void {
    TenantSubscription::create([
        'tenant_id' => $this->tenant->id,
        'subscription_plan_id' => $this->starterPlan->id,
        'billing_interval' => 'monthly',
        'status' => 'cancelled',
        'starts_at' => now()->subMonths(2),
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subDay(),
        'cancelled_at' => now()->subWeek(),
    ]);

    $this->artisan('subscriptions:process-expiration')->assertSuccessful();

    $this->assertDatabaseHas('tenant_subscriptions', [
        'tenant_id' => $this->tenant->id,
        'status' => 'expired',
    ]);
});
