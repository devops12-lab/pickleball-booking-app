<?php

use App\Enums\StaffPosition;
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

    $this->ownerRole = Role::where('name', 'owner')->firstOrFail();
    $this->staffRole = Role::where('name', 'staff')->firstOrFail();

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

function createStaffViaApi($testCase, array $overrides = [])
{
    return $testCase->actingAs($testCase->owner)
        ->withHeader('X-Tenant-ID', (string) $testCase->tenant->id)
        ->postJson('/api/v1/owners/staff', array_merge([
            'name' => 'Nina Newstaff',
            'email' => 'nina@metro.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'position' => 'front_desk',
        ], $overrides));
}

test('an owner can create a staff account with full credentials', function (): void {
    $response = createStaffViaApi($this);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.position', 'front_desk')
        ->assertJsonPath('data.position_label', 'Front Desk')
        ->assertJsonPath('data.user.email', 'nina@metro.test');

    // The password is never echoed back.
    expect(json_encode($response->json()))->not->toContain('Password123!');

    $staffUser = User::where('email', 'nina@metro.test')->firstOrFail();

    expect($staffUser->account_status)->toBe('active')
        ->and($staffUser->email_verified_at)->not->toBeNull()
        ->and(Hash::check('Password123!', $staffUser->password))->toBeTrue();

    $this->assertDatabaseHas('tenant_users', [
        'tenant_id' => $this->tenant->id,
        'user_id' => $staffUser->id,
        'role_id' => $this->staffRole->id,
        'position' => 'front_desk',
        'status' => 'active',
    ]);

    // The new staff member can log in immediately.
    $this->postJson('/api/v1/staff/auth/login', [
        'email' => 'nina@metro.test',
        'password' => 'Password123!',
        'tenant_id' => $this->tenant->id,
    ])->assertOk()->assertJsonPath('data.user.active_role', 'staff');
});

test('creating staff with an existing email is rejected', function (): void {
    $this->postJson('/api/v1/players/auth/register', [
        'name' => 'Pia Player',
        'email' => 'taken@courtflex.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertCreated();

    createStaffViaApi($this, ['email' => 'taken@courtflex.test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    // The player account was not linked into the facility.
    $user = User::where('email', 'taken@courtflex.test')->firstOrFail();
    expect($user->tenantUsers()->count())->toBe(0);
});

test('staff creation validates credentials and position', function (): void {
    createStaffViaApi($this, ['password' => 'short'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    createStaffViaApi($this, ['position' => 'janitor'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('position');

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('tenant_users', 1);
});

test('the staff list exposes positions and roles', function (): void {
    createStaffViaApi($this, ['email' => 'nina@metro.test', 'position' => 'manager']);

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->getJson('/api/v1/owners/staff')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.position', null)
        ->assertJsonPath('data.1.position', 'manager')
        ->assertJsonPath('data.1.user.email', 'nina@metro.test');
});

test('an owner can change a staff position and suspend the member', function (): void {
    createStaffViaApi($this);
    $staffUser = User::where('email', 'nina@metro.test')->firstOrFail();

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->patchJson("/api/v1/owners/staff/{$staffUser->id}", ['position' => 'scorer'])
        ->assertOk()
        ->assertJsonPath('data.position', 'scorer');

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->patchJson("/api/v1/owners/staff/{$staffUser->id}", ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    expect($staffUser->fresh()->tenantUsers()->first()->status)->toBe('inactive');
});

test('removing staff deletes only the facility assignment', function (): void {
    createStaffViaApi($this);
    $staffUser = User::where('email', 'nina@metro.test')->firstOrFail();

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->deleteJson("/api/v1/owners/staff/{$staffUser->id}")
        ->assertOk();

    $this->assertDatabaseMissing('tenant_users', ['user_id' => $staffUser->id, 'tenant_id' => $this->tenant->id]);
    $this->assertDatabaseHas('users', ['id' => $staffUser->id]);
});

test('staff positions map to different permission sets', function (): void {
    $positions = [
        'manager' => ['payments.verify' => true, 'bookings.manage' => true, 'scores.submit' => true, 'bookings.check_in' => true],
        'front_desk' => ['payments.verify' => true, 'bookings.manage' => true, 'scores.submit' => false, 'bookings.check_in' => true],
        'court_attendant' => ['payments.verify' => false, 'bookings.manage' => false, 'scores.submit' => false, 'bookings.check_in' => true],
        'scorer' => ['payments.verify' => false, 'bookings.manage' => false, 'scores.submit' => true, 'bookings.check_in' => false],
    ];

    foreach ($positions as $position => $expectations) {
        $staffUser = User::create([
            'name' => ucfirst($position).' Sam',
            'email' => "{$position}@metro.test",
            'password' => Hash::make('Password123!'),
            'account_status' => 'active',
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $staffUser->id,
            'role_id' => $this->staffRole->id,
            'position' => $position,
            'status' => 'active',
        ]);

        foreach ($expectations as $permission => $expected) {
            expect($staffUser->hasPermission($permission, $this->tenant->id))->toBe($expected);
        }
    }

    // Legacy staff without a position keep the role baseline.
    $legacy = User::create([
        'name' => 'Legacy Larry',
        'email' => 'legacy@metro.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $legacy->id,
        'role_id' => $this->staffRole->id,
        'status' => 'active',
    ]);

    expect($legacy->hasPermission('payments.verify', $this->tenant->id))->toBeTrue()
        ->and($legacy->hasPermission('scores.submit', $this->tenant->id))->toBeTrue();
});

test('the staff position enum carries the four facility roles', function (): void {
    expect(StaffPosition::values())->toBe(['manager', 'front_desk', 'court_attendant', 'scorer']);
});

test('the invitation endpoint is retired', function (): void {
    $this->postJson('/api/v1/staff/auth/accept-invitation', ['token' => 'anything'])
        ->assertNotFound();
});

test('players cannot manage staff', function (): void {
    $player = User::create([
        'name' => 'Plain Player',
        'email' => 'plain@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->actingAs($player)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->postJson('/api/v1/owners/staff', [
            'name' => 'x', 'email' => 'x@metro.test', 'password' => 'Password123!',
        ])
        ->assertForbidden();

    $this->actingAs($player)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->getJson('/api/v1/owners/staff')
        ->assertForbidden();

    $this->assertDatabaseCount('users', 2);
});
