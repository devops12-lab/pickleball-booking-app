<?php

use App\Models\Booking;
use App\Models\Court;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->tenant = Tenant::create([
        'name' => 'Upload Test Tenant',
        'slug' => 'upload-test-tenant',
        'business_email' => 'upload@courtflex.test',
        'status' => 'active',
    ]);

    $this->facility = Facility::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Facility Upload',
        'slug' => 'facility-upload',
        'address' => '789 Upload Way',
        'city' => 'Austin',
        'is_active' => true,
    ]);

    $this->court = Court::create([
        'facility_id' => $this->facility->id,
        'tenant_id' => $this->tenant->id,
        'name' => 'Court Upload',
        'hourly_rate' => 250.00,
        'is_active' => true,
    ]);

    $this->player1 = User::create([
        'name' => 'Player One',
        'email' => 'p1@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->player2 = User::create([
        'name' => 'Player Two',
        'email' => 'p2@courtflex.test',
        'password' => Hash::make('Password123!'),
        'account_status' => 'active',
    ]);

    $this->booking1 = Booking::create([
        'tenant_id' => $this->tenant->id,
        'court_id' => $this->court->id,
        'user_id' => $this->player1->id,
        'booking_reference' => 'CF-UPLOAD1',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
        'total_amount' => 250.00,
        'status' => 'pending',
    ]);

    $this->payment1 = Payment::create([
        'tenant_id' => $this->tenant->id,
        'booking_id' => $this->booking1->id,
        'user_id' => $this->player1->id,
        'amount' => 250.00,
        'currency' => 'PHP',
        'payment_method' => 'bank_transfer',
        'transaction_reference' => 'TXN-INIT',
        'status' => 'pending',
    ]);
});

test('player can upload valid payment receipt proof', function (): void {
    $disk = config('filesystems.upload_disk', 'public');
    Storage::fake($disk);

    $proofFile = UploadedFile::fake()->create('receipt.pdf', 300, 'application/pdf');

    $response = $this->actingAs($this->player1)
        ->postJson("/api/v1/players/payments/{$this->payment1->id}/proof", [
            'proof' => $proofFile,
            'transaction_reference' => 'TXN-BANK-999',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.transaction_reference', 'TXN-BANK-999');

    expect($this->payment1->fresh()->proof_attachment)->not->toBeNull();
});

test('player cannot upload proof for another user payment (IDOR protection)', function (): void {
    $disk = config('filesystems.upload_disk', 'public');
    Storage::fake($disk);

    $proofFile = UploadedFile::fake()->create('receipt.png', 100, 'image/png');

    $response = $this->actingAs($this->player2)
        ->postJson("/api/v1/players/payments/{$this->payment1->id}/proof", [
            'proof' => $proofFile,
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('success', false);
});

test('payment proof upload rejects executable files', function (): void {
    $disk = config('filesystems.upload_disk', 'public');
    Storage::fake($disk);

    $fakeExe = UploadedFile::fake()->create('exploit.exe', 50, 'application/x-msdownload');

    $response = $this->actingAs($this->player1)
        ->postJson("/api/v1/players/payments/{$this->payment1->id}/proof", [
            'proof' => $fakeExe,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['errors' => ['proof']]);
});

test('upload disk is configurable to s3 via environment configuration', function (): void {
    Config::set('filesystems.upload_disk', 's3');
    Storage::fake('s3');

    $proofFile = UploadedFile::fake()->create('receipt-s3.png', 150, 'image/png');

    $response = $this->actingAs($this->player1)
        ->postJson("/api/v1/players/payments/{$this->payment1->id}/proof", [
            'proof' => $proofFile,
            'transaction_reference' => 'TXN-S3-TEST',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $savedPayment = $this->payment1->fresh();
    expect($savedPayment->proof_attachment)->not->toBeNull();

    // Verify file actually landed on the fake s3 disk
    $allFiles = Storage::disk('s3')->allFiles('receipts');
    expect(count($allFiles))->toBe(1);
});

test('upload disk is configurable to cloudflare r2 via environment configuration', function (): void {
    Config::set('filesystems.upload_disk', 'r2');
    Storage::fake('r2');

    $avatarFile = UploadedFile::fake()->image('avatar-r2.webp', 200, 200);

    $response = $this->actingAs($this->player1)
        ->postJson('/api/v1/players/profile/avatar', [
            'avatar' => $avatarFile,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $updatedUser = $this->player1->fresh();
    expect($updatedUser->avatar)->not->toBeNull();

    // Verify avatar landed on the fake r2 disk
    $allAvatars = Storage::disk('r2')->allFiles('avatars');
    expect(count($allAvatars))->toBe(1);
});
