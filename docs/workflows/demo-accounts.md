---
title: Demo account seeder
category: Getting started
status: Implemented
order: 15
summary: Seed one demo account per role for local development and staging — admin, owner, staff, player, and organizer.
sources:
  - database/seeders/DemoAccountSeeder.php
  - app/Services/Auth/AccountProvisioningService.php
---

# Demo account seeder

The `DemoAccountSeeder` creates one user per role so you can explore every part of the application without manual registration. It is designed for local development and staging environments only.

## Running the seeder

```bash
php artisan db:seed --class=DemoAccountSeeder
```

The seeder is **not** included in `DatabaseSeeder` and will never run during `migrate:fresh --seed`. You must invoke it explicitly.

> **Production guard** — the seeder checks `app()->environment('production')` and refuses to run if the application is in production mode.

## Accounts created

All accounts share the same password: `Password123!`

| Role | Name | Email | Tenant / Club |
| --- | --- | --- | --- |
| System Admin | Demo Administrator | `admin@courtflex.test` | Linked to `platform-admin` tenant |
| Facility Owner | Demo Facility Owner | `owner@courtflex.test` | Creates **Demo Pickleball Venue** tenant |
| Facility Staff | Demo Staff Member | `staff@courtflex.test` | Assigned to Demo Pickleball Venue |
| Player | Demo Player | `player@courtflex.test` | — |
| Club Organizer | Demo Club Organizer | `organizer@courtflex.test` | Creates **Demo Pickleball Club** at the venue |

## What each account provisions

### System Admin

- `User` with verified email and active status
- `PlayerStatistic` with default rating (1500)
- `TenantUser` linking the user to the `platform-admin` tenant with the `system_admin` role

### Facility Owner

Uses `AccountProvisioningService::provisionOwner()` — the same path as real owner registration:

- `User` with verified email
- `PlayerStatistic` with default rating
- `Tenant` named "Demo Pickleball Venue" with business details
- `TenantUser` with the `owner` role

### Facility Staff

- `User` with verified email
- `PlayerStatistic` with default rating (1200)
- `TenantUser` linking the user to the owner's tenant with the `staff` role

### Player

Uses `AccountProvisioningService::provisionPlayer()` — the same path as real player registration:

- `User` with verified email
- `PlayerProfile` with display name
- `PlayerStatistic` with default rating (1200)

### Club Organizer

First provisioned as a player (via `provisionPlayer`), then:

- `Club` named "Demo Pickleball Club" at the owner's tenant
- `ClubMember` with `organizer` role and active status

## Idempotent design

The seeder uses `firstOrCreate` throughout. Running it multiple times will not create duplicate records — it safely skips accounts that already exist.

## Prerequisites

The seeder depends on structural data that `DatabaseSeeder` provisions:

1. Roles and permissions (`RolesAndPermissionsSeeder`)
2. The `platform-admin` tenant (`PlatformAdminSeeder`)

Run migrations and the main seeder first:

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoAccountSeeder
```

## Portal access after seeding

| Account | Portal / Entry point |
| --- | --- |
| System Admin | Admin dashboard, all platform settings |
| Facility Owner | Owner portal, facility management, court CRUD |
| Facility Staff | Staff portal (scoped to Demo Pickleball Venue) |
| Player | Player-facing booking, profile, clubs |
| Club Organizer | Club management for Demo Pickleball Club |

## Related commands

Each role also has a dedicated artisan command for creating individual accounts. See [Account creation commands](admin-account-setup.md) for details.
