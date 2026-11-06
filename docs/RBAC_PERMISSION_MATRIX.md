# CourtFlex — Role-Based Access Control (RBAC) Matrix

CourtFlex provides a flexible capability-based permission model. Roles group permissions into convenient presets, while user overrides allow granting or revoking specific permissions.

---

## Roles Overview

| Role Name | Slug | Scope | Description |
| :--- | :--- | :--- | :--- |
| **System Admin** | `system_admin` | Platform | Platform administrator with unrestricted access to manage tenants, subscriptions, and platform settings. |
| **Facility Owner** | `owner` | Tenant | Facility owner with full operational control over courts, facilities, staff, bookings, tournaments, and financial verifications. |
| **Facility Staff** | `staff` | Tenant | Operations staff facilitating bookings, checking in players, and verifying customer payment receipts. |
| **Player** | `player` | Platform / Global | General player account with access to explore venues, book courts, join clubs, and register for tournaments. |
| **Club Manager** | `club_manager` | Delegated / Club | Organizer managing club rosters, open play sessions, and submitting authorized match results. |

---

## Detailed Permissions Matrix

| Permission Slug | Permission Group | System Admin | Facility Owner | Facility Staff | Player | Club Manager |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: |
| `tenants.manage` | Tenants | ✅ | ❌ | ❌ | ❌ | ❌ |
| `tenants.view` | Tenants | ✅ | ✅ | ❌ | ❌ | ❌ |
| `tenants.update` | Tenants | ✅ | ✅ | ❌ | ❌ | ❌ |
| `staff.manage` | Staff | ✅ | ✅ | ❌ | ❌ | ❌ |
| `staff.view` | Staff | ✅ | ✅ | ❌ | ❌ | ❌ |
| `facilities.manage` | Facilities | ✅ | ✅ | ❌ | ❌ | ❌ |
| `facilities.view` | Facilities | ✅ | ✅ | ✅ | ✅ | ✅ |
| `courts.create` | Courts | ✅ | ✅ | ❌ | ❌ | ❌ |
| `courts.update` | Courts | ✅ | ✅ | ❌ | ❌ | ❌ |
| `courts.delete` | Courts | ✅ | ✅ | ❌ | ❌ | ❌ |
| `courts.view` | Courts | ✅ | ✅ | ✅ | ✅ | ✅ |
| `bookings.create` | Bookings | ✅ | ✅ | ❌ | ✅ | ✅ |
| `bookings.view` | Bookings | ✅ | ✅ | ✅ | ✅ (Own) | ✅ |
| `bookings.manage` | Bookings | ✅ | ✅ | ✅ | ❌ | ❌ |
| `bookings.cancel` | Bookings | ✅ | ✅ | ✅ | ✅ (Own) | ❌ |
| `payments.view` | Payments | ✅ | ✅ | ✅ | ✅ (Own) | ❌ |
| `payments.verify` | Payments | ✅ | ✅ | ✅ | ❌ | ❌ |
| `payments.refund` | Payments | ✅ | ✅ | ❌ | ❌ | ❌ |
| `clubs.create` | Clubs | ✅ | ✅ | ❌ | ❌ | ❌ |
| `clubs.manage` | Clubs | ✅ | ✅ | ❌ | ❌ | ✅ |
| `clubs.join` | Clubs | ✅ | ❌ | ❌ | ✅ | ❌ |
| `clubs.view` | Clubs | ✅ | ✅ | ✅ | ✅ | ✅ |
| `open_play.create` | Open Play | ✅ | ✅ | ❌ | ❌ | ❌ |
| `open_play.manage` | Open Play | ✅ | ✅ | ✅ | ❌ | ❌ |
| `open_play.join` | Open Play | ✅ | ❌ | ❌ | ✅ | ❌ |
| `tournaments.create` | Tournaments | ✅ | ✅ | ❌ | ❌ | ✅ |
| `tournaments.manage` | Tournaments | ✅ | ✅ | ❌ | ❌ | ✅ |
| `tournaments.participate` | Tournaments | ✅ | ❌ | ❌ | ✅ | ❌ |
| `scores.submit` | Matches | ✅ | ✅ | ✅ | ❌ | ✅ |
| `scores.verify` | Matches | ✅ | ✅ | ✅ | ❌ | ❌ |
| `audit_logs.view` | Admin | ✅ | ✅ | ❌ | ❌ | ❌ |
| `settings.manage` | Admin | ✅ | ❌ | ❌ | ❌ | ❌ |

---

## Contextual Roles

A user can possess multiple roles depending on the active tenant context:
- User **Alice** can be an `owner` of **Facility 1**, a `staff` member at **Facility 2**, and a `player` booking at **Facility 3**.
- Permissions are evaluated dynamically against the active tenant using `PermissionService::can($user, $permission, $tenantId)`.
