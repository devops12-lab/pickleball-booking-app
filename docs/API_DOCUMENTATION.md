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

## Full Name Validation Policy

All name fields that represent a person's real name enforce a **minimum 2-word requirement** (first name and surname). This applies to:

- Player registration (`POST /api/v1/players/auth/register`)
- Owner registration (`POST /api/v1/owners/auth/register`)
- Staff creation (`POST /api/v1/owners/staff`)
- Staff updates (`PUT /api/v1/owners/staff/{user}`)
- Profile updates (`PATCH /api/v1/players/profile`)
- Staff invitation acceptance (`POST /api/v1/staff/auth/accept-invitation`)

Single-word names like "John" are rejected with: *"The name must include a first name and surname (at least 2 words)."*

This does **not** apply to Google OAuth flows (names come from the Google profile) or to entity names (clubs, facilities, courts, tournaments).

---

## Player In-Game Name

Players can optionally set an **in-game name** (`in_game_name`) — a unique gaming handle displayed in matches and rankings, separate from their real name.

- Set during registration: include `in_game_name` in the `POST /api/v1/players/auth/register` body
- Update later: include `in_game_name` in `PATCH /api/v1/players/profile`
- Must be unique across all players, max 50 characters
- Stored on the `player_profiles` table (not the `users` table)

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
| `GET` | `/api/v1/public/open-play/{session}/results` | View final results (top 3 winners) for a completed open-play session |
| `GET` | `/api/v1/public/tournaments` | List upcoming, open, and completed tournaments |
| `GET` | `/api/v1/public/tournaments/{tournament}` | View tournament overview, categories, and brackets |
| `GET` | `/api/v1/public/tournaments/{tournament}/results` | View final results (1st, 2nd, 3rd place) for a completed tournament |
| `GET` | `/api/v1/public/rankings` | View global player rankings and rating leaderboards |
| `GET` | `/api/v1/public/app-config` | Public platform settings (name, logo, timezone, etc.) |
| `GET` | `/api/v1/public/tenants/{tenant}/court-front` | View facility storefront (logo, cover, tagline) |

### Public Guest Bookings (`/api/v1/public/guest-bookings/*`)
No authentication required. Code-verified email flow.

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `POST` | `/api/v1/public/guest-bookings/code` | Request a verification code (sent to email) |
| `POST` | `/api/v1/public/guest-bookings` | Submit a guest booking with verified code |
| `POST` | `/api/v1/public/guest-bookings/find` | Look up a guest booking by email and code |
| `POST` | `/api/v1/public/guest-bookings/cancel` | Cancel a guest booking by email and code |

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
| `GET` | `/api/v1/admin/audit-logs` | Filterable security and administrative audit trails |

### Analytics Endpoints (`/api/v1/admin/analytics/*`)
Middleware: `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/analytics` | Legacy aggregate analytics |
| `GET` | `/api/v1/admin/analytics/overview` | Platform-wide summary metrics |
| `GET` | `/api/v1/admin/analytics/users` | User growth and registration trends |
| `GET` | `/api/v1/admin/analytics/facilities` | Facility acquisition and usage |
| `GET` | `/api/v1/admin/analytics/bookings` | Booking volume and trends |
| `GET` | `/api/v1/admin/analytics/revenue` | Revenue breakdown and growth |
| `GET` | `/api/v1/admin/analytics/subscriptions` | Subscription plan distribution and MRR |
| `GET` | `/api/v1/admin/analytics/clubs` | Club growth and engagement |
| `GET` | `/api/v1/admin/analytics/tournaments` | Tournament activity metrics |
| `GET` | `/api/v1/admin/analytics/advertisements` | Ad performance, impressions, clicks |

All analytics endpoints accept optional `start_date`, `end_date`, and `period` (daily/weekly/monthly/quarterly/yearly) query parameters.

