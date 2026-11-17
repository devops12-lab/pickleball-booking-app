---
title: Staff management
category: Facility & court management
status: Implemented
order: 25
summary: How facility owners add, manage, and remove staff members — including invitation flow, credential management, role restrictions, subscription limits, and tenant isolation.
sources:
  - app/Http/Controllers/Api/V1/Owners/StaffController.php
  - app/Http/Controllers/Api/V1/Auth/StaffAuthController.php
  - app/Http/Requests/Staff/StoreStaffRequest.php
  - app/Http/Requests/Staff/UpdateStaffRequest.php
  - app/Http/Requests/Staff/UpdateStaffCredentialsRequest.php
  - app/Services/SubscriptionEntitlementService.php
  - app/Services/Auth/AccountProvisioningService.php
  - app/Services/AuditLogService.php
  - routes/api/v1/owners.php
  - routes/api/v1/staff.php
---

# Staff management

Staff members are **invitation-only** accounts. Only facility owners can add staff to their tenant. There is no public staff registration endpoint — staff accounts are created through the owner portal and activated through an invitation acceptance flow.

## Adding staff

Owners create staff via `POST /api/v1/owners/staff`. The endpoint accepts a name, email, and optional phone number. The `name` field must be a full name containing at least 2 words (e.g. first name and surname), enforced by the `App\Rules\FullName` validation rule in `StoreStaffRequest`.

### New user flow

When the email does not exist in the `users` table:

1. A new `User` record is created with a random 40-character password (unusable for login).
2. A `TenantUser` pivot is created with `status: invited` and `role: staff`.
3. The owner distributes the invitation link to the staff member out-of-band.
4. The staff member calls `POST /api/v1/staff/auth/accept-invitation` with their token to set a password (or link a Google account) and activate their account. The name provided must be a full name (at least 2 words), validated by `StaffAcceptInvitationRequest`.

### Existing user flow

When the email already exists:

1. The existing `User` record is reused (no duplicate account).
2. A `TenantUser` pivot is created with `status: active` — the user can log in immediately.
3. If the user already has an active membership in this tenant, the request is rejected with `422`.

## Role restrictions

The `StoreStaffRequest` and `UpdateStaffRequest` restrict the `role` field to `in:staff`. Attempts to assign `owner` or `system_admin` through these endpoints return a `422` validation error. This prevents privilege escalation through the staff management API.

## Viewing and updating staff

| Endpoint | Purpose |
| --- | --- |
| `GET /api/v1/owners/staff` | List all staff and owner members for the tenant |
| `GET /api/v1/owners/staff/{user}` | View a single staff member's details |
| `PUT /api/v1/owners/staff/{user}` | Update name, phone, or role (name must be a full name with at least 2 words) |
| `PATCH /api/v1/owners/staff/{user}/status` | Activate or deactivate a staff member |
| `PUT /api/v1/owners/staff/{user}/credentials` | Update email or password |
| `DELETE /api/v1/owners/staff/{user}` | Remove staff from the tenant |

All endpoints are scoped to the authenticated owner's tenant via `TenantContextService`. Attempting to access another tenant's staff returns `404`.

## Credential management

Owners can update a staff member's email and password via the credentials endpoint. When any credential is changed:

- **All Sanctum tokens** for that staff member are revoked immediately.
- The staff member must log in again with the new credentials.
- Password hashes are never included in any API response.

## Status toggle and token revocation

Setting a staff member's status to `inactive`:

- Updates the `TenantUser.status` to `inactive`.
- Revokes all of the staff member's Sanctum tokens.
- The staff member cannot log in until reactivated.

**Self-protection:** Owners cannot deactivate or remove themselves. Attempts return `422`.

## Subscription limits

Staff creation is gated by `SubscriptionEntitlementService::checkOrFail()`, which uses `SELECT ... FOR UPDATE` inside a database transaction to prevent race conditions.

- The tenant's subscription plan defines `max_staff`.
- Only **active** staff and owner `TenantUser` records count toward the limit.
- Deactivated (inactive) staff do not consume a slot.
- When the limit is reached, `POST /api/v1/owners/staff` returns `403 Forbidden`.

## Audit logging

All staff mutations are logged via `AuditLogService`:

| Action | Trigger |
| --- | --- |
| `staff.added` | New staff member created |
| `staff.updated` | Name, phone, or role changed |
| `staff.status_changed` | Activated or deactivated |
| `staff.credentials_changed` | Email or password updated |
| `staff.removed` | Staff member deleted from tenant |

Each log entry includes the tenant ID, the entity, and old/new values (passwords are recorded as `"changed"`, never as hashes).

## Staff authentication

Staff log in through their own portal at `/api/v1/staff/auth/login`. The `StaffAuthController` supports:

- Email/password login
- Google OAuth (pre-invited accounts only — unregistered Google users are rejected)
- Invitation acceptance with token validation (7-day expiry)
- Password reset and change flows
- Token management (logout, revoke all)

Staff can also log in with owner credentials — the `RoleAuthenticationService` allows users with an active `staff` or `owner` membership.
