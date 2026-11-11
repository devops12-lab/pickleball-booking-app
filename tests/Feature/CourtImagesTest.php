<?php

use App\Models\Court;
use App\Models\CourtImage;
use App\Models\Facility;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    $this->facility = Facility::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Metro Arena',
        'slug' => 'metro-arena',
        'address' => '123 Main St',
        'city' => 'Manila',
        'is_active' => true,
    ]);
});

/**
 * Build a court payload with N fake images.
 */
function courtPayloadWithImages(Facility $facility, int $count = 2): array
{
    return [
        'facility_id' => $facility->id,
        'name' => 'Court '.Str::random(4),
        'hourly_rate' => 350,
        'images' => array_map(
            fn (): UploadedFile => UploadedFile::fake()->image('court.jpg', 640, 480),
            range(1, $count)
        ),
    ];
}

function createCourt($testCase, int $imageCount = 2): Court
{
    $response = $testCase->actingAs($testCase->owner)
        ->withHeader('X-Tenant-ID', (string) $testCase->tenant->id)
        ->post('/api/v1/owners/courts', courtPayloadWithImages($testCase->facility, $imageCount), ['Accept' => 'application/json']);

    expect($response->status())->toBe(201);

    return Court::query()->latest('id')->firstOrFail();
}

// Images are optional on create here: the owner portal already creates courts
// without photos, so the gallery is filled in afterwards instead.
test('a court can be created without images and get them later', function (): void {
    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->postJson('/api/v1/owners/courts', [
            'facility_id' => $this->facility->id,
            'name' => 'No Photo Court',
            'hourly_rate' => 350,
        ])
        ->assertCreated();

    $court = Court::query()->latest('id')->firstOrFail();

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->post("/api/v1/owners/courts/{$court->id}/images", [
            'images' => [UploadedFile::fake()->image('first.jpg', 640, 480)],
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($court->images()->count())->toBe(1);
});

test('a court is created with its gallery images', function (): void {
    $court = createCourt($this, 3);

    expect($court->images()->count())->toBe(3)
        ->and($court->images()->first()->sort_order)->toBe(1)
        ->and(CourtImage::query()->where('court_id', $court->id)->orderByDesc('sort_order')->first()->sort_order)->toBe(3);

    Storage::disk(config('filesystems.upload_disk'))->assertExists($court->images()->first()->image_path);
});

test('more than five images on creation are rejected', function (): void {
    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->post('/api/v1/owners/courts', courtPayloadWithImages($this->facility, 6), ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('images');

    $this->assertDatabaseCount('courts', 0);
});

test('images can be added up to the limit of five per court', function (): void {
    $court = createCourt($this, 4);

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->post("/api/v1/owners/courts/{$court->id}/images", [
            'images' => [UploadedFile::fake()->image('extra.jpg', 640, 480)],
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    expect($court->images()->count())->toBe(5);

    // One more would exceed the limit.
    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->post("/api/v1/owners/courts/{$court->id}/images", [
            'images' => [UploadedFile::fake()->image('too-many.jpg', 640, 480)],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    expect($court->images()->count())->toBe(5);
});

test('a court cannot lose its last image', function (): void {
    $court = createCourt($this, 1);
    $image = $court->images()->firstOrFail();

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->deleteJson("/api/v1/owners/courts/{$court->id}/images/{$image->id}")
        ->assertUnprocessable();

    expect($court->images()->count())->toBe(1);
});

test('a court image can be deleted while at least one remains', function (): void {
    $court = createCourt($this, 2);
    $image = $court->images()->firstOrFail();
    $path = $image->image_path;

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->deleteJson("/api/v1/owners/courts/{$court->id}/images/{$image->id}")
        ->assertOk();

    $this->assertDatabaseMissing('court_images', ['id' => $image->id]);
    Storage::disk(config('filesystems.upload_disk'))->assertMissing($path);
    expect($court->images()->count())->toBe(1);
});

test('images can be reordered by supplying ids in the wanted order', function (): void {
    $court = createCourt($this, 3);

    $ids = $court->images()->orderByDesc('id')->pluck('id')->values()->all();

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->postJson("/api/v1/owners/courts/{$court->id}/images/reorder", ['image_ids' => $ids])
        ->assertOk();

    expect($court->images()->pluck('id')->values()->all())->toBe($ids);
});

test('the public court endpoints expose the gallery images', function (): void {
    $court = createCourt($this, 2);

    $this->getJson('/api/v1/public/courts/'.$court->id)
        ->assertOk()
        ->assertJsonCount(2, 'data.images')
        ->assertJsonStructure(['data' => ['images' => [['id', 'url', 'sort_order']]]]);
});

test('court image operations write audit entries', function (): void {
    $court = createCourt($this, 2);

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->post("/api/v1/owners/courts/{$court->id}/images", [
            'images' => [UploadedFile::fake()->image('third.jpg', 640, 480)],
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'court_images_added',
        'entity_id' => $court->id,
        'user_id' => $this->owner->id,
    ]);

    $image = $court->images()->firstOrFail();

    $this->actingAs($this->owner)
        ->withHeader('X-Tenant-ID', (string) $this->tenant->id)
        ->deleteJson("/api/v1/owners/courts/{$court->id}/images/{$image->id}")
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'court_image_deleted',
        'entity_id' => $court->id,
    ]);
});

test('another tenant cannot touch this court images', function (): void {
    $court = createCourt($this, 2);

    $otherTenant = Tenant::create([
        'name' => 'Sunset Pickleball Arena',
        'slug' => 'sunset-arena',
        'business_email' => 'sunset@courtflex.test',
        'status' => 'active',
    ]);

    $otherOwner = User::create([
        'name' => 'Owner Bob',
        'email' => 'bob@sunset.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);
    TenantUser::create([
        'tenant_id' => $otherTenant->id,
        'user_id' => $otherOwner->id,
        'role_id' => $this->ownerRole->id,
        'status' => 'active',
    ]);

    $this->actingAs($otherOwner)
        ->withHeader('X-Tenant-ID', (string) $otherTenant->id)
        ->post("/api/v1/owners/courts/{$court->id}/images", [
            'images' => [UploadedFile::fake()->image('intruder.jpg', 640, 480)],
        ], ['Accept' => 'application/json'])
        ->assertForbidden();

    $image = $court->images()->firstOrFail();

    $this->actingAs($otherOwner)
        ->withHeader('X-Tenant-ID', (string) $otherTenant->id)
        ->deleteJson("/api/v1/owners/courts/{$court->id}/images/{$image->id}")
        ->assertForbidden();

    expect(CourtImage::query()->where('court_id', $court->id)->count())->toBe(2);
});
