---
title: Database backup & restore
category: Operations
status: Implemented
order: 11
summary: Manual, scheduled and uploaded database backups with native MySQL, PostgreSQL and SQLite tooling, guarded restore, retention cleanup and audit logging.
sources:
  - config/database-backup.php
  - app/Services/DatabaseBackupService.php
  - app/Services/DatabaseRestoreService.php
  - app/Services/DatabaseBackupRetentionService.php
  - app/Services/DatabaseBackupScheduleService.php
  - app/Console/Commands/DatabaseBackupCommand.php
  - app/Console/Commands/DatabaseBackupScheduleRunCommand.php
  - app/Console/Commands/DatabaseBackupCleanupCommand.php
  - routes/api/v1/admin.php
  - routes/console.php
---

# Database backup & restore

## Overview

The platform can back up its own database with the native tooling of the configured engine (`mysqldump`/`mysql`, `pg_dump`/`psql`, `sqlite3`), accept uploaded external `.sql` dumps, restore them behind explicit safety gates, keep scheduled daily/weekly/monthly backups and prune old ones.

Key invariants:

- **Native processes only.** Dumps and restores run as argument-array Symfony processes (`ProcessRunner`); no shell, no `DB::unprepared()`, no loading of SQL into PHP memory. Restores stream the dump file through the engine's client on stdin.
- **Private storage only.** Backups live on the `local` disk under `database-backups/`, which resolves to `storage/app/private/database-backups/` — never a public disk.
- **Secrets never leak.** Passwords are passed via environment variables (`MYSQL_PWD`, `PGPASSWORD`), never CLI arguments, never logs, never API responses.
- **Admin only.** Every endpoint requires Sanctum authentication plus a `database_backups.*` permission.
- **No destructive action without confirmation.** Restore requires a POST, an explicit `acknowledge` flag and typing the target database name; uploads never auto-restore.

## Permissions

Seeded in `RolesAndPermissionsSeeder` (group `database_backups`):

| Permission | Grants |
| --- | --- |
| `database_backups.view` | List backups, restore/operation logs, audit logs, monitoring |
| `database_backups.create` | Queue a manual backup, upload an external dump |
| `database_backups.restore` | Start a restore (also required together with `create` for restore-upload) |
| `database_backups.delete` | Delete a backup and its file |
| `database_backups.manage_schedule` | View and update the daily/weekly/monthly schedule |

System admins bypass permission checks through the existing RBAC (`system_admin`).

## Storage layout

| Path | Purpose |
| --- | --- |
| `storage/app/private/database-backups/` | Final backup files (`database_backup_{type}_{timestamp}_{random}.sql`) |
| `storage/app/database-backups-tmp/` | Staging directory when the target disk is not `local`; the file is uploaded then unlinked |

Each row in `database_backups` records `storage_disk`, `storage_path`, `file_size`, `checksum_sha256` and `database_driver`. Retention and delete operations flip `status` to `deleted` and remove the file; the row is kept for history.

## Engine support

| Engine | Dump | Restore | Notes |
| --- | --- | --- | --- |
| MySQL / MariaDB | `mysqldump --single-transaction --quick --routines --triggers --events --hex-blob --no-tablespaces --default-character-set=utf8mb4 --result-file=...` | `mysql --default-character-set=utf8mb4` with the dump on stdin | `--events` requires the `EVENT` privilege for the connecting user; grant it or remove the flag per environment. |
| PostgreSQL | `pg_dump --format=plain --no-owner --no-privileges --if-exists --clean --file=...` | `psql --no-psqlrc --set=ON_ERROR_STOP=on --dbname=...` with the dump on stdin | Requires `pg_dump` and `psql` on the server. They are **not installed** on the current development machine, so PostgreSQL round trips are covered by command-construction tests only (binaries pinned in tests) until installed in staging/production. |
| SQLite | `sqlite3 <file> .dump` (stdout streamed to file) | `sqlite3 <file>` with the dump on stdin | In-memory (`:memory:`) connections **cannot** be backed up or restored; manual backups against them are rejected with 422. |

Binary locations default to `PATH` lookup and can be pinned per environment:

```env
DB_BACKUP_MYSQL_DUMP_PATH=/usr/bin/mysqldump
DB_BACKUP_MYSQL_CLIENT_PATH=/usr/bin/mysql
DB_BACKUP_PG_DUMP_PATH=/usr/bin/pg_dump
DB_BACKUP_PSQL_PATH=/usr/bin/psql
DB_BACKUP_SQLITE3_PATH=/usr/bin/sqlite3
```

