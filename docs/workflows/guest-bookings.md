---
title: Guest bookings
category: Booking management
status: Implemented
order: 22
summary: How walk-in and phone guests book courts through the owner portal, staff portal, or public storefront — without needing a player account.
sources:
  - app/Http/Controllers/Api/V1/Owners/GuestBookingController.php
  - app/Http/Controllers/Api/V1/Staff/GuestBookingController.php
  - app/Http/Controllers/Api/V1/Public/GuestBookingController.php
  - app/Http/Requests/Bookings/StoreGuestBookingRequest.php
  - routes/api/v1/owners.php
  - routes/api/v1/staff.php
  - routes/api/v1/public.php
---

# Guest bookings

Guest bookings let people reserve courts without creating a CourtFlex player account. They are used for walk-ins, phone reservations, and public storefront bookings.

## Who can create guest bookings

| Portal | Endpoint | Auth | Use case |
| --- | --- | --- | --- |
| Owner | `POST /api/v1/owners/guest-bookings` | Sanctum + owner role | Walk-in at the front desk |
| Staff | `POST /api/v1/staff/guest-bookings` | Sanctum + staff role | Staff handling phone/walk-in |
| Public | `POST /api/v1/public/venues/{slug}/guest-bookings` | None (rate-limited) | Online self-service booking |

All three endpoints use the same `StoreGuestBookingRequest` validation.

## Required fields

| Field | Type | Description |
| --- | --- | --- |
| `court_id` | integer | Must be an active court belonging to the venue |
| `guest_name` | string | Guest's full name (min 2 chars) |
| `guest_email` | string | Guest's email for confirmation |
| `guest_phone` | string | Guest's phone number |
| `starts_at` | datetime | Must be in the future |
| `ends_at` | datetime | Must be after starts_at |
| `payment_method` | string | Optional — how the guest will pay |
| `notes` | string | Optional — special requests (max 300 chars) |

## Booking lifecycle

1. **Created** — Guest booking is submitted with guest contact details and time slot.
2. **Pending** — The booking awaits payment confirmation or staff approval.
3. **Confirmed** — Payment verified or staff approves the walk-in.
4. **Completed** — The court session has ended.
5. **Cancelled** — Owner or staff cancels via `POST /api/v1/owners/guest-bookings/{booking}/cancel`.

## Viewing guest bookings

| Endpoint | Portal |
| --- | --- |
| `GET /api/v1/owners/guest-bookings` | Owner — list all guest bookings for the tenant |
| `GET /api/v1/owners/guest-bookings/{booking}` | Owner — view details |
| `GET /api/v1/staff/guest-bookings` | Staff — list guest bookings |
| `GET /api/v1/staff/guest-bookings/{booking}` | Staff — view details |

## Cancellation

`POST /api/v1/owners/guest-bookings/{booking}/cancel`

Only owners can cancel guest bookings. The booking status is updated and the time slot is released for other reservations.

## Differences from player bookings

| Aspect | Player booking | Guest booking |
| --- | --- | --- |
| Account required | Yes (Sanctum token) | No |
| Payment proof upload | Player uploads via `/payments/{payment}/proof` | Handled at venue or via payment method |
| Booking history | Tied to user account | Tied to guest email/phone |
| Court availability | Same availability engine | Same availability engine |
| Cancellation | Player can self-cancel | Owner/staff cancels |

## Public storefront flow

When a facility has its court front (storefront) set to `is_open: true`, guests can book through the public API:

1. Guest visits the venue's public page (resolved by tenant slug).
2. Guest selects a court and time slot via the public courts/availability endpoints.
3. Guest submits the booking form with their contact details.
4. The booking is created in pending status.
5. The venue's owner or staff confirms and processes payment.
