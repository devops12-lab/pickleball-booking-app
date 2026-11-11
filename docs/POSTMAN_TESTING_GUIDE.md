# CourtFlex — Postman API Testing Guide

This guide explains how to import and test the CourtFlex API in **Postman** or any REST client using the pre-configured Postman Collection and Environment files.

---

## 1. Quick Start Files

The following files are located in the `postman/` directory:

1. **Collection**: [`postman/CourtFlex_API_v1.postman_collection.json`](file:///C:/Users/ec.40021031/Documents/edscodes/myprojects/pickleball-booking-app/postman/CourtFlex_API_v1.postman_collection.json)
2. **Environment**: [`postman/CourtFlex_Local.postman_environment.json`](file:///C:/Users/ec.40021031/Documents/edscodes/myprojects/pickleball-booking-app/postman/CourtFlex_Local.postman_environment.json)

---

## 2. Step-by-Step Setup

### Step 1: Start the Laravel API Server
In your terminal, start the local development server:

```bash
php artisan serve
```
By default, the API will be available at: `http://localhost:8000`

> [!NOTE]
> The root URL `http://localhost:8000/` returns `404 Not Found` by design.
> The interactive endpoint catalog is accessible at: `http://localhost:8000/docs-api-endpoints`.

### Step 2: Seed Demo Data
Ensure the database has all seeded roles, demo facilities, courts, staff, players, and clubs:

```bash
php artisan db:seed
```

### Step 3: Import into Postman
1. Open **Postman**.
2. Click the **Import** button in the top-left corner.
3. Select or drag-and-drop both files:
   - `postman/CourtFlex_API_v1.postman_collection.json`
   - `postman/CourtFlex_Local.postman_environment.json`
4. In the top-right corner of Postman, select the active environment:  
   **"CourtFlex — Local Environment"**

---

## 3. Automatic Token Capture & Multi-Tenancy

Every login request in this collection includes an automatic **Postman Test Script**:
```javascript
if (pm.response.code === 200 || pm.response.code === 201) {
    var json = pm.response.json();
    if (json.data && json.data.access_token) {
        pm.environment.set('token', json.data.access_token);
        pm.environment.set('admin_token', json.data.access_token);
        pm.environment.set('owner_token', json.data.access_token);
        pm.environment.set('staff_token', json.data.access_token);
        pm.environment.set('player_token', json.data.access_token);
        pm.environment.set('organizer_token', json.data.access_token);
    }
    if (json.data && json.data.context && json.data.context.tenant_slug) {
        pm.environment.set('tenant_id', json.data.context.tenant_slug);
    }
}
```

> [!TIP]
> **Zero Manual Copy-Pasting**:
> When you hit **Send** on any login or registration request, Postman automatically extracts the Bearer token and active tenant slug and saves them into your Postman environment variables. All subsequent requests in that folder automatically use the new token.

---

## 4. Pre-configured Demo Accounts

All demo accounts share the password: `Password123!`

| Role | Seeded Email | Password | Pre-configured Tenant | Purpose |
| :--- | :--- | :--- | :--- | :--- |
| **System Admin** | `admin@courtflex.test` | `Password123!` | Platform wide (`*`) | SaaS platform analytics, tenant onboarding, billing |
| **Facility Owner**| `owner@courtflex.test` | `Password123!` | `metro-pickleball-club` | Manage venues, courts, staff, and view bookings |
| **Facility Staff**| `staff@courtflex.test` | `Password123!` | `metro-pickleball-club` | Daily shift reservations, check-ins, payments |
| **Global Player** | `player@courtflex.test` | `Password123!` | Global player | Book courts, view stats, join clubs & open-play |
| **Club Organizer**| `organizer@courtflex.test` | `Password123!` | `austin-dinking-masters`| Manage club rosters and tournaments |

---

## 5. Sample Raw JSON Payloads by Role

### A. Global Player Registration (`POST /api/v1/players/auth/register`)
```json
{
  "name": "Jane Player",
  "email": "jane.player@example.com",
  "phone": "+1-555-0199",
  "password": "Password123!",
  "password_confirmation": "Password123!"
}
```

### B. Player Login (`POST /api/v1/players/auth/login`)
```json
{
  "email": "player@courtflex.test",
  "password": "Password123!"
}
```

### C. Facility Owner Registration & Tenant Setup (`POST /api/v1/owners/auth/register`)
```json
{
  "name": "Alice Court Owner",
  "email": "alice.owner@example.com",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "business_name": "Sunset Pickleball Center",
  "business_phone": "+1-555-0144",
  "address": "456 Pickleball Way, Austin, TX"
}
```

### D. Facility Owner Login (`POST /api/v1/owners/auth/login`)
```json
{
  "email": "owner@courtflex.test",
  "password": "Password123!"
}
```

### E. Accept Staff Invitation (`POST /api/v1/staff/auth/accept-invitation`)
```json
{
  "token": "demo-staff-invitation-token-12345",
  "name": "Sam Staff Member",
  "password": "Password123!",
  "password_confirmation": "Password123!"
}
```

### F. Facility Staff Login (`POST /api/v1/staff/auth/login`)
```json
{
  "email": "staff@courtflex.test",
  "password": "Password123!"
}
```

### G. Accept Organizer Assignment (`POST /api/v1/organizers/auth/accept-assignment`)
*Requires Bearer token in Authorization header.*
```json
{
  "token": "demo-organizer-invitation-token-12345"
}
```

### H. Club Organizer Login (`POST /api/v1/organizers/auth/login`)
```json
{
  "email": "organizer@courtflex.test",
  "password": "Password123!"
}
```

### I. System Admin Login (`POST /api/v1/admin/auth/login`)
```json
{
  "email": "admin@courtflex.test",
  "password": "Password123!"
}
```
*(If MFA is enabled on the admin account, add `"mfa_code": "123456"` to the body).*

---

## 6. Testing Walkthrough by Role

### A. Public Discovery (No Token Needed)
Folder: `1. Public Discovery API`
1. Run **Get Facilities Directory** (`GET /api/v1/public/facilities`)
   - Returns all active pickleball venues across all tenants.
2. Run **Get Courts** (`GET /api/v1/public/courts`)
   - Returns court types (`indoor`, `outdoor`), surfaces (`cushioned`, `acrylic`), and hourly rates.
3. Run **Get Court Availability** (`GET /api/v1/public/courts/1/availability?date=2026-11-10`)
   - Returns real-time slot availability breakdown.

### B. System Admin Portal
Folder: `2. System Admin API`
1. Run **Admin Login** (`POST /api/v1/admin/auth/login`)
   - Postman script saves `admin_token`.
2. Run **Admin Profile (Me)** (`GET /api/v1/admin/auth/me`)
   - Returns admin profile with `active_role: system_admin` and permissions `['*']`.
3. Run **Admin Dashboard KPIs** (`GET /api/v1/admin/dashboard`)
   - Returns SaaS platform overview, tenant counts, and total users.
4. Run **List All Tenants** (`GET /api/v1/admin/tenants`)
   - Returns all business tenants on the platform.

### C. Facility Owner Portal
Folder: `3. Facility Owner API`
1. Run **Owner Login** (`POST /api/v1/owners/auth/login`)
   - Postman script saves `owner_token` and `tenant_id`.
2. Run **Owner Dashboard** (`GET /api/v1/owners/dashboard`)
   - Automatically passes `X-Tenant-ID: metro-pickleball-club`.
   - Returns venue utilization and bookings.
3. Run **List Courts** (`GET /api/v1/owners/courts`)
   - Returns courts under Metro Pickleball Club.

### D. Facility Staff Operations
Folder: `4. Facility Staff API`
1. Run **Staff Login** (`POST /api/v1/staff/auth/login`)
   - Postman script saves `staff_token`.
2. Run **Staff Shift Dashboard** (`GET /api/v1/staff/dashboard`)
   - Passes `X-Tenant-ID: metro-pickleball-club`.
   - Returns today's check-ins and bookings.
3. Run **Check-In Player** (`POST /api/v1/staff/bookings/{booking}/check-in`)
   - Updates booking status to `checked_in`.

### E. Player Account & Features
Folder: `5. Player API`
1. Run **Player Login** (`POST /api/v1/players/auth/login`)
   - Postman script saves `player_token`.
2. Run **Player Profile** (`GET /api/v1/players/profile`)
   - Returns player stats and verification status.
3. Run **Upload Avatar** (`POST /api/v1/players/profile/avatar`)
   - Uploads a new avatar image (multipart form-data).
4. Run **Create Booking** (`POST /api/v1/players/bookings`)
   - Creates a new court reservation.

### F. Club Organizer
Folder: `6. Club Organizer API`
1. Run **Organizer Login** (`POST /api/v1/organizers/auth/login`)
   - Postman script saves `organizer_token`.
2. Run **Managed Clubs** (`GET /api/v1/organizers/clubs`)
   - Returns Austin Dinking Masters club details.
3. Run **Record Match Score** (`POST /api/v1/organizers/tournaments/{tournament}/matches/{match}/results`)
   - Submits tournament match scores and calculates rankings.

---

## 7. Common Headers Reference

| Header | Example Value | Description |
| :--- | :--- | :--- |
| `Accept` | `application/json` | Enforces JSON response envelope |
| `Content-Type` | `application/json` | Specifies request body format |
| `Authorization` | `Bearer {{token}}` | Bearer token from Sanctum |
| `X-Tenant-ID` | `{{tenant_id}}` | Facility tenant slug or ID for owner/staff routes |

---

## 8. Troubleshooting

- **401 Unauthenticated**: Ensure you ran the login request in that folder first to capture the token.
- **403 Forbidden**: Ensure the `X-Tenant-ID` header is set to `metro-pickleball-club` when querying owner or staff endpoints.
- **404 Not Found on `/`**: Web root `GET /` intentionally returns 404. For the interactive API explorer, visit `/docs-api-endpoints`.
- **429 Too Many Requests**: You exceeded rate limits (e.g. 5 attempts/min on login). Wait for the duration in the `Retry-After` header.
- **Connection Refused**: Ensure `php artisan serve` is running in your terminal on `http://localhost:8000`.
