# CourtFlex — API v1 Documentation & Route Reference

CourtFlex features a strict, role-based API route prefix architecture. All endpoints are grouped under `/api/v1/` with dedicated namespaces and server-side authorization per primary user role.

Interactive UI documentation is available locally at: `GET /docs-api-endpoints` (The web root `GET /` returns `404 Not Found`).

---

## Authentication & Multi-Tenant Identity Architecture

CourtFlex uses **Laravel Sanctum** bearer tokens for API authentication, paired with contextual role enforcement.

- **Base URL**: `/api/v1/`
- **Request Headers**:
  - `Accept: application/json`
  - `Content-Type: application/json`
  - `Authorization: Bearer <TOKEN>` (on all protected endpoints)
  - `X-Tenant-ID: <TENANT_ID_OR_SLUG>` (on tenant-scoped owner and staff endpoints)

---

## Standardized JSON Response Envelopes

Every API endpoint returns a predictable, standardized envelope.

### 1. Success Response (`200 OK`, `201 Created`)
```json
{
  "success": true,
  "message": "Operation completed successfully.",
  "data": { ... },
  "meta": null
}
```

### 2. Validation Error Response (`422 Unprocessable Content`)
```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "The email field is required."
    ]
  }
}
```

### 3. Authentication & Authorization Errors (`401 Unauthorized`, `403 Forbidden`)
```json
{
  "success": false,
  "message": "Unauthenticated. / Your account is not authorized as [owner].",
  "errors": []
}
```

### 4. Rate Limit Exceeded Response (`429 Too Many Requests`)
```json
{
  "success": false,
  "message": "Too many requests. Please try again in 58 seconds.",
  "errors": []
}
```
*Includes HTTP headers: `Retry-After: 58`, `X-RateLimit-Limit: 5`, `X-RateLimit-Remaining: 0`.*

---

## Rate Limiting Policy

CourtFlex implements named rate limiters via `RateLimiter` and the `throttle:` middleware:

| Named Limiter | Threshold | Target Routes |
| :--- | :--- | :--- |
| `throttle:login` | 5 req / min (by email + IP) | All role login endpoints |
| `throttle:register` | 5 req / min (by IP) | All role registration endpoints |
| `throttle:forgot-password` | 3 req / min (by IP) | Password recovery triggers |
| `throttle:password-reset` | 5 req / min (by IP) | Password reset completions |
| `throttle:email-verification` | 5 req / min (by user / IP) | Verification emails and code confirmations |
| `throttle:api` | 120 req / min (by user ID / IP) | General authenticated role endpoints |
| `throttle:public-api` | 60 req / min (by IP) | Public discovery endpoints |
| `throttle:resource-creation` | 30 req / min (by user / IP) | Resource stores (bookings, clubs, tournaments) |
| `throttle:sensitive` | 10 req / min (by user / IP) | Password changes, administrative status toggles |

---

## 1. Public Discovery API (`/api/v1/public/*`)

