---
title: Announcements
category: Communication
status: Implemented
order: 12
summary: Platform-wide and tenant-scoped announcements with a full lifecycle (draft, scheduled, published, archived), audience targeting, read tracking and unread counts.
sources:
  - app/Services/AnnouncementService.php
  - app/Models/Announcement.php
  - app/Models/AnnouncementRead.php
  - app/Http/Controllers/Api/V1/Admin/AnnouncementController.php
  - app/Http/Controllers/Api/V1/Owners/AnnouncementController.php
  - app/Http/Controllers/Api/V1/Players/AnnouncementController.php
  - app/Console/Commands/PublishDueAnnouncementsCommand.php
---

# Announcements

## Overview

Announcements let the platform and individual facilities communicate with their users. Every announcement follows the same lifecycle and audience model:

- **Platform-wide** (`tenant_id = null`) — created by System Admins; visible to the whole platform.
- **Tenant-scoped** — created by Facility Owners; visible only within their facility.

**Lifecycle**: `draft` → `scheduled` → `published` → `archived`

| Status | Meaning |
| --- | --- |
| `draft` | Visible to managers only. |
| `scheduled` | Becomes `published` automatically when `publish_at` passes (`announcements:publish-due`, runs every minute). |
| `published` | Live for the target audience until `expires_at` (optional) or archiving. |
| `archived` | Hidden from audiences; can no longer be edited or re-published. |

**Audience targeting**: `all`, `players`, `staff`, `owners`. Players only ever see `all`/`players` announcements; management endpoints ignore audience filtering.

**Player visibility rule**: players see platform-wide announcements plus tenant announcements from every tenant where they have at least one booking or an active club membership.

## Permissions

| Permission | Grants |
| --- | --- |
| `announcements.view` | List/show announcements (admin: all; owner: own tenant) |
| `announcements.create` | Create announcements |
| `announcements.update` | Edit non-archived announcements |
| `announcements.delete` | Delete announcements |
| `announcements.publish` | Publish and archive announcements |

`system_admin` holds all permissions; `owner` holds all five at tenant scope. The player feed requires only the `role:player` route group (no announcement permission).

## API reference

### System Admin — `/api/v1/admin/announcements`

| Method | URI | Permission | Description |
| --- | --- | --- | --- |
| `GET` | `/announcements` | view | Paginated list. Filters: `status`, `type`, `search`, `per_page`. Includes `read_count`. |
| `POST` | `/announcements` | create | Create. `201` with the status derived from `publish_at`. |
| `GET` | `/announcements/{announcement}` | view | Details. |
| `PATCH` | `/announcements/{announcement}` | update | Update non-archived announcements. |
| `DELETE` | `/announcements/{announcement}` | delete | Delete (cascades read receipts). |
| `POST` | `/announcements/{announcement}/publish` | publish | Publish now (`draft`/`scheduled` only). |
| `POST` | `/announcements/{announcement}/archive` | publish | Hide from audiences. |

### Facility Owner — `/api/v1/owners/announcements`

Identical surface to the admin API but always scoped to the caller's tenant via `tenant.context`. Cross-tenant access returns `403`. Platform-wide announcements never appear in owner listings.

### Player — `/api/v1/players/announcements` (read-only)

| Method | URI | Description |
| --- | --- | --- |
| `GET` | `/announcements` | Visible feed, pinned first, newest after. Meta includes `unread_count`. Filters: `per_page`. |
| `GET` | `/announcements/unread-count` | `{ "data": { "unread": 3 } }` |
| `GET` | `/announcements/{announcement}` | Detail (`404` when not visible). |
| `POST` | `/announcements/{announcement}/read` | Idempotent mark-as-read. |

## Payload

```json
{
    "title": "Court 3 repainted",
    "body": "Court 3 reopens on Monday morning.",
    "type": "maintenance",
    "audience": "all",
    "publish_at": "2026-11-15T08:00:00+00:00",
    "expires_at": "2026-11-30T00:00:00+00:00",
    "is_pinned": false
}
```

Validation rules:

- `title` — required, max 200 characters.
- `body` — required, max 20,000 characters.
- `type` — one of `general`, `maintenance`, `promotion`, `event`, `alert` (default `general`).
- `audience` — one of `all`, `players`, `staff`, `owners` (default `all`).
- `publish_at` — optional ISO-8601 timestamp. **Future** → `scheduled`; **present/past** → published immediately.
- `expires_at` — optional; must be after `publish_at`.
- `is_pinned` — optional boolean (default `false`).

Status transitions are enforced server-side: editing an `archived` announcement or re-publishing one returns `422`.

## Read tracking

The `announcement_reads` pivot records `read_at` per user per announcement (unique pair, idempotent). `unread` counts visible published announcements without a receipt, so archived/expired/draft items never inflate badges.

## Scheduling

`routes/console.php`:

```php
Schedule::command('announcements:publish-due')
    ->everyMinute()
    ->withoutOverlapping(30)
    ->onOneServer();
```

The command promotes every `scheduled` announcement with `publish_at <= now()` and stamps `published_at` with the original `publish_at` value. Requires the scheduler to run (`schedule:work` or a cron trigger).

## Audit trail

Create, update, publish, archive and delete actions are written to `audit_logs` (`announcement_created`, `announcement_updated`, `announcement_published`, `announcement_archived`, `announcement_deleted`) with old/new value snapshots. Audit failures never block the operation.

## Testing

`tests/Feature/AnnouncementAdminTest.php` (12), `AnnouncementOwnerTest.php` (6) and `AnnouncementPlayerTest.php` (10) cover CRUD, lifecycle transitions, validation, tenant isolation, audience filtering, expiry, ordering, read tracking and the scheduler command.
