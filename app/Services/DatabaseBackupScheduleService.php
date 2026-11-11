<?php

namespace App\Services;

use App\Enums\BackupFrequency;
use App\Enums\DatabaseBackupAction;
use App\Models\DatabaseBackupSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Persists and calculates the daily / weekly / monthly backup schedules.
 *
 * Every value is stored in the database so administrators can change the
 * cadence at runtime without editing .env or the scheduler definition.
 */
class DatabaseBackupScheduleService
{
    private const DAYS = [
        'sunday' => 0,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
    ];

    public function __construct(
        protected DatabaseBackupAuditService $audit
    ) {}

    /**
     * Create the three scheduled backup rows the first time the system runs.
     *
     * Existing rows are never overwritten so administrator changes survive
     * deployments.
     *
     * @return Collection<int, DatabaseBackupSchedule>
     */
    public function ensureDefaults(?int $userId = null): Collection
    {
        foreach (BackupFrequency::cases() as $frequency) {
            if (DatabaseBackupSchedule::where('frequency', $frequency->value)->exists()) {
                continue;
            }

            $defaults = (array) config("database-backup.schedules.{$frequency->value}", []);
            $timezone = $this->timezone();

            $schedule = new DatabaseBackupSchedule([
                'name' => $frequency->label().' database backup',
                'frequency' => $frequency,
                'is_enabled' => (bool) ($defaults['enabled'] ?? true),
                'execution_time' => $this->normalizeTime((string) ($defaults['time'] ?? '01:00')),
                'timezone' => $timezone,
                'created_by' => $userId,
            ]);

            if ($frequency === BackupFrequency::Weekly) {
                $schedule->day_of_week = $this->normalizeDayOfWeek($defaults['day_of_week'] ?? 0);
                $schedule->retention_count = (int) ($defaults['retention'] ?? 4);
            } elseif ($frequency === BackupFrequency::Monthly) {
                $schedule->day_of_month = $this->normalizeDayOfMonth($defaults['day_of_month'] ?? 1);
                $schedule->retention_count = (int) ($defaults['retention'] ?? 12);
            } else {
                $schedule->retention_count = (int) ($defaults['retention'] ?? 7);
            }

            $schedule->next_run_at = $this->nextRunAt($schedule);
            $schedule->save();
        }

        return DatabaseBackupSchedule::query()->orderBy('id')->get();
    }

    /**
     * Calculate the next occurrence of a schedule in UTC.
     */
    public function nextRunAt(DatabaseBackupSchedule $schedule, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $timezone = $this->timezone();

        try {
            $local = ($from ?? CarbonImmutable::now())->setTimezone($timezone);
        } catch (InvalidArgumentException) {
            $local = ($from ?? CarbonImmutable::now())->utc();
        }

        [$hour, $minute] = $this->parseTime($schedule->execution_time);

        $candidate = match ($schedule->frequency) {
            BackupFrequency::Daily => $this->nextDaily($local, $hour, $minute),
            BackupFrequency::Weekly => $this->nextWeekly(
                $local,
                $hour,
                $minute,
                (int) ($schedule->day_of_week ?? 0)
            ),
            BackupFrequency::Monthly => $this->nextMonthly(
                $local,
                $hour,
                $minute,
                (int) ($schedule->day_of_month ?? 1)
            ),
        };

        return $candidate->utc();
    }