### App Settings Endpoints (`/api/v1/admin/settings/*`)
Middleware: `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/settings/app` | Retrieve all platform settings |
| `PUT` | `/api/v1/admin/settings/app` | Update platform settings (name, logo, timezone, etc.) |
| `GET` | `/api/v1/admin/settings/socials` | List platform social links |
| `POST` | `/api/v1/admin/settings/socials` | Add a platform social link (max 10) |
| `PUT` | `/api/v1/admin/settings/socials/{index}` | Update a platform social link |
| `DELETE`| `/api/v1/admin/settings/socials/{index}` | Remove a platform social link |

### Advertisement Management Endpoints (`/api/v1/admin/advertisements/*`)
Middleware: `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/advertisements` | List all advertisements |
| `POST` | `/api/v1/admin/advertisements` | Create a new advertisement |
| `GET` | `/api/v1/admin/advertisements/requests` | List ad requests from owners/organizers |
| `GET` | `/api/v1/admin/advertisements/requests/{req}` | View ad request details |
| `PATCH` | `/api/v1/admin/advertisements/requests/{req}/review` | Approve or reject an ad request |
| `GET` | `/api/v1/admin/advertisements/{ad}` | View ad details and performance |
| `PATCH` | `/api/v1/admin/advertisements/{ad}` | Update advertisement content |
| `DELETE`| `/api/v1/admin/advertisements/{ad}` | Delete an advertisement |
| `POST` | `/api/v1/admin/advertisements/{ad}/publish` | Publish an advertisement |
| `POST` | `/api/v1/admin/advertisements/{ad}/pause` | Pause an advertisement |
| `PATCH` | `/api/v1/admin/advertisements/{ad}/status` | Change ad status directly |

### Subscription Plan Management (`/api/v1/admin/subscription-plans/*`)
Middleware: `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/subscription-plans` | List all subscription plans |
| `POST` | `/api/v1/admin/subscription-plans` | Create a new plan |
| `GET` | `/api/v1/admin/subscription-plans/{code}` | View plan details by code |
| `PATCH` | `/api/v1/admin/subscription-plans/{code}` | Update plan limits and pricing |
| `DELETE`| `/api/v1/admin/subscription-plans/{code}` | Delete a subscription plan |

### Billing & Revenue (`/api/v1/admin/*`)
Middleware: `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/plans` | List compat billing plans |
| `POST` | `/api/v1/admin/plans` | Create a compat billing plan |
| `PATCH` | `/api/v1/admin/plans/{code}` | Update a compat billing plan |
| `GET` | `/api/v1/admin/subscriptions` | List tenant subscriptions |
| `GET` | `/api/v1/admin/subscriptions/{subscription}` | View subscription details |
| `POST` | `/api/v1/admin/subscriptions/{subscription}/extend-trial` | Extend a subscription trial period |
| `POST` | `/api/v1/admin/subscriptions/{subscription}/change-plan` | Change a subscription's plan |
| `GET` | `/api/v1/admin/invoices` | List all invoices |
| `POST` | `/api/v1/admin/invoices/{invoice}/mark-paid` | Mark an invoice as paid |
| `POST` | `/api/v1/admin/invoices/{invoice}/void` | Void an invoice |
| `GET` | `/api/v1/admin/revenue` | Platform revenue summary |

### Disputes & Ratings (`/api/v1/admin/*`)
Middleware: `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/disputes` | List disputed match results |
| `POST` | `/api/v1/admin/matches/{match}/resolve` | Resolve a disputed match |
| `POST` | `/api/v1/admin/matches/{match}/reverse` | Reverse a match result |
| `GET` | `/api/v1/admin/ratings` | View player rating list |
| `GET` | `/api/v1/admin/ratings/adjustments` | View manual rating adjustments |
| `POST` | `/api/v1/admin/ratings/adjust` | Apply a manual rating correction |
| `GET` | `/api/v1/admin/rating-rules` | View ELO rating rules |
| `PUT` | `/api/v1/admin/rating-rules` | Update ELO rating rules |

