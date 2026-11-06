---
title: Documentation discrepancies
category: Project status
status: Implemented
order: 10
summary: Every conflict found between the existing Markdown documents and the code, with the file it appears in and what the code actually does.
sources:
  - app/Policies/BookingPolicy.php
  - app/Http/Middleware/RequirePermission.php
  - app/Http/Middleware/IdentifyTenant.php
  - app/Services/FileUploadService.php
  - app/Services/PaymentVerificationService.php
  - app/Services/AuditLogService.php
  - app/Models/Traits/BelongsToTenant.php
---

# Documentation discrepancies

The existing documents in `docs/` were compared with the code. They were **not edited**; instead, each conflicting page shows a notice at the top, and the full list is here. The code is treated as the source of truth.

## Conflicts

| Document | The document says | The code does |
| --- | --- | --- |
| [Architecture](../ARCHITECTURE.md), [Implementation roadmap](../IMPLEMENTATION_ROADMAP.md) | A completed policy authorization layer guards mutations and reads. | The 7 policies are never called; no `authorize()`, `Gate` or `can()` in controllers. |
| [Multi-tenancy](../MULTI_TENANCY.md), [Plan](../PLAN.md) | `RequirePermission` enforces permissions such as `courts.create` on tenant routes. | No route uses the `permission` or `staff.permission` middleware. |
| [RBAC permission matrix](../RBAC_PERMISSION_MATRIX.md) | Permissions per role decide what each role can do. | Permissions are seeded and evaluable, but access is decided by role middleware and controller checks only. |
| [Architecture](../ARCHITECTURE.md) | `tenant.context` resolves the tenant from header, route parameter "or user tenancy". | Only the `X-Tenant-ID` header, a `{tenant}` route parameter or a `tenant_id` input. |
| [Architecture](../ARCHITECTURE.md) | `FileUploadService` uses SHA-256 file names. | Uses `Str::random(40)` names. |
| [Multi-tenancy](../MULTI_TENANCY.md) | Every administrative and financial mutation is audit-logged. | Only logins and payment verification are logged. |
| [Multi-tenancy](../MULTI_TENANCY.md), [Plan](../PLAN.md) | `BelongsToTenant` provides isolation scopes. | It adds a `tenant()` relation and a manual `forTenant()` scope; there is no automatic global scope. |
| [Database schema](../DATABASE_SCHEMA.md) | Booking status `pending_payment`. | Bookings are created as `pending`. |
| [Database schema](../DATABASE_SCHEMA.md) | Payment status `rejected`. | `reject()` sets `failed`, and no route calls it. |
| [Plan](../PLAN.md) | A `notifications` table and a `users.google_id` column. | Neither exists; Google identities are in `user_auth_providers`. |
| [Plan](../PLAN.md) | Rate limiting of 10 attempts per minute on authentication routes. | The `login` limiter allows 5 per minute per email and IP. |
| [Plan](../PLAN.md) | A `CourtFlexFoundationSeeder`. | The seeders are `DatabaseSeeder`, `DemoDataSeeder`, `PlatformAdminSeeder` and `RolesAndPermissionsSeeder`. |
| [Implementation roadmap](../IMPLEMENTATION_ROADMAP.md) | 87 passing tests. | The suite has grown since; run `php artisan test` for the current count. |
| [Architecture](../ARCHITECTURE.md) | The Google flow is listed among completed role gateways. | The Google token signature is not verified (see [Authentication](authentication.md)). |

## Features described but not reachable

These appear as working features in planning or reference documents, but no route reaches them:

- Creating payments, rejecting payments and refunds ([Payments](payments.md)).
- Creating staff invitations and club invitations ([Authentication](authentication.md), [Clubs](clubs.md)).
- Creating open play sessions, tournament categories and matches ([Open play](open-play.md), [Tournaments](tournaments-and-matches.md)).
- Managing court operating hours and blocked schedules ([Facilities and courts](facilities-and-courts.md)).
- Sending password reset and verification emails ([Background jobs](background-processing.md)).

## Code issues found while documenting

These are not documentation conflicts, but behavior worth fixing:

- Tenants with status `trial` are locked out like suspended tenants ([Platform administration](platform-administration.md)).
- Invalid Google or invitation tokens return 500 instead of 4xx ([Security](security-and-error-handling.md)).
- Booking creation can double-book under concurrent requests ([Booking lifecycle](booking-lifecycle.md)).
- Owners can log into the staff portal but are refused by staff routes; club-only organizers can log into the organizer portal but are refused by organizer routes ([Authorization](authorization-and-tenancy.md)).
