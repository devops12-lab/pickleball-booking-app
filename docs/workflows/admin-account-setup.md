---
title: Account creation commands
category: Platform administration
status: Implemented
order: 5
summary: CLI commands for creating user accounts across all roles — system admin, player, owner, staff, and organizer.
sources:
  - app/Console/Commands/CreateAdminCommand.php
  - app/Console/Commands/CreatePlayerCommand.php
  - app/Console/Commands/CreateOwnerCommand.php
  - app/Console/Commands/CreateStaffCommand.php
  - app/Console/Commands/CreateOrganizerCommand.php
  - app/Services/Auth/AccountProvisioningService.php
---

# Account creation commands

CourtFlex provides artisan commands to create user accounts for every role directly from the CLI. These commands are useful during initial setup, staging environments, scripted provisioning, and CI pipelines — no web UI or API calls required.

## Command overview

| Command | Role | Creates new user? | Requires existing resource? |
| --- | --- | --- | --- |
| `php artisan admin:create` | System Admin | Yes | — |
| `php artisan player:create` | Player | Yes | — |
| `php artisan owner:create` | Facility Owner | Yes | — (creates a new tenant) |
| `php artisan staff:create` | Facility Staff | Yes | Tenant (facility) must exist |
| `php artisan organizer:create` | Club Organizer | No | User and Club must exist |

## Initial setup

After cloning the repository and running migrations:

```bash
php artisan migrate:fresh --seed
php artisan admin:create
```

The seeder provisions:
- 32 granular permissions and 5 roles (`system_admin`, `owner`, `staff`, `player`, `club_manager`)
- The `platform-admin` system tenant
- Subscription plans (Free, Starter, Professional, Business)
- Philippine geographic data (regions, provinces, cities, barangays)

---

## admin:create

Create a system administrator with full platform access.

### Interactive mode

```bash
php artisan admin:create
```

Prompts for: full name, email address, password.

### Non-interactive mode

```bash
php artisan admin:create --name="Admin User" --email="admin@example.com" --password="SecurePass123"
```

### What gets created

| Record | Table | Details |
| --- | --- | --- |
| User account | `users` | Active status, email verified, hashed password |
| Player statistics | `player_statistics` | Default rating of 1500.00 |
| Platform tenant link | `tenant_users` | Links user to `platform-admin` tenant with `system_admin` role |

### Existing user behavior

If the email already belongs to a registered user, the command asks whether to promote that user to system admin. If the user is already a system admin, the command reports that and exits.

### Portal access

| Endpoint | Method |
| --- | --- |
| `POST /api/v1/admin/auth/login` | Password authentication → Sanctum token |

---

## player:create

Create a player account with a profile and statistics.

### Interactive mode

```bash
php artisan player:create
```

Prompts for: full name, email address, password.

### Non-interactive mode

```bash
php artisan player:create --name="Emma Player" --email="emma@example.com" --password="SecurePass123"
```

### What gets created

| Record | Table | Details |
| --- | --- | --- |
| User account | `users` | Active status, hashed password |
| Player profile | `player_profiles` | Display name set from full name |
| Player statistics | `player_statistics` | Default rating of 1200.00 |

### Existing user behavior

If the email already exists and has a player profile, the command warns and exits. If the email exists but has no player profile, the command asks whether to create one for the existing user.

### Portal access

| Endpoint | Method |
| --- | --- |
| `POST /api/v1/players/auth/login` | Password authentication |
| `POST /api/v1/players/auth/google` | Google OAuth |

---

## owner:create

Create a facility owner with a new tenant (business facility).

### Interactive mode

```bash
php artisan owner:create
```

Prompts for: full name, email, password, business name, business email, business phone, address.

### Non-interactive mode

```bash
php artisan owner:create \
    --name="Alice Owner" \
    --email="alice@arena.test" \
    --password="SecurePass123" \
    --business-name="Alice Sports Hub" \
    --business-email="info@alicesports.com" \
    --business-phone="+1-555-123-4567" \
    --address="123 Pickleball Way"
```