### Venue & Club Governance (`/api/v1/admin/*`)
Middleware: `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/venues` | List all venues |
| `GET` | `/api/v1/admin/venues/{venue}` | View venue details |
| `POST` | `/api/v1/admin/venues/{venue}/decision` | Approve or reject a venue |
| `GET` | `/api/v1/admin/clubs` | List all clubs |
| `GET` | `/api/v1/admin/clubs/{club}` | View club details |
| `POST` | `/api/v1/admin/clubs` | Create a club (admin) |
| `PATCH` | `/api/v1/admin/clubs/{club}/admin` | Update a club (admin) |
| `DELETE`| `/api/v1/admin/clubs/{club}` | Delete a club |
| `POST` | `/api/v1/admin/clubs/{club}/decision` | Approve or reject a club |

### User Management (`/api/v1/admin/users/*`)
Middleware: `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/users` | List all users with filters |
| `GET` | `/api/v1/admin/users/{user}` | View user details |
| `PATCH` | `/api/v1/admin/users/{user}/status` | Update user account status |
| `POST` | `/api/v1/admin/users/{user}/password-reset` | Send password reset email to user |

### Tenant Management (`/api/v1/admin/tenants/*`)
Middleware: `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/tenants` | List all tenants |
| `POST` | `/api/v1/admin/tenants` | Create a new tenant |
| `GET` | `/api/v1/admin/tenants/{tenant}` | View tenant details |
| `PATCH` | `/api/v1/admin/tenants/{tenant}/status` | Update tenant status |

### Platform Settings & Announcements (`/api/v1/admin/*`)
Middleware: `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/audit-logs` | Filterable audit trail |
| `GET` | `/api/v1/admin/announcements` | List announcements |
| `POST` | `/api/v1/admin/announcements` | Create an announcement |
| `PATCH` | `/api/v1/admin/announcements/{announcement}` | Update an announcement |
| `POST` | `/api/v1/admin/announcements/{announcement}/end` | End an announcement |
| `GET` | `/api/v1/admin/settings` | View platform settings |
| `PUT` | `/api/v1/admin/settings` | Update platform settings |
| `GET` | `/api/v1/admin/email-settings` | View email configuration |
| `PUT` | `/api/v1/admin/email-settings` | Update email configuration |
| `POST` | `/api/v1/admin/email-settings/test` | Send test email |