File: `routes/api/v1/public.php`  
Middleware: `throttle:public-api` (No authentication required).

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/public/facilities` | List active facilities across all tenants |
| `GET` | `/api/v1/public/facilities/{facility}` | View facility details, operating hours, and courts |
| `GET` | `/api/v1/public/courts` | List courts with hourly rates and surface types |
| `GET` | `/api/v1/public/courts/{court}` | View court details and operating hours |
| `GET` | `/api/v1/public/courts/{court}/availability?date=YYYY-MM-DD` | Check availability time-slots for a specific court |
| `GET` | `/api/v1/public/clubs` | Explore open pickleball clubs |
| `GET` | `/api/v1/public/clubs/{club}` | View club details, member count, and tenant host |
| `GET` | `/api/v1/public/open-play` | Browse scheduled open play sessions across facilities |
| `GET` | `/api/v1/public/open-play/{session}` | View open play session details and player roster |
| `GET` | `/api/v1/public/tournaments` | List upcoming, open, and completed tournaments |
| `GET` | `/api/v1/public/tournaments/{tournament}` | View tournament overview, categories, and brackets |
| `GET` | `/api/v1/public/rankings` | View global player rankings and rating leaderboards |

---

## 2. System Administrator API (`/api/v1/admin/*`)

File: `routes/api/v1/admin.php`

### Authentication Routes (`/api/v1/admin/auth/*`)
Strictly restricted to email & password credentials (Google OAuth is prohibited). Provisioned via database seeders.

| Method | Endpoint | Description | Middleware |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/admin/auth/login` | System Admin login (supports `mfa_code`) | `throttle:login` |
| `POST` | `/api/v1/admin/auth/forgot-password` | Request admin password reset token | `throttle:forgot-password` |
| `POST` | `/api/v1/admin/auth/reset-password` | Reset password using valid token | `throttle:password-reset` |
| `GET` | `/api/v1/admin/auth/me` | Current authenticated admin profile | `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api` |
| `POST` | `/api/v1/admin/auth/change-password` | Change authenticated admin password | `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:sensitive` |
| `POST` | `/api/v1/admin/auth/logout` | Revoke current admin device token | `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api` |
| `POST` | `/api/v1/admin/auth/tokens/revoke-all` | Revoke all admin sessions across devices | `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api` |

### Business & Governance Endpoints (`/api/v1/admin/*`)
Middleware: `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/dashboard` | Platform metrics: total tenants, active courts, MRR, platform bookings |
| `GET` | `/api/v1/admin/tenants` | Paginated list of tenants with filters (`status`, `search`) |
| `POST` | `/api/v1/admin/tenants` | Onboard a new facility tenant organization |
| `GET` | `/api/v1/admin/tenants/{tenant}` | View single tenant profile and facility counts |
| `PATCH`| `/api/v1/admin/tenants/{tenant}/status` | Activate, suspend, or deactivate a tenant |
| `GET` | `/api/v1/admin/subscriptions` | View tenant subscription statuses and plans |
| `POST` | `/api/v1/admin/subscription-plans` | Create a new SaaS pricing tier / plan |
| `GET` | `/api/v1/admin/analytics` | Revenue charts and booking volume analytics |
| `GET` | `/api/v1/admin/audit-logs` | Filterable security and administrative audit trails |
| `GET` | `/api/v1/admin/settings` | Platform-wide operational configurations |

---

## 3. Facility Owner API (`/api/v1/owners/*`)

File: `routes/api/v1/owners.php`

### Authentication Routes (`/api/v1/owners/auth/*`)
Public registration with atomic creation of facility tenant, owner user, and default operating roles.

| Method | Endpoint | Description | Middleware |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/owners/auth/register` | Register owner and provision new tenant | `throttle:register` |
| `POST` | `/api/v1/owners/auth/login` | Owner email & password login | `throttle:login` |
| `POST` | `/api/v1/owners/auth/google` | Owner Google OAuth authentication | `throttle:login` |
| `POST` | `/api/v1/owners/auth/forgot-password` | Request password reset token | `throttle:forgot-password` |
| `POST` | `/api/v1/owners/auth/reset-password` | Reset password using valid token | `throttle:password-reset` |
| `POST` | `/api/v1/owners/auth/verify-email` | Verify email address | `throttle:email-verification` |
| `GET` | `/api/v1/owners/auth/me` | Owner profile and tenant membership context | `auth:sanctum`, `account.active`, `throttle:api` |
| `POST` | `/api/v1/owners/auth/change-password` | Change owner password | `auth:sanctum`, `account.active`, `throttle:sensitive` |
| `POST` | `/api/v1/owners/auth/logout` | Revoke owner session token | `auth:sanctum`, `account.active`, `throttle:api` |
| `POST` | `/api/v1/owners/auth/tokens/revoke-all` | Revoke all owner sessions across devices | `auth:sanctum`, `account.active`, `throttle:api` |

### Business & Operations Endpoints (`/api/v1/owners/*`)
Middleware: `auth:sanctum`, `account.active`, `tenant.context`, `tenant.access`, `role:owner`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/owners/dashboard` | Facility revenue, court utilization, and bookings overview |
| `GET` | `/api/v1/owners/facilities` | List facilities owned by the tenant |
| `POST` | `/api/v1/owners/facilities` | Create a new facility venue |
| `GET` | `/api/v1/owners/facilities/{facility}` | View facility details and court lists |
| `PATCH`| `/api/v1/owners/facilities/{facility}` | Update facility venue information |
| `DELETE`| `/api/v1/owners/facilities/{facility}` | Soft-delete a facility |
| `GET` | `/api/v1/owners/courts` | List courts belonging to owner facilities |
| `POST` | `/api/v1/owners/courts` | Add a new court with hourly rates |
| `GET` | `/api/v1/owners/courts/{court}` | View court details and schedules |
| `PATCH`| `/api/v1/owners/courts/{court}` | Update court rate, surface, or active status |
| `DELETE`| `/api/v1/owners/courts/{court}` | Soft-delete a court |
| `GET` | `/api/v1/owners/staff` | List staff members assigned to tenant |
| `POST` | `/api/v1/owners/staff` | Invite staff member to facility via email |
| `DELETE`| `/api/v1/owners/staff/{user}` | Remove staff member from facility tenant |
| `GET` | `/api/v1/owners/bookings` | View tenant bookings with participant details |
| `GET` | `/api/v1/owners/bookings/{booking}` | View single reservation details |
| `GET` | `/api/v1/owners/payments` | List tenant payment transactions |
| `POST` | `/api/v1/owners/payments/{payment}/verify` | Approve or reject customer payment receipts |
| `GET` | `/api/v1/owners/reports` | Monthly revenue and court utilization reports |

---

## 4. Facility Staff Operations API (`/api/v1/staff/*`)

File: `routes/api/v1/staff.php`

### Authentication Routes (`/api/v1/staff/auth/*`)
Staff accounts are **invitation-only** (invited by Facility Owners). No open registration.

| Method | Endpoint | Description | Middleware |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/staff/auth/login` | Staff email & password login | `throttle:login` |
| `POST` | `/api/v1/staff/auth/google` | Staff Google OAuth login (pre-invited only) | `throttle:login` |
| `POST` | `/api/v1/staff/auth/accept-invitation` | Accept invite token and set password | `guest` |
| `POST` | `/api/v1/staff/auth/forgot-password` | Request password reset token | `throttle:forgot-password` |
| `POST` | `/api/v1/staff/auth/reset-password` | Reset password using valid token | `throttle:password-reset` |
| `GET` | `/api/v1/staff/auth/me` | Staff profile and facility assignment | `auth:sanctum`, `account.active`, `throttle:api` |
| `POST` | `/api/v1/staff/auth/change-password` | Change staff password | `auth:sanctum`, `account.active`, `throttle:sensitive` |
| `POST` | `/api/v1/staff/auth/logout` | Revoke staff session token | `auth:sanctum`, `account.active`, `throttle:api` |
| `POST` | `/api/v1/staff/auth/tokens/revoke-all` | Revoke all staff sessions | `auth:sanctum`, `account.active`, `throttle:api` |

### Business Endpoints (`/api/v1/staff/*`)
Middleware: `auth:sanctum`, `account.active`, `tenant.context`, `tenant.access`, `role:staff`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/staff/dashboard` | Shift view: today's court reservations and check-in queues |
| `GET` | `/api/v1/staff/bookings` | View daily facility bookings and statuses |
| `GET` | `/api/v1/staff/bookings/{booking}` | View reservation and customer details |
| `PATCH`| `/api/v1/staff/bookings/{booking}/status` | Mark booking completed or cancelled |
| `GET` | `/api/v1/staff/payments/pending` | View unverified customer payment proofs |
| `POST` | `/api/v1/staff/payments/{payment}/verify` | Verify proof of payment and mark booking confirmed |
| `POST` | `/api/v1/staff/bookings/{booking}/check-in` | Check in players at facility reception desk |

---

## 5. Player API (`/api/v1/players/*`)

File: `routes/api/v1/players.php`

### Authentication Routes (`/api/v1/players/auth/*`)
Open public registration. Player profiles are global across all facility tenants.

| Method | Endpoint | Description | Middleware |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/players/auth/register` | Global player account registration | `throttle:register` |
| `POST` | `/api/v1/players/auth/login` | Player email & password login | `throttle:login` |
| `POST` | `/api/v1/players/auth/google` | Player Google OAuth login / registration | `throttle:login` |
| `POST` | `/api/v1/players/auth/forgot-password` | Request password reset link | `throttle:forgot-password` |
| `POST` | `/api/v1/players/auth/reset-password` | Reset password using valid token | `throttle:password-reset` |
| `POST` | `/api/v1/players/auth/verify-email` | Verify player email | `throttle:email-verification` |
| `GET` | `/api/v1/players/auth/me` | Current player profile and statistics | `auth:sanctum`, `account.active`, `throttle:api` |
| `POST` | `/api/v1/players/auth/change-password` | Change player password | `auth:sanctum`, `account.active`, `throttle:sensitive` |
| `POST` | `/api/v1/players/auth/email/verification-notification` | Resend email verification notification | `auth:sanctum`, `account.active`, `throttle:email-verification` |
| `POST` | `/api/v1/players/auth/logout` | Revoke current player session token | `auth:sanctum`, `account.active`, `throttle:api` |
| `POST` | `/api/v1/players/auth/tokens/revoke-all` | Revoke all player sessions across devices | `auth:sanctum`, `account.active`, `throttle:api` |

### Business Endpoints (`/api/v1/players/*`)
Middleware: `auth:sanctum`, `account.active`, `role:player`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/players/dashboard` | Player home: upcoming reservations, joined clubs, stats |
| `GET` | `/api/v1/players/profile` | View player profile |
| `PATCH`| `/api/v1/players/profile` | Update player name and phone |
| `POST` | `/api/v1/players/profile/avatar` | Upload player avatar image (PNG/JPEG/WEBP, max 2MB) |
| `GET` | `/api/v1/players/bookings` | View player court reservations |
| `POST` | `/api/v1/players/bookings` | Reserve a court slot |
| `GET` | `/api/v1/players/bookings/{booking}` | View reservation details |
| `POST` | `/api/v1/players/bookings/{booking}/cancel` | Cancel personal court reservation |
| `GET` | `/api/v1/players/clubs` | List joined clubs |
| `POST` | `/api/v1/players/clubs/{club}/join` | Request to join a club (max 3 active clubs) |
| `GET` | `/api/v1/players/open-play` | View open play sessions joined by player |
| `POST` | `/api/v1/players/open-play/{session}/join` | Join an open play session |
| `GET` | `/api/v1/players/tournaments` | View tournaments entered by player |
| `POST` | `/api/v1/players/tournaments/{tournament}/register` | Register for a tournament category |
| `GET` | `/api/v1/players/rankings` | View personal ranking history and ratings |
| `GET` | `/api/v1/players/statistics` | View player win/loss count and rating stats |
| `GET` | `/api/v1/players/payment-history` | View personal billing and payment transactions |
| `POST` | `/api/v1/players/payments/{payment}/proof` | Upload payment receipt image / proof |

---

## 6. Club Manager / Organizer API (`/api/v1/organizers/*`)

File: `routes/api/v1/organizers.php`

### Authentication Routes (`/api/v1/organizers/auth/*`)
Club organizers are assigned by Facility Owners or Club Managers.

| Method | Endpoint | Description | Middleware |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/organizers/auth/login` | Organizer login | `throttle:login` |
| `POST` | `/api/v1/organizers/auth/google` | Organizer Google OAuth login | `throttle:login` |
| `POST` | `/api/v1/organizers/auth/accept-assignment` | Accept club organizer invitation token | `auth:sanctum`, `account.active`, `throttle:api` |
| `POST` | `/api/v1/organizers/auth/forgot-password` | Request password reset token | `throttle:forgot-password` |
| `POST` | `/api/v1/organizers/auth/reset-password` | Reset password using valid token | `throttle:password-reset` |
| `GET` | `/api/v1/organizers/auth/me` | Organizer profile and managed club roster | `auth:sanctum`, `account.active`, `throttle:api` |
| `POST` | `/api/v1/organizers/auth/change-password` | Change organizer password | `auth:sanctum`, `account.active`, `throttle:sensitive` |
| `POST` | `/api/v1/organizers/auth/logout` | Revoke organizer session token | `auth:sanctum`, `account.active`, `throttle:api` |
| `POST` | `/api/v1/organizers/auth/tokens/revoke-all` | Revoke all organizer sessions across devices | `auth:sanctum`, `account.active`, `throttle:api` |

### Business Endpoints (`/api/v1/organizers/*`)
Middleware: `auth:sanctum`, `account.active`, `role:club_manager,owner,system_admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/organizers/dashboard` | Managed clubs and active tournament summaries |
| `GET` | `/api/v1/organizers/clubs` | List managed clubs |
| `POST` | `/api/v1/organizers/clubs` | Create a new club |
| `GET` | `/api/v1/organizers/clubs/{club}` | View club details |
| `PATCH`| `/api/v1/organizers/clubs/{club}` | Update club details |
| `DELETE`| `/api/v1/organizers/clubs/{club}` | Soft-delete a club |
| `GET` | `/api/v1/organizers/clubs/{club}/members` | View club membership roster |
| `POST` | `/api/v1/organizers/clubs/{club}/members/{player}/approve` | Approve a pending club member |
| `GET` | `/api/v1/organizers/tournaments` | List organized tournaments |
| `POST` | `/api/v1/organizers/tournaments` | Create a new tournament |
| `GET` | `/api/v1/organizers/tournaments/{tournament}` | View tournament bracket and details |
| `PATCH`| `/api/v1/organizers/tournaments/{tournament}` | Update tournament schedule or rules |
| `DELETE`| `/api/v1/organizers/tournaments/{tournament}` | Soft-delete a tournament |
| `POST` | `/api/v1/organizers/tournaments/{tournament}/matches/{match}/results` | Record match score and winner |