### What gets created

| Record | Table | Details |
| --- | --- | --- |
| User account | `users` | Active status, hashed password |
| Player statistics | `player_statistics` | Default rating of 1200.00 |
| Tenant (facility) | `tenants` | Business name, slug auto-generated, active status |
| Owner membership | `tenant_users` | Links user to new tenant with `owner` role |

### Existing user behavior

If the email already exists and is already an owner, the command warns and exits. If the email exists but is not an owner, the command asks whether to create a new facility and assign the owner role.

### Portal access

| Endpoint | Method |
| --- | --- |
| `POST /api/v1/owners/auth/login` | Password authentication (requires `tenant_id`) |
| `POST /api/v1/owners/auth/google` | Google OAuth |

---

## staff:create

Create a facility staff member and assign them to an existing tenant.

### Interactive mode

```bash
php artisan staff:create
```

Prompts for: full name, email, password, tenant slug or ID.

### Non-interactive mode

```bash
php artisan staff:create \
    --name="Clara Staff" \
    --email="clara@metro.test" \
    --password="SecurePass123" \
    --tenant="metro-pickleball-club"
```

The `--tenant` option accepts either a slug (`metro-pickleball-club`) or a numeric ID (`3`).

### What gets created

| Record | Table | Details |
| --- | --- | --- |
| User account | `users` | Active status, email verified, hashed password |
| Player statistics | `player_statistics` | Default rating of 1200.00 |
| Staff membership | `tenant_users` | Links user to tenant with `staff` role |

> **Note:** This command bypasses the invitation system and creates the staff account directly. The invitation flow (`StaffInvitation`) is designed for the web UI where owners invite staff via email.

### Existing user behavior

If the email already exists and is already staff at the given tenant, the command warns and exits. If the email exists but is not staff, the command asks whether to assign the staff role at that tenant.

### Portal access

| Endpoint | Method |
| --- | --- |
| `POST /api/v1/staff/auth/login` | Password authentication (requires `tenant_id`) |
| `POST /api/v1/staff/auth/google` | Google OAuth |

---

## organizer:create

Assign an existing user as a club organizer. Unlike the other commands, this does not create a new user — the user must already exist.

### Interactive mode

```bash
php artisan organizer:create
```

Prompts for: user email, club slug or ID.

### Non-interactive mode

```bash
php artisan organizer:create --email="oscar@club.test" --club="downtown-dinking-club"
```

The `--club` option accepts either a slug (`downtown-dinking-club`) or a numeric ID (`5`).

### What gets created

| Record | Table | Details |
| --- | --- | --- |
| Club membership | `club_members` | Organizer role, active status, joined timestamp |

### Existing membership behavior

- If the user is already an organizer or manager of the club → warns and exits.
- If the user is a regular member of the club → asks whether to promote to organizer.
- If the user has no membership → creates a new organizer membership.

### Portal access

| Endpoint | Method |
| --- | --- |
| `POST /api/v1/organizers/auth/login` | Password authentication |
| `POST /api/v1/organizers/auth/google` | Google OAuth |

---

## Scripted provisioning example

A complete setup script for a fresh deployment:

```bash
# Seed structural data
php artisan migrate:fresh --seed

# Create system admin
php artisan admin:create \
    --name="Platform Admin" \
    --email="admin@courtflex.com" \
    --password="AdminPass123!"

# Create a facility owner with their venue
php artisan owner:create \
    --name="Alice Owner" \
    --email="alice@arena.test" \
    --password="OwnerPass123!" \
    --business-name="Manila Pickleball Hub"

# Create staff for that facility (use the tenant slug from owner output)
php artisan staff:create \
    --name="Bob Staff" \
    --email="bob@arena.test" \
    --password="StaffPass123!" \
    --tenant="manila-pickleball-hub"

# Create a player
php artisan player:create \
    --name="Charlie Player" \
    --email="charlie@gmail.com" \
    --password="PlayerPass123!"
```
