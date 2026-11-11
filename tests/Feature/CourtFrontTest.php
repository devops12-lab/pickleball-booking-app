<?php

use App\Models\CourtFront;
use App\Models\Role;
use App\Models\Tenant;
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

test('a facility has exactly one court front, created on first access', function (): void {
    $response = $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->getJson('/api/v1/owners/court-front');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.tenant_id', $this->tenant->id)
        ->assertJsonPath('data.is_open', true);

    // Repeated access never duplicates the row.
    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->getJson('/api/v1/owners/court-front')
        ->assertOk();

    expect(CourtFront::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('an owner can manage the court front details and contact information', function (): void {
    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->patchJson('/api/v1/owners/court-front', [
            'tagline' => 'Manila\'s home of pickleball',
            'description' => 'Six premium indoor courts with pro coaching.',
            'contact_email' => 'hello@metro.test',
            'contact_phone' => '+63 917 555 0100',
            'contact_address' => '123 Court Street, Manila',
            'website' => 'https://metropickleball.test',
            'facebook' => 'https://facebook.com/metropickleball',
            'is_open' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.tagline', 'Manila\'s home of pickleball')
        ->assertJsonPath('data.contact_phone', '+63 917 555 0100')
        ->assertJsonPath('data.is_open', false);

    expect(CourtFront::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('court front validation rejects bad contact details', function (): void {
    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->patchJson('/api/v1/owners/court-front', [
            'contact_email' => 'not-an-email',
            'website' => 'not-a-url',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_email', 'website']);
});

test('the owner can upload a logo and a cover image', function (): void {
    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->post('/api/v1/owners/court-front/logo', [
            'image' => UploadedFile::fake()->image('logo.png', 512, 512),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['logo_url']]);

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->post('/api/v1/owners/court-front/cover', [
            'image' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonStructure(['data' => ['cover_image_url']]);

    $front = CourtFront::query()->where('tenant_id', $this->tenant->id)->firstOrFail();

    Storage::disk(config('filesystems.upload_disk'))->assertExists($front->logo);
    Storage::disk(config('filesystems.upload_disk'))->assertExists($front->cover_image);

    // Replacing the logo deletes the old file and keeps a single row.
    $oldLogo = $front->logo;

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->post('/api/v1/owners/court-front/logo', [
            'image' => UploadedFile::fake()->image('logo2.png', 512, 512),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    Storage::disk(config('filesystems.upload_disk'))->assertMissing($oldLogo);
    expect(CourtFront::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('the public court front endpoint exposes the facility profile', function (): void {
    CourtFront::create([
        'tenant_id' => $this->tenant->id,
        'tagline' => 'Manila\'s home of pickleball',
        'contact_phone' => '+63 917 555 0100',
    ]);

    $this->getJson("/api/v1/public/tenants/{$this->tenant->id}/court-front")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.tagline', 'Manila\'s home of pickleball')
        ->assertJsonPath('data.contact_phone', '+63 917 555 0100');
});

test('the public court front returns 404 when the facility has none', function (): void {
    $this->getJson("/api/v1/public/tenants/{$this->tenant->id}/court-front")
        ->assertNotFound();
});

test('court front management writes audit entries', function (): void {
    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->patchJson('/api/v1/owners/court-front', ['tagline' => 'Play where the pros play'])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'court_front_updated',
        'entity_type' => CourtFront::class,
        'user_id' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->post('/api/v1/owners/court-front/logo', [
            'image' => UploadedFile::fake()->image('logo.png', 512, 512),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'court_front_logo_updated',
        'user_id' => $this->owner->id,
    ]);
});

test('staff and guests cannot manage the court front', function (): void {
    $this->getJson('/api/v1/owners/court-front')->assertUnauthorized();

    $staff = User::create([
        'name' => 'Sam Staff',
        'email' => 'sam@metro.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $staff->id,
        'role_id' => Role::where('name', 'staff')->firstOrFail()->id,
        'status' => 'active',
    ]);

    $this->actingAs($staff)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->patchJson('/api/v1/owners/court-front', ['tagline' => 'Hacked'])
        ->assertForbidden();

    $this->assertDatabaseCount('court_fronts', 0);
});
