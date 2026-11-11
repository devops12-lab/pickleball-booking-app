<?php

use App\Models\PlayerProfile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::create([
        'name' => 'Security Tester',
        'email' => 'security@courtflex.test',
        'password' => Hash::make('OldPassword123!'),
        'account_status' => 'active',
    ]);
});

test('authenticated player can change password successfully', function (): void {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/players/auth/change-password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Password changed successfully.');

    expect(Hash::check('NewPassword123!', $this->user->fresh()->password))->toBeTrue();
});

test('password change is rejected when current password is wrong', function (): void {
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/players/auth/change-password', [
            'current_password' => 'WrongCurrentPassword!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['errors' => ['current_password']]);
});

test('player can revoke all active device tokens', function (): void {
    $this->user->createToken('device_1');
    $this->user->createToken('device_2');
    $this->user->createToken('device_3');

    expect($this->user->tokens()->count())->toBe(3);

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/players/auth/tokens/revoke-all');

    $response->assertOk()
        ->assertJsonPath('success', true);

    expect($this->user->tokens()->count())->toBe(0);
});

test('player can upload valid avatar image and updates profile', function (): void {
    $disk = config('filesystems.upload_disk', 'public');
    Storage::fake($disk);

    $file = UploadedFile::fake()->image('profile.png', 300, 300);

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/players/profile/avatar', [
            'avatar' => $file,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $this->user->id);

    expect($this->user->fresh()->avatar)->not->toBeNull();
});

test('player can choose and clear a default avatar style', function (): void {
    $profile = [
        'display_name' => 'Security Tester',
        'skill_level' => 'intermediate',
        'bio' => 'Testing avatars.',
        'play_formats' => ['doubles'],
        'preferred_times' => ['evening'],
        'visibility' => 'public',
    ];

    $this->actingAs($this->user)
        ->patchJson('/api/v1/player/profile', [...$profile, 'avatar_style' => 'male'])
        ->assertOk()
        ->assertJsonPath('data.avatar_style', 'male')
        ->assertJsonPath('data.avatar_url', null);

    expect(PlayerProfile::where('user_id', $this->user->id)->value('avatar_style'))->toBe('male');

    $this->actingAs($this->user)
        ->patchJson('/api/v1/player/profile', [...$profile, 'avatar_style' => null])
        ->assertOk()
        ->assertJsonPath('data.avatar_style', null);

    expect(PlayerProfile::where('user_id', $this->user->id)->value('avatar_style'))->toBeNull();
});

test('player can remove their uploaded avatar', function (): void {
    $disk = config('filesystems.upload_disk', 'public');
    Storage::fake($disk);

    $this->actingAs($this->user)
        ->postJson('/api/v1/players/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('profile.png', 300, 300),
        ])
        ->assertOk();

    expect($this->user->fresh()->avatar)->not->toBeNull();

    $this->actingAs($this->user)
        ->deleteJson('/api/v1/players/profile/avatar')
        ->assertOk()
        ->assertJsonPath('data.avatar', null);

    expect($this->user->fresh()->avatar)->toBeNull();
});

test('avatar upload rejects invalid mime types and malicious extensions', function (): void {
    $disk = config('filesystems.upload_disk', 'public');
    Storage::fake($disk);

    $file = UploadedFile::fake()->create('malicious.sh', 50, 'text/x-shellscript');

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/players/profile/avatar', [
            'avatar' => $file,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['errors' => ['avatar']]);
});
