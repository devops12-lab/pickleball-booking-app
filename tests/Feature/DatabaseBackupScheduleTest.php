<?php

use App\Enums\BackupFrequency;
use App\Jobs\CreateDatabaseBackupJob;
use App\Models\DatabaseBackupSchedule;
use App\Services\DatabaseBackupScheduleService;
use App\Services\DatabaseBackupService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlatformAdminSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlatformAdminSeeder::class);

    $this->admin = databaseBackupAdmin();
    $this->schedules = app(DatabaseBackupScheduleService::class);
});

test('the default schedules are created once with their retention defaults', function (): void {
    $schedules = $this->schedules->ensureDefaults();

    expect($schedules)->toHaveCount(3);

    $daily = $schedules->first(fn (DatabaseBackupSchedule $schedule): bool => $schedule->frequency === BackupFrequency::Daily);
    $weekly = $schedules->first(fn (DatabaseBackupSchedule $schedule): bool => $schedule->frequency === BackupFrequency::Weekly);
    $monthly = $schedules->first(fn (DatabaseBackupSchedule $schedule): bool => $schedule->frequency === BackupFrequency::Monthly);

    expect($daily->retention_count)->toBe(7)
        ->and($weekly->retention_count)->toBe(4)
        ->and($monthly->retention_count)->toBe(12);

    $this->schedules->ensureDefaults();

    $this->assertDatabaseCount('database_backup_schedules', 3);
});

test('the default enabled flag comes from configuration', function (): void {
    config(['database-backup.schedules.daily.enabled' => false]);

    $schedules = $this->schedules->ensureDefaults();

    $daily = $schedules->first(fn (DatabaseBackupSchedule $schedule): bool => $schedule->frequency === BackupFrequency::Daily);

    expect($daily->is_enabled)->toBeFalse();
});

test('a daily schedule advances to the next day once the time has passed', function (): void {
    $schedule = new DatabaseBackupSchedule([
        'frequency' => BackupFrequency::Daily,
        'execution_time' => '03:30',
    ]);

    $before = CarbonImmutable::parse('2026-03-10 01:00:00', 'UTC');
    $after = CarbonImmutable::parse('2026-03-10 04:00:00', 'UTC');

    expect($this->schedules->nextRunAt($schedule, $before)->toIso8601String())->toBe('2026-03-10T03:30:00+00:00')
        ->and($this->schedules->nextRunAt($schedule, $after)->toIso8601String())->toBe('2026-03-11T03:30:00+00:00');
});

test('a weekly schedule targets the configured weekday', function (): void {
    $schedule = new DatabaseBackupSchedule([
        'frequency' => BackupFrequency::Weekly,
        'execution_time' => '02:00',
        'day_of_week' => 1,
    ]);

    $from = CarbonImmutable::parse('2026-03-11 12:00:00', 'UTC');
    $next = $this->schedules->nextRunAt($schedule, $from);

    expect($next->dayOfWeek)->toBe(1)
        ->and($next->format('H:i'))->toBe('02:00')
        ->and($next->greaterThan($from))->toBeTrue();
});

test('a monthly schedule clamps to the last day of a shorter month', function (): void {
    $schedule = new DatabaseBackupSchedule([
        'frequency' => BackupFrequency::Monthly,
        'execution_time' => '00:00',
        'day_of_month' => 31,
    ]);

    $from = CarbonImmutable::parse('2026-02-01 00:00:00', 'UTC');

    expect($this->schedules->nextRunAt($schedule, $from)->toDateString())->toBe('2026-02-28');
});

test('the schedule times are evaluated in the configured timezone', function (): void {
    config(['database-backup.timezone' => 'Africa/Lagos']);

    $schedule = new DatabaseBackupSchedule([
        'frequency' => BackupFrequency::Daily,
        'execution_time' => '03:30',
    ]);

    $from = CarbonImmutable::parse('2026-03-10 00:00:00', 'UTC');

    expect($this->schedules->nextRunAt($schedule, $from)->toIso8601String())->toBe('2026-03-10T02:30:00+00:00');
});

