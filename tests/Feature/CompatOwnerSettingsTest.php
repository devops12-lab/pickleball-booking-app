<?php

use App\Models\Court;
use App\Models\CourtImage;
use App\Models\Facility;
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

/**
 * The owner portal reads and writes through the compat contract
 * (/api/v1/clubs/{club}/...), so the new photo and payment-channel screens
 * are exercised here rather than only through the tenant-scoped API.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    Storage::fake(config('filesystems.upload_disk'));

    $this->ownerRole = Role::where('name', 'owner')->firstOrFail();

    $this->club = Tenant::create([
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
        'tenant_id' => $this->club->id,
        'user_id' => $this->owner->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);

    $facility = Facility::create([
        'tenant_id' => $this->club->id,
        'name' => 'Metro Arena',
        'slug' => 'metro-arena',
        'address' => '123 Main St',
        'city' => 'Manila',
        'is_active' => true,
    ]);

    $this->court = Court::create([
        'tenant_id' => $this->club->id,
        'facility_id' => $facility->id,
        'name' => 'Court 1',
        'court_type' => 'indoor',
        'surface_type' => 'acacia',
        'hourly_rate' => 350,
        'is_active' => true,
    ]);
});

function clubImageFixture($testCase, int $count = 2): void
{
    for ($i = 1; $i <= $count; $i++) {
        CourtImage::create([
            'court_id' => $testCase->court->id,
            'image_path' => "courts/court-{$i}.jpg",
            'sort_order' => $i,
        ]);
    }
}

test('photos can be uploaded, reordered and removed from a court', function (): void {
    $this->actingAs($this->owner)
        ->post("/api/v1/clubs/{$this->club->id}/courts/{$this->court->id}/images", [
            'images' => [
                UploadedFile::fake()->image('front.jpg', 640, 480),
                UploadedFile::fake()->image('side.jpg', 640, 480),
            ],
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonCount(2, 'data.images')
        ->assertJsonPath('data.images.0.sort_order', 1);

    $ids = $this->court->images()->pluck('id')->all();

    $this->actingAs($this->owner)
        ->postJson("/api/v1/clubs/{$this->club->id}/courts/{$this->court->id}/images/reorder", [
            'image_ids' => array_reverse($ids),
        ])
        ->assertOk()
        ->assertJsonPath('data.images.0.id', (string) end($ids));

    $this->actingAs($this->owner)
        ->deleteJson("/api/v1/clubs/{$this->club->id}/courts/{$this->court->id}/images/{$ids[0]}")
        ->assertOk()
        ->assertJsonCount(1, 'data.images');
});

test('the last photo on a court cannot be removed', function (): void {
    clubImageFixture($this, 1);

    $this->actingAs($this->owner)
        ->deleteJson("/api/v1/clubs/{$this->club->id}/courts/{$this->court->id}/images/{$this->court->images()->first()->id}")
        ->assertUnprocessable();
});

test('the court listing carries its photos for the portal gallery', function (): void {
    clubImageFixture($this, 2);

    $this->actingAs($this->owner)
        ->getJson("/api/v1/clubs/{$this->club->id}/courts")
        ->assertOk()
        ->assertJsonCount(2, 'data.0.images');
});

test('an owner can manage the club payment channels through the portal contract', function (): void {
    $created = $this->actingAs($this->owner)
        ->postJson("/api/v1/clubs/{$this->club->id}/payment-methods", [
            'name' => 'GCash - 09171234567',
            'type' => 'gcash',
            'account_name' => 'Metro Pickleball',
            'account_number' => '09171234567',
        ])
        ->assertCreated()
        ->assertJsonPath('data.type_label', 'GCash')
        ->json('data');

    $this->actingAs($this->owner)
        ->getJson("/api/v1/clubs/{$this->club->id}/payment-methods")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/clubs/{$this->club->id}/payment-methods/{$created['id']}", [
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->actingAs($this->owner)
        ->deleteJson("/api/v1/clubs/{$this->club->id}/payment-methods/{$created['id']}")
        ->assertOk();

    expect(TenantPaymentMethod::query()->count())->toBe(0);
});

test('a payment channel with an unsupported type is rejected', function (): void {
    $this->actingAs($this->owner)
        ->postJson("/api/v1/clubs/{$this->club->id}/payment-methods", [
            'name' => 'Crypto',
            'type' => 'crypto',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

test('a non member cannot read or change the club payment channels', function (): void {
    $stranger = User::create([
        'name' => 'Nosy Player',
        'email' => 'nosy@example.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->actingAs($stranger)
        ->getJson("/api/v1/clubs/{$this->club->id}/payment-methods")
        ->assertForbidden();

    $this->actingAs($stranger)
        ->postJson("/api/v1/clubs/{$this->club->id}/payment-methods", [
            'name' => 'Cash',
            'type' => 'cash',
        ])
        ->assertForbidden();
});
