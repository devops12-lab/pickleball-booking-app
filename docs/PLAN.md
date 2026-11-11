# COURTFLEX — Laravel API Backend Foundation Architecture & Implementation Plan

## Goal Description
Establish a production-ready, highly secure, scalable multi-tenant SaaS API foundation for **CourtFlex** — a sports management and pickleball court booking platform. The platform serves multiple facility owners independently while maintaining a unified global player experience across all facilities.

---

## 1. System Architecture Overview

### Architectural Model: Centralized Shared-Schema Multi-Tenancy
- **Database Strategy**: Single database, shared schema with tenant isolation enforced via `tenant_id` foreign keys, `TenantContextService`, Eloquent scopes (`BelongsToTenant`), and backend route middleware.
- **Why this approach**:
  - **Global Player Identity**: Players register once and can discover, book courts, join open play, and participate in tournaments across any facility in the CourtFlex network without duplicate accounts.
  - **Facility Independence**: Facility Owners and Staff are strictly isolated to their own tenant context.
  - **Operational Efficiency**: Centralized migrations, updates, billing, analytics, and platform administration.

---

## 2. Role-Based Access Control (RBAC) Design

### Roles & Scopes
1. **System Admin** (Platform / Global Scope):
   - Tenant-agnostic (`tenant_id = null`).
   - Platform administration, subscription plan management, tenant lifecycle (activation, suspension), platform audit logs, system-wide analytics.
2. **Owner** (Tenant Scope):
   - Full administration of their facility, courts, operating schedules, staff assignments, booking approvals, payment verifications, and financial reporting.
3. **Staff** (Tenant Scope):
   - Day-to-day operations: court schedule viewing, player check-in, payment verification, match facilitation, and score entry.
4. **Player** (Global Scope):
   - Discover facilities, reserve courts, make payments, join clubs (up to 3), participate in open play & tournaments, view personal statistics and global rankings.
5. **Club Manager / Organizer** (Delegated Contextual Role):
   - Assigned to specific clubs or tournaments to manage rosters, schedules, and submit authorized results without full tenant administration rights.

### Granular Capability System
Permissions are granular slugs (`courts.create`, `bookings.verify-payment`, `tenant.update`, etc.) attached to roles or directly assigned with tenant context.

---

## 3. Database Foundation (10 Core Domains)

1. **Platform & Authentication**:
   - `users`: Global identity (email, password, `google_id`, `avatar`, `phone`, `account_status`).
   - `tenants`: Facility business entity (`name`, `slug`, `business_email`, `status`, `subscription_plan_id`).
   - `tenant_users`: Pivot table connecting users to tenants with `role_id` and `status`.
   - `roles`, `permissions`, `role_permissions`, `user_permissions`.
   - `personal_access_tokens` (Sanctum).
2. **SaaS Management**:
   - `subscription_plans`: Pricing, billing intervals, limits (courts, staff), feature flags.
   - `tenant_subscriptions`: Subscriptions tied to plans with lifecycle states.
   - `subscription_invoices`: Financial records for SaaS subscriptions.
   - `platform_settings`: Key-value configuration for platform-wide toggles.
3. **Facility & Court Management**:
   - `facilities`: Venues belonging to a tenant (location, address, coordinates).
   - `courts`: Individual pickleball courts (indoor/outdoor, surface type, hourly rates).
   - `court_operating_hours`: Weekly recurring operating hours.
   - `court_blocked_schedules`: Maintenance, special closures, or private reservation blocks.
4. **Bookings**:
   - `bookings`: Booking headers with unique references, time ranges, amounts, and statuses.
   - `booking_participants`: Registered players and guests attached to a booking.
   - `booking_status_histories`: Audit trail of booking state transitions.
5. **Payments**:
   - `payment_methods`: Stored customer payment preferences.
   - `payments`: Transaction records (status, amount with decimal precision, references).
   - `payment_verifications`: Manual/automated verification tracking (proof attachments, approver).
   - `refunds`: Refund processing records.
6. **Clubs**:
   - `clubs`: Player clubs with membership limits and privacy settings.
   - `club_members`: Membership roster and roles (manager, organizer, member).
   - `club_invitations`: Pending invitations with secure tokens.