Uploaded dumps have their engine detected from the file header (`mysqldump`/`pg_dump`/SQLite markers). Unrecognised dumps are stored with `database_driver = 'unknown'` and fail the engine comparison on restore.

## API reference

All routes live under `/api/v1/admin` with Sanctum auth and `can:`/permission middleware. Long-running operations answer `202 Accepted` with `meta.operation_id`; poll the operations endpoint for progress.

### Backups

| Method | URI | Permission | Description |
| --- | --- | --- | --- |
| `GET` | `/database-backups` | view | Paginated history. Filters: `status`, `type`, `search`, `per_page`. |
| `POST` | `/database-backups` | create | Queue a manual backup. Body: `notes?`. Returns `202` + `meta.operation_id`. |
| `GET` | `/database-backups/{backup}` | view | Single backup. |
| `POST` | `/database-backups/upload` | create | Multipart `file` (`.sql` only). Stored synchronously, returns `201`. Never auto-restored. |
| `POST` | `/database-backups/restore-upload` | create + restore | Upload **and** queue a restore in one explicit call. Returns `202` with `meta.operation_id` and `meta.backup_id`. |
| `GET` | `/database-backups/{backup}/download` | view | Streams the `.sql` file (`application/sql`). `422` unless `completed`; `404` if the file is missing. |
| `DELETE` | `/database-backups/{backup}` | delete | Marks the backup `deleted` and removes the file. `422` while pending/processing. |
| `POST` | `/database-backups/{backup}/restore` | restore | Queue a restore. Returns `202` + `meta.operation_id`. |
| `GET` | `/database-backups/monitoring` | view | Health snapshot (see below). |
| `GET` | `/database-backups/operations` | view | Paginated restore logs. |
| `GET` | `/database-backups/operations/{restoreLog}` | view | Single restore log. |
| `GET` | `/database-backups/audit-logs?backup_id=` | view | Audit trail filtered to a backup. |

### Schedules

| Method | URI | Permission | Description |
| --- | --- | --- | --- |
| `GET` | `/backup-schedules` | manage_schedule | The three persisted schedules (created on first read). |
| `PATCH` | `/backup-schedules/{schedule}` | manage_schedule | Update `execution_time` (`HH:MM`), `day_of_week` (`0`–`6` or day name), `day_of_month` (`1`–`31`), `retention_count`, `is_enabled`. |

### Restore payload

```json
{
    "confirmation": "<exact database name of the target>",
    "acknowledge": true
}
```

## Restore safety gates

`DatabaseRestoreService::initiate()` enforces, in order:

1. Feature enabled (`DB_BACKUP_ENABLED`).
2. Restores enabled (`DB_BACKUP_RESTORE_ENABLED`).
3. Backup `status = completed`.
4. Dump engine matches the active connection engine.
5. `acknowledge` is `true` (validation).
6. `confirmation` matches the target database name via `hash_equals`.
7. Backup file exists on the private disk.
8. File size ≤ `DB_BACKUP_MAX_UPLOAD_SIZE` (applies to uploads).
9. SHA-256 checksum matches the recorded value.
10. Target is backupable (rejects `:memory:`).
11. No other restore is pending/processing (global cache lock `database-backup:restore`).

It then creates a `database_restore_logs` row (`pending`) and dispatches `RestoreDatabaseBackupJob`, which — depending on configuration — takes an optional pre-restore safety backup, optionally enables maintenance mode, streams the dump into the engine client, and records duration, status (`completed`/`failed`) and an error message. Jobs run on the `database-backups` queue with `tries=1` and configured timeouts.

## Scheduling

`routes/console.php` schedules:

```php
Schedule::command('database:backup-schedule-run')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('database:backup-cleanup')->dailyAt('04:30')->withoutOverlapping()->onOneServer();
```

`database:backup-schedule-run` evaluates the persisted daily/weekly/monthly rows (independent `execution_time`, `day_of_week`, `day_of_month`, `retention_count`, `is_enabled`), dispatches `CreateDatabaseBackupJob` for due ones and records `last_run_at` / `next_run_at`. Next-run math is evaluated in `DB_BACKUP_TIMEZONE`; monthly day 31 clamps to the last day of shorter months.

## Retention