    /**
     * Schedules whose next run is in the past.
     *
     * @return Collection<int, DatabaseBackupSchedule>
     */
    public function dueSchedules(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();

        return DatabaseBackupSchedule::query()
            ->where('is_enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now->utc())
            ->orderBy('next_run_at')
            ->get();
    }

    /**
     * Record a successful dispatch and advance the next run.
     */
    public function markExecuted(DatabaseBackupSchedule $schedule, ?CarbonImmutable $now = null): DatabaseBackupSchedule
    {
        $now ??= CarbonImmutable::now();

        $schedule->forceFill([
            'last_run_at' => $now->utc(),
            'next_run_at' => $this->nextRunAt($schedule, $now->addSecond()),
        ])->save();

        return $schedule->refresh();
    }

    /**
     * Persist administrator changes to a schedule.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(DatabaseBackupSchedule $schedule, array $attributes, ?User $user = null): DatabaseBackupSchedule
    {
        $attributes = array_intersect_key($attributes, array_flip([
            'name',
            'is_enabled',
            'execution_time',
            'day_of_week',
            'day_of_month',
            'timezone',
            'retention_count',
        ]));

        if (array_key_exists('execution_time', $attributes)) {
            $attributes['execution_time'] = $this->normalizeTime((string) $attributes['execution_time']);
        }

        if (array_key_exists('day_of_week', $attributes)) {
            $attributes['day_of_week'] = $this->normalizeDayOfWeek($attributes['day_of_week']);
        }

        if (array_key_exists('day_of_month', $attributes)) {
            $attributes['day_of_month'] = $this->normalizeDayOfMonth($attributes['day_of_month']);
        }

        if (array_key_exists('timezone', $attributes)) {
            $attributes['timezone'] = $this->assertTimezone((string) $attributes['timezone']);
        }

        $schedule->fill($attributes);
        $schedule->next_run_at = $this->nextRunAt($schedule);
        $schedule->save();

        $this->audit->record(
            action: DatabaseBackupAction::ScheduleUpdated,
            user: $user,
            metadata: [
                'schedule_id' => $schedule->id,
                'frequency' => $schedule->frequency->value,
                'is_enabled' => $schedule->is_enabled,
                'execution_time' => $schedule->execution_time,
                'timezone' => $schedule->timezone,
                'retention_count' => $schedule->retention_count,
            ],
        );

        return $schedule->refresh();
    }

    /**
     * Earliest enabled next run, used by the monitoring endpoint.
     */
    public function nextScheduledBackup(): ?CarbonImmutable
    {
        $next = DatabaseBackupSchedule::query()
            ->where('is_enabled', true)
            ->whereNotNull('next_run_at')
            ->min('next_run_at');

        return $next !== null ? CarbonImmutable::parse($next) : null;
    }

    /**
     * Normalise a 0-6 or day-name value.
     */
    public function normalizeDayOfWeek(mixed $value): int
    {
        if (is_string($value) && ! is_numeric($value)) {
            $key = strtolower(trim($value));

            if (array_key_exists($key, self::DAYS)) {
                return self::DAYS[$key];
            }

            throw new InvalidArgumentException('The weekly backup day must be a day name or a number between 0 (Sunday) and 6 (Saturday).');
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('The weekly backup day must be a day name or a number between 0 (Sunday) and 6 (Saturday).');
        }

        $day = (int) $value;

        if ($day < 0 || $day > 6) {
            throw new InvalidArgumentException('The weekly backup day must be between 0 (Sunday) and 6 (Saturday).');
        }

        return $day;
    }

    public function normalizeDayOfMonth(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('The monthly backup day must be between 1 and 31.');
        }

        $day = (int) $value;

        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException('The monthly backup day must be between 1 and 31.');
        }

        return $day;
    }

    /**
     * HH:MM, 24 hour clock.
     */
    public function normalizeTime(string $time): string
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) !== 1) {
            throw new InvalidArgumentException('The execution time must use the HH:MM 24 hour format.');
        }

        return $time;
    }

    private function nextDaily(CarbonImmutable $local, int $hour, int $minute): CarbonImmutable
    {
        $candidate = $local->setTime($hour, $minute);

        return $candidate->lessThanOrEqualTo($local) ? $candidate->addDay() : $candidate;
    }

    private function nextWeekly(CarbonImmutable $local, int $hour, int $minute, int $dayOfWeek): CarbonImmutable
    {
        $daysAhead = ($dayOfWeek - $local->dayOfWeek + 7) % 7;

        $candidate = $local->startOfDay()
            ->addDays($daysAhead)
            ->setTime($hour, $minute);

        return $candidate->lessThanOrEqualTo($local) ? $candidate->addWeek() : $candidate;
    }

    private function nextMonthly(CarbonImmutable $local, int $hour, int $minute, int $dayOfMonth): CarbonImmutable
    {
        $candidate = $this->monthlyCandidate($local, $hour, $minute, $dayOfMonth);

        if ($candidate->greaterThan($local)) {
            return $candidate;
        }

        return $this->monthlyCandidate(
            $local->startOfMonth()->addMonthNoOverflow(),
            $hour,
            $minute,
            $dayOfMonth
        );
    }

    private function monthlyCandidate(CarbonImmutable $month, int $hour, int $minute, int $dayOfMonth): CarbonImmutable
    {
        $day = min($dayOfMonth, $month->daysInMonth);

        return $month->startOfMonth()
            ->addDays($day - 1)
            ->setTime($hour, $minute);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseTime(?string $time): array
    {
        $time = $time !== null && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) === 1
            ? $time
            : '01:00';

        [$hour, $minute] = explode(':', $time);

        return [(int) $hour, (int) $minute];
    }

    private function timezone(): string
    {
        return $this->assertTimezone((string) config('database-backup.timezone', 'UTC'));
    }

    private function assertTimezone(string $timezone): string
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('The configured backup timezone is not a valid timezone identifier.');
        }

        return $timezone;
    }
}