7. **Open Play**:
   - `open_play_sessions`: Drop-in pickleball sessions with skill ratings and participant limits.
   - `open_play_participants`: Registered participants with check-in statuses.
8. **Tournaments**:
   - `tournaments`: Competitive events with formats (single/double elimination, round-robin).
   - `tournament_categories`: Divisions and skill brackets.
   - `tournament_participants`: Registered teams or solo players.
   - `tournament_matches`: Match scheduling, court assignments, and scores.
   - `tournament_brackets`: Bracket progression tracking.
9. **Rankings & Statistics**:
   - `player_statistics`: Aggregate win/loss records, tournament victories, dynamic rating.
   - `player_rankings`: Historical and periodic leaderboard standings.
   - `match_results`: Official verified match scorecards.
10. **System Administration**:
    - `audit_logs`: Immutable tracking of sensitive administrative and financial actions.
    - `notifications`: Multi-channel system notifications.

---

## 4. API Standards & Security Architecture

### Base URL & Versioning
- All endpoints versioned under `/api/v1/`.

### Standardized Response Contract
- **Success (`200 OK`, `201 Created`)**:
  ```json
  {
    "success": true,
    "message": "Operation completed successfully.",
    "data": {},
    "meta": null
  }
  ```
- **Error (`400`, `401`, `403`, `404`, `422`, `500`)**:
  ```json
  {
    "success": false,
    "message": "Validation failed / Unauthorized / Not found.",
    "errors": {}
  }
  ```

### Security & Middlewares
- `EnsureAccountActive`: Rejects requests from suspended or inactive accounts.
- `IdentifyTenant`: Resolves tenant from `X-Tenant-ID` header or route parameter.
- `EnsureTenantAccess`: Verifies that authenticated user belongs to the tenant with appropriate permissions.
- `RequirePermission`: Enforces fine-grained permission checks.
- Rate limiting on authentication routes (10 attempts per minute).

---

## 5. Reusable Backend Services
- **`TenantContextService`**: Thread-safe active tenant resolver and context switcher.
- **`PermissionService`**: Granular capability evaluator respecting tenant boundary.
- **`BookingAvailabilityService`**: Evaluates operating hours, existing reservations, blocked dates, and overlaps.
- **`PaymentVerificationService`**: Handles verification state machine, receipt storage, and ledger consistency.
- **`AuditLogService`**: Centralized logging of security, financial, and administrative actions.
- **`FileUploadService`**: Sanitized storage with MIME-type verification for images and documents.

---

## 6. Implementation Phases & Step-by-Step Roadmap

- **Step 1: Scaffolding & Packages**: Install Sanctum (`php artisan install:api`), configure `bootstrap/app.php` with API routes and middleware aliases.
- **Step 2: Database Migrations & Schemas**: Create normalized migrations covering all 10 core domains.
- **Step 3: Foundational Eloquent Models**: Implement models, traits (`BelongsToTenant`), relationships, and casting.
- **Step 4: Seeders**: Implement `RolesAndPermissionsSeeder` and `CourtFlexFoundationSeeder`.
- **Step 5: API Response & Error Handling**: Implement `ApiResponse` trait and register JSON response handlers.
- **Step 6: Core Security Middleware**: Implement `IdentifyTenant`, `EnsureTenantAccess`, `RequirePermission`, and `EnsureAccountActive`.
- **Step 7: Sanctum Authentication**: Implement registration, login, Google readiness, logout, token revocation, me endpoint, and password reset.
- **Step 8: Reusable Backend Services**: Implement `TenantContextService`, `PermissionService`, `BookingAvailabilityService`, and `AuditLogService`.
- **Step 9: Versioned API Route Scaffolding**: Setup `/api/v1` route groups for all modules with baseline controllers and resources.
- **Step 10: Automated Test Suite (Pest)**: Feature and unit tests covering Auth, Tenant Isolation, RBAC, and Services.
- **Step 11: Architecture & Setup Documentation**: Generate complete architecture and API documentation.