`database:backup-cleanup` (also available as `DatabaseBackupRetentionService::cleanup()`) keeps the newest `retention_count` completed backups per schedule type (defaults 7 / 4 / 12) and marks the rest `deleted`. It **never** touches manual or uploaded backups, anything not `completed`, or backups with a pending/processing restore.

## Monitoring

`GET /database-backups/monitoring` returns:

- `is_enabled`, `restore_enabled`
- `storage_bytes`, `backups_by_status`, `backups_by_type`
- `latest_backup`, `latest_failed_backup`
- `next_scheduled_backup` (across all schedules)
- `active_restore`, `latest_restore`

All mutating actions (`created`, `scheduled`, `uploaded`, `restore_requested`, `restore_completed`, `restore_failed`, `backup_deleted`, `retention_cleanup`) are written to `database_backup_audit_logs` with actor, IP, user agent and sanitised metadata (keys containing `password`, `token`, `secret`, `key` are stripped).

## Configuration reference

```env
DB_BACKUP_ENABLED=true
DB_BACKUP_DISK=local
DB_BACKUP_PATH=database-backups
DB_BACKUP_TEMP_PATH=database-backups-tmp
DB_BACKUP_QUEUE=database-backups
DB_BACKUP_TIMEZONE=UTC
DB_BACKUP_MAX_UPLOAD_SIZE=51200

DB_BACKUP_MYSQL_DUMP_PATH=mysqldump
DB_BACKUP_MYSQL_CLIENT_PATH=mysql
DB_BACKUP_PG_DUMP_PATH=pg_dump
DB_BACKUP_PSQL_PATH=psql
DB_BACKUP_SQLITE3_PATH=sqlite3

DB_BACKUP_RESTORE_ENABLED=true
DB_BACKUP_RESTORE_SAFETY_BACKUP=true
DB_BACKUP_RESTORE_REQUIRE_SAFETY_BACKUP=false
DB_BACKUP_RESTORE_MAINTENANCE_MODE=false
DB_BACKUP_TIMEOUT=3600
DB_BACKUP_RESTORE_TIMEOUT=3600
DB_BACKUP_LOCK_TTL=7200
DB_BACKUP_RESTORE_LOCK_TTL=7200

DB_BACKUP_DAILY_ENABLED=true
DB_BACKUP_DAILY_TIME=01:00
DB_BACKUP_DAILY_RETENTION=7
DB_BACKUP_WEEKLY_ENABLED=true
DB_BACKUP_WEEKLY_TIME=02:00
DB_BACKUP_WEEKLY_DAY=0
DB_BACKUP_WEEKLY_RETENTION=4
DB_BACKUP_MONTHLY_ENABLED=true
DB_BACKUP_MONTHLY_TIME=03:00
DB_BACKUP_MONTHLY_DAY=1
DB_BACKUP_MONTHLY_RETENTION=12

# DB_BACKUP_NOTIFICATION_EMAIL=ops@example.com
```

Binary timeouts default to 3600s, cache locks to 7200s (`locks.backup_ttl`, `locks.restore_ttl`). `DB_BACKUP_MAX_UPLOAD_SIZE` (default 51200 bytes = 50 MB) bounds uploaded dumps. Notification email is optional; failures are swallowed so backups never fail because of mail.

## Artisan commands

```bash
php artisan database:backup                      # queue a manual backup (default type)
php artisan database:backup --type=weekly --sync # run a specific type inline
php artisan database:backup-schedule-run         # dispatch every due schedule
php artisan database:backup-schedule-run --sync  # run due schedules inline
php artisan database:backup-cleanup              # apply retention
```

The queue worker must run for non-`--sync` operations: `QUEUE_CONNECTION=database` with the `database-backups` queue (`php artisan queue:work --queue=database-backups` or Herd's queue process).

## Testing

`tests/Feature/DatabaseBackup*Test.php` covers authorization, the API surface, restore gates, schedules, retention, driver command construction and a real SQLite dump→restore round trip through the native `sqlite3` shell (skipped when the binary is absent). Driver tests pin every binary to `PHP_BINARY` so command construction is verified even where `pg_dump`/`psql` are missing.

## Known limitations

- PostgreSQL backup/restore has not been executed end-to-end on this machine (`pg_dump`/`psql` not installed); its SQL construction is test-pinned and must be smoke-tested after installing the client tools.
- MySQL `--events` requires the `EVENT` privilege; without it the dump fails and the error message is recorded on the backup row.
- Only plain-text SQL dumps are accepted (no compressed archives); uploads are limited to `.sql`.
