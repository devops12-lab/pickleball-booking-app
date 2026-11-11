<?php

use App\Models\Payment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantPaymentMethod;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    Storage::fake(config('filesystems.upload_disk'));

    $this->ownerRole = Role::where('name', 'owner')->firstOrFail();

    $this->tenant = Tenant::create([
        'name' => 'Metro Pickleball Club',
        'slug' => 'metro-pickleball',
        'business_email' => 'metro@courtflex.test',
        'status' => 'active',
    ]);

    $this->owner = User::create([
        'name' => 'Owner Alice',
        'email' => 'alice@metro.test',
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

function createTenantPaymentMethod($testCase, array $overrides = []): TenantPaymentMethod
{
    return TenantPaymentMethod::create(array_merge([
        'tenant_id' => $testCase->tenant->id,
        'name' => 'GCash - 09171234567',
        'type' => 'gcash',
        'account_name' => 'Metro Pickleball',
        'account_number' => '09171234567',
        'instructions' => 'Send then screenshot the receipt.',
        'is_active' => true,
    ], $overrides));
}

test('an owner can create payment methods like cash, gcash and qrph', function (): void {
    foreach (['cash', 'gcash', 'maya', 'qrph', 'bank_transfer'] as $index => $type) {
        $this->actingAs($this->owner)
            ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->postJson('/api/v1/owners/payment-methods', [
                'name' => 'Method '.$type,
                'type' => $type,
                'account_name' => 'Metro Pickleball',
                'account_number' => '0917'.$index,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tenant_id', $this->tenant->id)
            ->assertJsonPath('data.is_active', true);
    }

    expect(TenantPaymentMethod::query()->count())->toBe(5);
});

test('an owner can attach a QR image to a payment method', function (): void {
    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->post('/api/v1/owners/payment-methods', [
            'name' => 'GCash QR',
            'type' => 'gcash',
            'qr_image' => UploadedFile::fake()->image('qr.png', 400, 400),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['qr_image_url']]);

    Storage::disk(config('filesystems.upload_disk'))->assertExists(TenantPaymentMethod::query()->firstOrFail()->qr_image);
});

test('payment method types are validated', function (): void {
    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->postJson('/api/v1/owners/payment-methods', [
            'name' => 'PayPal',
            'type' => 'paypal',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');

    $this->assertDatabaseCount('tenant_payment_methods', 0);
});

test('an owner sees only the payment methods of their own tenant', function (): void {
    createTenantPaymentMethod($this);

    $otherTenant = Tenant::create([
        'name' => 'Sunset Pickleball Arena',
        'slug' => 'sunset-arena',
        'business_email' => 'sunset@courtflex.test',
        'status' => 'active',
    ]);

    TenantPaymentMethod::create([
        'tenant_id' => $otherTenant->id,
        'name' => 'Maya - Sunset',
        'type' => 'maya',
    ]);

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->getJson('/api/v1/owners/payment-methods')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'GCash - 09171234567');
});

test('cross-tenant payment method access is forbidden with a diagnosable error', function (): void {
    $foreign = TenantPaymentMethod::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Cash',
        'type' => 'cash',
    ]);

    $otherOwner = User::create([
        'name' => 'Owner Bob',
        'email' => 'bob@sunset.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    $otherTenant = Tenant::create([
        'name' => 'Sunset Pickleball Arena',
        'slug' => 'sunset-arena',
        'business_email' => 'sunset@courtflex.test',
        'status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $otherTenant->id,
        'user_id' => $otherOwner->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);

    $response = $this->actingAs($otherOwner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->getJson("/api/v1/owners/payment-methods/{$foreign->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have administrative or staff access to this facility tenant.');

    // The payload points the caller at the mismatch so stale tenant ids are obvious.
    expect($response->json('errors.requested_tenant_id'))->toBe($this->tenant->id)
        ->and($response->json('errors.member_tenant_ids'))->toBe([$otherTenant->id]);

    $this->actingAs($otherOwner)
        ->withHeader('X-Tenant-ID', (string) $otherTenant->id)
        ->deleteJson("/api/v1/owners/payment-methods/{$foreign->id}")
        ->assertForbidden();

    expect(TenantPaymentMethod::query()->count())->toBe(1);
});

test('payment method management writes audit entries', function (): void {
    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->postJson('/api/v1/owners/payment-methods', [
            'name' => 'Cash',
            'type' => 'cash',
        ])
        ->assertCreated();

    $method = TenantPaymentMethod::query()->firstOrFail();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'payment_method_created',
        'entity_type' => TenantPaymentMethod::class,
        'entity_id' => $method->id,
        'user_id' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->patchJson("/api/v1/owners/payment-methods/{$method->id}", ['is_active' => false])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'payment_method_updated',
        'entity_id' => $method->id,
    ]);

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->deleteJson("/api/v1/owners/payment-methods/{$method->id}")
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'payment_method_deleted',
        'user_id' => $this->owner->id,
    ]);
});

test('players see only active payment methods of a facility', function (): void {
    createTenantPaymentMethod($this);
    createTenantPaymentMethod($this, ['name' => 'Cash on site', 'type' => 'cash', 'is_active' => false]);

    $player = User::create([
        'name' => 'Pia Player',
        'email' => 'pia@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->actingAs($player)
        ->getJson("/api/v1/players/tenants/{$this->tenant->id}/payment-methods")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'gcash')
        ->assertJsonPath('data.0.account_number', '09171234567');
});

test('payment proof must use an accepted facility payment method', function (): void {
    createTenantPaymentMethod($this);

    $player = User::create([
        'name' => 'Pia Player',
        'email' => 'pia@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $player->id,
        'amount' => 700.00,
        'payment_method' => 'unknown',
        'transaction_reference' => 'CF-TEST0001',
        'status' => 'pending',
    ]);

    // Reject a method the facility never configured.
    $this->actingAs($player)
        ->post("/api/v1/players/payments/{$payment->id}/proof", [
            'proof' => UploadedFile::fake()->image('receipt.jpg', 400, 600),
            'payment_method' => 'PayPal',
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_method');

    // Accept a configured channel and record it on the payment.
    $this->actingAs($player)
        ->post("/api/v1/players/payments/{$payment->id}/proof", [
            'proof' => UploadedFile::fake()->image('receipt.jpg', 400, 600),
            'payment_method' => 'GCash - 09171234567',
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.payment_method', 'GCash - 09171234567');

    expect($payment->fresh()->payment_method)->toBe('GCash - 09171234567');
});

test('players cannot manage facility payment methods', function (): void {
    $player = User::create([
        'name' => 'Pia Player',
        'email' => 'pia@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->actingAs($player)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->postJson('/api/v1/owners/payment-methods', ['name' => 'Hack', 'type' => 'cash'])
        ->assertForbidden();

    $this->assertDatabaseCount('tenant_payment_methods', 0);
});
