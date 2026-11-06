---
title: Background jobs, events and notifications
category: Background jobs & notifications
status: Planned
order: 10
summary: The queue, cache and session infrastructure that exists, and the jobs, events, mail and notifications that do not exist yet.
sources:
  - database/migrations/0001_01_01_000001_create_cache_table.php
  - database/migrations/0001_01_01_000002_create_jobs_table.php
  - routes/console.php
---

# Background jobs, events and notifications

## What exists

- The `jobs`, `job_batches` and `failed_jobs` tables, and `.env.example` sets `QUEUE_CONNECTION=database`.
- The `cache` and `cache_locks` tables for the database cache store, and the `sessions` table for database sessions.
- One scheduled task in `routes/console.php`: `sanctum:prune-expired --hours=24` runs daily. Because `sanctum.expiration` is not set, it only removes tokens that were given their own `expires_at`; normal login tokens never expire and are not pruned. The scheduler only runs if `php artisan schedule:run` is triggered by cron or `schedule:work`.

## What does not exist

| Building block | State |
| --- | --- |
| Jobs (`app/Jobs`) | None. Nothing is dispatched to the queue. |
| Events and listeners (`app/Events`, `app/Listeners`) | None. No `event()` calls. |
| Notifications (`app/Notifications`) | None, and there is no `notifications` table. |
| Mail | No `Mail::` calls anywhere. |

Every operation in the backend runs synchronously inside the HTTP request.

## Consequences for workflows

- Password reset tokens are stored but never sent (see [Authentication](authentication.md)).
- Booking confirmations, payment results and club approvals produce no messages to players.
- There is no reservation-hold expiry: pending bookings stay pending until staff or the player change them.

The roadmap (`docs/IMPLEMENTATION_ROADMAP.md`) plans e-receipts, reservation holds, waitlists and subscription billing, all of which would need queued jobs or scheduled commands.