### Database Backups (`/api/v1/admin/database-backups/*`)
Middleware: `auth:sanctum`, `account.active`, `role:system-admin`, `throttle:api` + permission middleware

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/admin/database-backups` | List backups |
| `POST` | `/api/v1/admin/database-backups` | Create a backup |
| `POST` | `/api/v1/admin/database-backups/upload` | Upload a backup file |
| `POST` | `/api/v1/admin/database-backups/restore-upload` | Restore from uploaded backup |
| `GET` | `/api/v1/admin/database-backups/monitoring` | Backup health monitoring |
| `GET` | `/api/v1/admin/database-backups/operations` | List restore operations |
| `GET` | `/api/v1/admin/database-backups/operations/{restoreLog}` | View restore operation details |
| `GET` | `/api/v1/admin/database-backups/audit-logs` | Backup audit logs |
| `GET` | `/api/v1/admin/database-backups/{backup}` | View backup details |
| `GET` | `/api/v1/admin/database-backups/{backup}/download` | Download a backup |
| `POST` | `/api/v1/admin/database-backups/{backup}/restore` | Restore from a backup |
| `DELETE`| `/api/v1/admin/database-backups/{backup}` | Delete a backup |
| `GET` | `/api/v1/admin/backup-schedules` | List backup schedules |
| `PATCH` | `/api/v1/admin/backup-schedules/{schedule}` | Update a backup schedule |
| `POST` | `/api/v1/admin/backup-schedules/{schedule}/run` | Run a backup schedule now |

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
| `POST` | `/api/v1/owners/staff` | Add/invite staff member to facility |
| `GET` | `/api/v1/owners/staff/{user}` | View staff member details |
| `PUT` | `/api/v1/owners/staff/{user}` | Update staff name, phone, or role |
| `PATCH` | `/api/v1/owners/staff/{user}/status` | Activate or deactivate staff member |
| `PUT` | `/api/v1/owners/staff/{user}/credentials` | Update staff email or password (revokes tokens) |
| `DELETE`| `/api/v1/owners/staff/{user}` | Remove staff member from facility tenant |
| `GET` | `/api/v1/owners/bookings` | View tenant bookings with participant details |
| `GET` | `/api/v1/owners/bookings/{booking}` | View single reservation details |
| `GET` | `/api/v1/owners/payments` | List tenant payment transactions |
| `POST` | `/api/v1/owners/payments/{payment}/verify` | Approve or reject customer payment receipts |
| `GET` | `/api/v1/owners/reports` | Monthly revenue and court utilization reports |

### Guest Bookings (`/api/v1/owners/guest-bookings/*`)
Middleware: `auth:sanctum`, `account.active`, `tenant.context`, `tenant.access`, `role:owner`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/owners/guest-bookings` | List guest (walk-in) bookings |
| `POST` | `/api/v1/owners/guest-bookings` | Create a walk-in guest booking |
| `GET` | `/api/v1/owners/guest-bookings/{booking}` | View guest booking details |
| `POST` | `/api/v1/owners/guest-bookings/{booking}/cancel` | Cancel a guest booking |

### Payment Methods (`/api/v1/owners/payment-methods/*`)
Middleware: `auth:sanctum`, `account.active`, `tenant.context`, `tenant.access`, `role:owner`, `permission:payment_methods.manage`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/owners/payment-methods` | List tenant payment methods |
| `POST` | `/api/v1/owners/payment-methods` | Add a payment method (GCash, Maya, bank, etc.) |
| `GET` | `/api/v1/owners/payment-methods/{method}` | View payment method details |
| `PATCH` | `/api/v1/owners/payment-methods/{method}` | Update payment method |
| `DELETE`| `/api/v1/owners/payment-methods/{method}` | Remove a payment method |

### Court Front / Storefront (`/api/v1/owners/court-front/*`)
Middleware: `auth:sanctum`, `account.active`, `tenant.context`, `tenant.access`, `role:owner`, `permission:court_front.manage`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/owners/court-front` | View storefront settings |
| `PATCH` | `/api/v1/owners/court-front` | Update storefront tagline, description, contact info |
| `POST` | `/api/v1/owners/court-front/logo` | Upload storefront logo |
| `POST` | `/api/v1/owners/court-front/cover` | Upload storefront cover image |

### Storefront Social Links (`/api/v1/owners/storefront/social-links/*`)
Middleware: `auth:sanctum`, `account.active`, `tenant.context`, `tenant.access`, `role:owner`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/owners/storefront/social-links` | List storefront social links |
| `POST` | `/api/v1/owners/storefront/social-links` | Add a social link |
| `PATCH` | `/api/v1/owners/storefront/social-links/{link}` | Update a social link |
| `DELETE`| `/api/v1/owners/storefront/social-links/{link}` | Remove a social link |
| `POST` | `/api/v1/owners/storefront/social-links/reorder` | Reorder social links |

### Subscription Management (`/api/v1/owners/subscription/*`)
Middleware: `auth:sanctum`, `account.active`, `tenant.context`, `tenant.access`, `role:owner`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/owners/subscription` | View current subscription and plan |
| `POST` | `/api/v1/owners/subscription/subscribe` | Subscribe to a plan |
| `PUT` | `/api/v1/owners/subscription/billing-interval` | Change billing interval |
| `POST` | `/api/v1/owners/subscription/upgrade` | Upgrade to a higher plan |
| `POST` | `/api/v1/owners/subscription/downgrade` | Downgrade to a lower plan |
| `POST` | `/api/v1/owners/subscription/cancel` | Cancel subscription |
| `GET` | `/api/v1/owners/subscription/invoices` | View billing history |
| `GET` | `/api/v1/owners/subscription/usage` | View resource usage vs limits |

### Advertisement Requests (`/api/v1/owners/advertisement-requests/*`)
Middleware: `auth:sanctum`, `account.active`, `tenant.context`, `tenant.access`, `role:owner`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/owners/advertisement-requests` | List submitted ad requests |
| `POST` | `/api/v1/owners/advertisement-requests` | Submit an advertising request |
| `GET` | `/api/v1/owners/advertisement-requests/{req}` | View ad request details |
| `GET` | `/api/v1/owners/advertisements` | View active ads for this portal |

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
| `GET` | `/api/v1/staff/advertisements` | View active ads for staff portal |

### Staff Guest Bookings (`/api/v1/staff/guest-bookings/*`)
Middleware: `auth:sanctum`, `account.active`, `tenant.context`, `tenant.access`, `role:staff`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/staff/guest-bookings` | List guest (walk-in) bookings |
| `POST` | `/api/v1/staff/guest-bookings` | Create a walk-in guest booking |
| `GET` | `/api/v1/staff/guest-bookings/{booking}` | View guest booking details |
| `POST` | `/api/v1/staff/guest-bookings/{booking}/cancel` | Cancel a guest booking |

---

## 5. Player API (`/api/v1/players/*`)

File: `routes/api/v1/players.php`

### Authentication Routes (`/api/v1/players/auth/*`)
Open public registration. Player profiles are global across all facility tenants.

| Method | Endpoint | Description | Middleware |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v1/players/auth/register` | Global player account registration (accepts optional `in_game_name`) | `throttle:register` |
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
| `GET` | `/api/v1/players/tenants/{tenant}/payment-methods` | View facility's accepted payment methods |
| `GET` | `/api/v1/players/clubs/available` | List available clubs to join |
| `DELETE`| `/api/v1/players/clubs/{club}/leave` | Leave a club |
| `DELETE`| `/api/v1/players/profile/avatar` | Remove player avatar |
| `GET` | `/api/v1/players/advertisements` | View active ads for player portal |

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

### Organizer Advertisement Requests (`/api/v1/organizers/advertisement-requests/*`)
Middleware: `auth:sanctum`, `account.active`, `role:club_manager,owner,system_admin`, `throttle:api`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/organizers/advertisement-requests` | List submitted ad requests |
| `POST` | `/api/v1/organizers/advertisement-requests` | Submit an advertising request |
| `GET` | `/api/v1/organizers/advertisement-requests/{req}` | View ad request details |
| `GET` | `/api/v1/organizers/advertisements` | View active ads for organizer portal |

---

## 7. Shared Authenticated Services (`/api/v1/*`)

File: `routes/api.php`
Middleware: `auth:sanctum`, `account.active`, `throttle:api` (any authenticated role)

### Form Drafts (`/api/v1/form-drafts/*`)

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/form-drafts` | List user's form drafts |
| `POST` | `/api/v1/form-drafts` | Save a form draft |
| `GET` | `/api/v1/form-drafts/{formDraft}` | Retrieve a form draft |
| `PATCH` | `/api/v1/form-drafts/{formDraft}` | Update a form draft |
| `DELETE`| `/api/v1/form-drafts/{formDraft}` | Delete a form draft |

### Advertisement Tracking

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `POST` | `/api/v1/advertisements/{advertisement}/impression` | Record an ad impression |
| `POST` | `/api/v1/advertisements/{advertisement}/click` | Record an ad click |

---

## 8. SPA Compatibility API (`/api/v1/*`)

File: `routes/api/v1/compat.php`

The SPA frontend calls resource-first URLs. These routes serve the same data as the role-scoped API but under a flat URL structure. A "club" in this context is a venue tenant.

### Authentication (unauthenticated)

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `POST` | `/api/v1/auth/login` | Universal login (any role) |
| `POST` | `/api/v1/auth/register` | Player registration |
| `POST` | `/api/v1/auth/register/check-email` | Check email availability |
| `POST` | `/api/v1/auth/register/start` | Start registration (send OTP) |
| `POST` | `/api/v1/auth/register/verify` | Verify registration OTP |
| `POST` | `/api/v1/auth/register/resend` | Resend registration OTP |
| `POST` | `/api/v1/auth/register/complete` | Complete registration |
| `POST` | `/api/v1/auth/forgot-password` | Request password reset |
| `POST` | `/api/v1/auth/reset-password` | Reset password |
| `POST` | `/api/v1/owner-signup/code` | Owner signup verification code |
| `POST` | `/api/v1/owner-signup/verify` | Verify owner signup code |
| `POST` | `/api/v1/owner-signup` | Complete owner signup |

### Public Browsing (unauthenticated)

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/platform/settings` | Platform settings |
| `GET` | `/api/v1/announcements` | Public announcements |
| `GET` | `/api/v1/tournaments` | Browse tournaments |
| `GET` | `/api/v1/tournaments/{tournament}` | View tournament details |
| `GET` | `/api/v1/open-play` | Browse open play sessions |
| `GET` | `/api/v1/open-play/{session}` | View open play session |
| `GET` | `/api/v1/rankings` | View rankings |
| `GET` | `/api/v1/rankings/areas` | View ranking areas |
| `GET` | `/api/v1/geo/{level}` | Geographic data lookup |
| `GET` | `/api/v1/discovery/amenities` | Facility amenity list |
| `GET` | `/api/v1/discovery/courts` | Discover courts with filters |
| `GET` | `/api/v1/courts/{court}` | Public court details |
| `GET` | `/api/v1/courts/{court}/availability` | Public court availability |
| `GET` | `/api/v1/venues/{slug}/booking-day` | Venue booking calendar |
| `GET` | `/api/v1/organizers/{type}/{id}/sessions` | Organizer's open play sessions |
| `GET` | `/api/v1/bookings/quote` | Get booking price quote |
| `GET` | `/api/v1/matches/{match}` | Public match details |
| `GET` | `/api/v1/matches/{match}/events` | Match event / point history |

### Guest Bookings (unauthenticated)

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `POST` | `/api/v1/guest/code` | Request guest booking verification code |
| `POST` | `/api/v1/guest/bookings` | Create a guest booking |
| `POST` | `/api/v1/guest/booking` | Find guest booking by code |
| `POST` | `/api/v1/guest/booking/receipt` | Email a guest booking receipt |
| `POST` | `/api/v1/guest/booking/cancel` | Cancel a guest booking |

### Authenticated Routes
Middleware: `auth:sanctum`, `compat.active`

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `POST` | `/api/v1/auth/logout` | Logout |
| `POST` | `/api/v1/auth/verify` | Verify email |
| `POST` | `/api/v1/auth/verify/resend` | Resend verification |
| `GET` | `/api/v1/me` | Current user profile |
| `GET` | `/api/v1/notifications` | List notifications |
| `POST` | `/api/v1/notifications/read-all` | Mark all as read |
| `POST` | `/api/v1/notifications/{notification}/read` | Mark one as read |
| `GET` | `/api/v1/notification-preferences` | View notification preferences |
| `PUT` | `/api/v1/notification-preferences` | Update notification preferences |

### Player Flows

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/player/clubs` | Player's joined clubs |
| `GET` | `/api/v1/players` | Search players |
| `GET` | `/api/v1/players/{player}/stats` | Player statistics |
| `GET` | `/api/v1/player/profile` | Player profile |
| `PATCH` | `/api/v1/player/profile` | Update player profile |
| `PATCH` | `/api/v1/player/location` | Update player location |
| `GET` | `/api/v1/player/home` | Player home screen data |
| `GET` | `/api/v1/player/bookings` | Player booking history |
| `POST` | `/api/v1/bookings` | Create a booking |
| `GET` | `/api/v1/bookings/{booking}` | View booking details |
| `POST` | `/api/v1/bookings/{booking}/receipt` | Send booking receipt |
| `POST` | `/api/v1/bookings/{booking}/cancel` | Cancel a booking |

### Match Scoring

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/player/matches` | Player's match history |
| `POST` | `/api/v1/matches` | Create a match |
| `POST` | `/api/v1/matches/{match}/start` | Start a match |
| `POST` | `/api/v1/matches/{match}/score-events` | Record a score event |
| `POST` | `/api/v1/matches/{match}/undo` | Undo last score event |
| `POST` | `/api/v1/matches/{match}/submit-result` | Submit match result |
| `POST` | `/api/v1/matches/{match}/confirm-result` | Confirm match result |
| `POST` | `/api/v1/matches/{match}/dispute` | Dispute match result |
| `POST` | `/api/v1/matches/{match}/verify` | Verify match result |
| `POST` | `/api/v1/matches/{match}/scorer` | Change match scorer |
| `POST` | `/api/v1/matches/{match}/void` | Void a match |

### Community & Clubs

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/discovery/clubs` | Browse communities |
| `GET` | `/api/v1/discovery/clubs/{type}/{slug}` | View community details |
| `POST` | `/api/v1/communities` | Create a community |
| `PATCH` | `/api/v1/communities/{club}` | Update a community |
| `GET` | `/api/v1/venues/{venue}/court-options` | Court options for a venue |
| `POST` | `/api/v1/organizers/{type}/{id}/join` | Join an organization |
| `POST` | `/api/v1/organizers/{type}/{id}/leave` | Leave an organization |
| `GET` | `/api/v1/organizers/{type}/{id}/members` | View organization members |
| `GET` | `/api/v1/organizers/{type}/{id}/members/{member}` | View member details |
| `POST` | `/api/v1/organizers/{type}/{id}/members/{member}/dues` | Record member dues |
| `POST` | `/api/v1/organizers/{type}/{id}/members/{member}/{action}` | Approve/reject member |
| `GET` | `/api/v1/organizers/{type}/{id}/plans` | View membership plans |
| `POST` | `/api/v1/organizers/{type}/{id}/plans` | Create a membership plan |
| `PATCH` | `/api/v1/organizers/{type}/{id}/plans/{plan}` | Update a membership plan |
| `GET` | `/api/v1/organizers/{type}/{id}/payments` | View organization payments |
| `GET` | `/api/v1/organizers/{type}/{id}/reports/revenue` | Organization revenue report |

### Tournaments & Open Play

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/tournaments/hosting-options` | Tournament hosting options |
| `POST` | `/api/v1/tournaments` | Create a tournament |
| `POST` | `/api/v1/tournaments/{tournament}/register` | Register for tournament |
| `POST` | `/api/v1/tournaments/{tournament}/withdraw` | Withdraw from tournament |
| `POST` | `/api/v1/tournaments/{tournament}/start` | Start a tournament |
| `POST` | `/api/v1/tournaments/{tournament}/cancel` | Cancel a tournament |
| `POST` | `/api/v1/open-play` | Create an open play session |
| `PATCH` | `/api/v1/open-play/{session}` | Update open play session |
| `POST` | `/api/v1/open-play/{session}/participants` | Add participant |
| `POST` | `/api/v1/open-play/{session}/participants/{participant}/payments` | Record participant payment |
| `POST` | `/api/v1/open-play/{session}/participants/{participant}/refund` | Refund participant |
| `POST` | `/api/v1/open-play/{session}/participants/{participant}/{action}` | Participant action |
| `POST` | `/api/v1/open-play/{session}/court-fee` | Set court fee |
| `POST` | `/api/v1/open-play/{session}/{action}` | Session action (start, end, etc.) |
| `GET` | `/api/v1/open-play/{session}/queue` | View session queue |
| `POST` | `/api/v1/open-play/{session}/queue/{command}` | Queue command (rotate, skip, etc.) |

### Venue Management (owner context)

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/v1/clubs/{club}` | View venue details |
| `PATCH` | `/api/v1/clubs/{club}` | Update venue details |
| `GET` | `/api/v1/clubs/{club}/courts` | List venue courts |
| `POST` | `/api/v1/clubs/{club}/courts` | Create a court |
| `GET` | `/api/v1/clubs/{club}/courts/{court}` | View court details |
| `PATCH` | `/api/v1/clubs/{club}/courts/{court}` | Update court |
| `PUT` | `/api/v1/clubs/{club}/courts/{court}/hours` | Update court hours |
| `PUT` | `/api/v1/clubs/{club}/courts/{court}/price-rules` | Update pricing |
| `GET` | `/api/v1/clubs/{club}/courts/{court}/availability` | Court availability |
| `POST` | `/api/v1/clubs/{club}/courts/{court}/images` | Upload court photos |
| `POST` | `/api/v1/clubs/{club}/courts/{court}/images/reorder` | Reorder photos |
| `DELETE`| `/api/v1/clubs/{club}/courts/{court}/images/{image}` | Delete a photo |
| `GET` | `/api/v1/clubs/{club}/payment-methods` | List payment methods |
| `POST` | `/api/v1/clubs/{club}/payment-methods` | Add payment method |
| `PATCH` | `/api/v1/clubs/{club}/payment-methods/{method}` | Update payment method |
| `DELETE`| `/api/v1/clubs/{club}/payment-methods/{method}` | Remove payment method |
| `GET` | `/api/v1/clubs/{club}/court-exceptions` | List court exceptions |
| `POST` | `/api/v1/clubs/{club}/court-exceptions` | Create court exception |
| `DELETE`| `/api/v1/clubs/{club}/court-exceptions/{exception}` | Delete court exception |
| `GET` | `/api/v1/clubs/{club}/staff` | List venue staff |
| `POST` | `/api/v1/clubs/{club}/staff` | Add staff member |
| `PATCH` | `/api/v1/clubs/{club}/staff/{membership}` | Update staff |
| `DELETE`| `/api/v1/clubs/{club}/staff/{membership}` | Remove staff |
| `GET` | `/api/v1/clubs/{club}/players` | List venue players |
| `GET` | `/api/v1/clubs/{club}/subscription` | View venue subscription |
| `GET` | `/api/v1/clubs/{club}/matches` | List venue matches |
| `GET` | `/api/v1/clubs/{club}/tournaments` | List venue tournaments |
| `GET` | `/api/v1/clubs/{club}/calendar` | Venue booking calendar |
| `GET` | `/api/v1/clubs/{club}/reports/daily` | Daily revenue report |
| `GET` | `/api/v1/clubs/{club}/receipts` | List booking receipts |
| `POST` | `/api/v1/clubs/{club}/bookings/walk-in` | Create walk-in booking |
| `GET` | `/api/v1/clubs/{club}/bookings/{booking}` | View booking |
| `POST` | `/api/v1/clubs/{club}/bookings/{booking}/payments` | Record payment |
| `POST` | `/api/v1/clubs/{club}/bookings/{booking}/payments/{payment}/adjustments` | Adjust payment |
| `POST` | `/api/v1/clubs/{club}/bookings/{booking}/receipt/{action}` | Approve/reject receipt |
| `POST` | `/api/v1/clubs/{club}/bookings/{booking}/pay-at-court` | Mark pay-at-court |
| `POST` | `/api/v1/clubs/{club}/bookings/{booking}/cancel` | Cancel booking |
| `POST` | `/api/v1/organizers/community/{id}/membership/receipt` | Send membership receipt |