test('weekday names are normalised to their numeric value', function (): void {
    expect($this->schedules->normalizeDayOfWeek('Monday'))->toBe(1)
        ->and($this->schedules->normalizeDayOfWeek('sunday'))->toBe(0)
        ->and($this->schedules->normalizeDayOfWeek('6'))->toBe(6);
});

test('invalid weekday and monthly day values are rejected', function (): void {
    expect(fn () => $this->schedules->normalizeDayOfWeek(9))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->schedules->normalizeDayOfWeek('funday'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->schedules->normalizeDayOfMonth(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->schedules->normalizeTime('25:00'))->toThrow(InvalidArgumentException::class);
});

test('only enabled schedules in the past are due', function (): void {
    $this->schedules->ensureDefaults();

    DatabaseBackupSchedule::query()->update(['next_run_at' => now()->addDay()]);

    $daily = DatabaseBackupSchedule::query()->where('frequency', 'daily')->firstOrFail();
    $weekly = DatabaseBackupSchedule::query()->where('frequency', 'weekly')->firstOrFail();

    $daily->forceFill(['next_run_at' => now()->subMinute(), 'is_enabled' => true])->save();
    $weekly->forceFill(['next_run_at' => now()->subMinute(), 'is_enabled' => false])->save();

    $due = $this->schedules->dueSchedules();

    expect($due)->toHaveCount(1)
        ->and($due->first()->frequency)->toBe(BackupFrequency::Daily);
});

test('an administrator can update a schedule', function (): void {
    $this->schedules->ensureDefaults();

    $schedule = DatabaseBackupSchedule::query()->where('frequency', 'weekly')->firstOrFail();

    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/backup-schedules/'.$schedule->id, [
            'execution_time' => '05:45',
            'day_of_week' => 'Friday',
            'is_enabled' => false,
            'retention_count' => 20,
        ])
        ->assertOk()
        ->assertJsonPath('data.execution_time', '05:45')
        ->assertJsonPath('data.day_of_week', 5)
        ->assertJsonPath('data.is_enabled', false)
        ->assertJsonPath('data.retention_count', 20);

    expect($schedule->fresh()->is_enabled)->toBeFalse();
});

test('an invalid schedule update is rejected', function (): void {
    $this->schedules->ensureDefaults();

    $schedule = DatabaseBackupSchedule::query()->where('frequency', 'weekly')->firstOrFail();

    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/backup-schedules/'.$schedule->id, ['execution_time' => '25:99'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('execution_time');

    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/backup-schedules/'.$schedule->id, ['day_of_week' => 9])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('day_of_week');

    $this->actingAs($this->admin)
        ->patchJson('/api/v1/admin/backup-schedules/'.$schedule->id, ['retention_count' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('retention_count');
});

test('the schedule command dispatches every due backup', function (): void {
    Queue::fake();

    $this->schedules->ensureDefaults();

    DatabaseBackupSchedule::query()->update(['next_run_at' => now()->addDay()]);

    $daily = DatabaseBackupSchedule::query()->where('frequency', 'daily')->firstOrFail();
    $daily->forceFill(['next_run_at' => now()->subMinute(), 'is_enabled' => true])->save();

    $this->mock(DatabaseBackupService::class, function ($mock): void {
        $mock->shouldReceive('createRecord')
            ->once()
            ->andReturnUsing(fn () => makeDatabaseBackup(['backup_type' => 'daily']));
    });

    $this->artisan('database:backup-schedule-run')->assertSuccessful();

    Queue::assertPushed(CreateDatabaseBackupJob::class, 1);

    $daily->refresh();

    expect($daily->last_run_at)->not->toBeNull()
        ->and($daily->next_run_at->isFuture())->toBeTrue();
});

test('the schedule command does nothing when backups are disabled', function (): void {
    Queue::fake();

    config(['database-backup.enabled' => false]);

    $this->schedules->ensureDefaults();

    $this->mock(DatabaseBackupService::class, function ($mock): void {
        $mock->shouldNotReceive('createRecord');
    });

    $this->artisan('database:backup-schedule-run')->assertSuccessful();

    Queue::assertNothingPushed();
});
