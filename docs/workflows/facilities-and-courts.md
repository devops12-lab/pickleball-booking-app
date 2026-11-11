---
title: Facilities and courts
category: Facility & court management
status: Partially Implemented
order: 10
summary: How facility owners manage venues and courts inside their tenant, and how the public discovery API exposes them.
sources:
  - routes/api/v1/owners.php
  - routes/api/v1/public.php
  - app/Http/Controllers/Api/V1/Owners/FacilityController.php
  - app/Http/Controllers/Api/V1/Owners/CourtController.php
  - app/Http/Controllers/Api/V1/Owners/DashboardController.php
  - app/Http/Controllers/Api/V1/Owners/ReportController.php
  - app/Http/Controllers/Api/V1/Owners/StaffController.php
  - app/Http/Controllers/Api/V1/Public/FacilityController.php
  - app/Http/Controllers/Api/V1/Public/CourtController.php
  - app/Http/Requests/Facilities/StoreFacilityRequest.php
  - app/Http/Requests/Courts/StoreCourtRequest.php
  - app/Models/Facility.php
  - app/Models/Court.php
  - database/migrations/2026_11_05_100002_create_facilities_and_courts_tables.php
---

# Facilities and courts

## Module overview

A **tenant** is a facility business. It owns one or more **facilities** (venues), and each facility has **courts**. Courts carry the `hourly_rate` used for booking prices, and can have weekly operating hours and blocked periods.

| | |
| --- | --- |
| Owners | CRUD on `/owners/facilities` and `/owners/courts`, plus `/owners/dashboard`, `/owners/reports`, `/owners/staff` |
| Public | `GET /public/facilities[/{facility}]`, `GET /public/courts[/{court}]`, `GET /public/courts/{court}/availability` |
| Tables | `facilities`, `courts`, `court_operating_hours`, `court_blocked_schedules` |
| Related | [Booking lifecycle](booking-lifecycle.md), [Authorization and tenancy](authorization-and-tenancy.md) |

## Owner workflows

Every owner business request needs a token and the `X-Tenant-ID` header. The middleware chain resolves the tenant and checks that the user is an active `owner` of it (see [Authorization and tenancy](authorization-and-tenancy.md)).

### Create a facility

1. `StoreFacilityRequest` validates the input.
2. A slug is built from the name. If it already exists **in the same tenant**, a 4-character random suffix is added (the unique key is `tenant_id` + `slug`).
3. The facility is created with the current `tenant_id` and `is_active = true`.

### Create a court

1. `StoreCourtRequest` validates the input, including `facility_id`.
2. The controller checks that the facility belongs to the current tenant. If not, it returns 403 "Facility does not belong to this tenant."
3. The court is created with the tenant ID and `is_active = true`.

### Show, update and delete

Each action loads the record by ID and compares its `tenant_id` with the current tenant. A mismatch returns 403. Deletes are hard deletes (no soft deletes in the schema). Database foreign keys cascade from facilities to courts.

### Staff management

- `GET /owners/staff` lists `tenant_users` rows of the tenant with the `staff` or `owner` role.
- `POST /owners/staff` finds an **existing** user by email and adds or updates their membership with the given role (default `staff`). Unknown emails return 404.
- `DELETE /owners/staff/{user}` deletes the membership row.

### Dashboard and reports

`/owners/dashboard` returns counts of courts, bookings, pending bookings, today's bookings and revenue for the tenant. `/owners/reports` aggregates monthly revenue from payments and court utilization from bookings.

## Public discovery

| Endpoint | Filter applied |
| --- | --- |
| `GET /public/facilities` | `is_active = true`, optional `tenant_id`, paginated |
| `GET /public/courts` | `is_active = true`, optional `facility_id` and `tenant_id` |
| `GET /public/facilities/{facility}`, `GET /public/courts/{court}` | **None.** Inactive facilities and courts are returned when requested by ID. |
| `GET /public/courts/{court}/availability` | Requires `starts_at` and `ends_at`; uses `BookingAvailabilityService` |

All public routes use the `public-api` limiter (60 requests per minute per IP).

## Not implemented

- No endpoints manage `court_operating_hours` or `court_blocked_schedules`. They are used by the availability check but can only be filled outside the API (for example by seeders).
- No photo uploads, amenities, or peak and off-peak pricing (planned in the roadmap).
- Plan limits (`subscription_plans.max_courts`, `max_staff`) are not enforced when creating courts or staff.
