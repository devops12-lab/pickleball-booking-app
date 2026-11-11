---
title: Facility management
category: Facility Owner
status: Implemented
order: 13
summary: Staff accounts created directly by owners with position-based capabilities, court galleries (1-5 images), owner-managed payment channels, and the per-facility court front.
sources:
  - app/Http/Controllers/Api/V1/Owners/StaffController.php
  - app/Http/Controllers/Api/V1/Owners/CourtController.php
  - app/Http/Controllers/Api/V1/Owners/PaymentMethodController.php
  - app/Http/Controllers/Api/V1/Owners/CourtFrontController.php
  - app/Http/Controllers/Api/V1/Public/CourtFrontController.php
  - config/staff-positions.php
---

# Facility management

Owner-facing facility operations: staff accounts, court galleries, accepted payment channels and the public store front ("court front").

## Staff management — direct credential creation

Staff accounts are created **by the owner with full credentials** — no email invitation flow. The old invitation endpoints are retired.

`POST /api/v1/owners/staff`

```json
{
    "name": "Nina Newstaff",
    "email": "nina@metro.test",
    "password": "Password123!",
    "phone": "+63 917 555 0100",
    "position": "front_desk"
}
```

- The email must not belong to an existing account (`422` otherwise) — personal player accounts are never linked into a facility without consent.
- The account is created active with a verified email so the staff member can log in immediately; the password is never echoed back.
- The membership row is written to `tenant_users` with role `staff` and the chosen `position`.
- Every action is audit-logged (`staff_created`, `staff_updated`, `staff_removed`).

| Method | URI | Description |
| --- | --- | --- |
| `GET` | `/owners/staff` | Staff list (includes position and role) |
| `POST` | `/owners/staff` | Create staff with full credentials |
| `PATCH` | `/owners/staff/{user}` | Change `position` or `status` (`active`/`inactive`) |
| `DELETE` | `/owners/staff/{user}` | Remove the facility assignment (user account is kept) |

### Staff positions & capabilities

`config/staff-positions.php` maps each position to a permission whitelist. **When a staff member has a position, only the position's permissions apply** (the staff role baseline is replaced). Staff without a position keep the legacy baseline — fully backwards compatible.

| Position | Capabilities |
| --- | --- |
| **Manager** | Bookings (manage/cancel/check-in), courts, payments (view/verify), open play, scores (submit/verify), staff view, announcements |
| **Front Desk** | Bookings (manage/check-in), courts view, payments (view/verify), announcements view |
| **Court Attendant** | Bookings view, check-ins, courts view, announcements view |
| **Scorer** | Bookings view, scores (submit/verify), announcements view |

Staff business routes now enforce these permissions server-side (`permission:` middleware on `/api/v1/staff/*`). The `role:staff` route group itself is unchanged, so existing integrations keep working.

## Court images (1–5 per court)

Every court carries a gallery of 1 to 5 images, stored in the `court_images` table with a `sort_order`.

| Method | URI | Rules |
| --- | --- | --- |
| `POST` | `/owners/courts` | `images[]` **required**, 1–5 files (jpg/jpeg/png/webp, ≤5 MB each) |
| `POST` | `/owners/courts/{court}/images` | Add images; `422` when the combined total would exceed 5 |
| `DELETE` | `/owners/courts/{court}/images/{image}` | Delete; `422` when it is the court's last image |
| `POST` | `/owners/courts/{court}/images/reorder` | `{ "image_ids": [3, 1, 2] }` — must contain exactly the court's images |

Images are served through `CourtResource` with public URLs, so the public court list/detail endpoints expose the gallery automatically. All image operations are tenant-scoped (`403` across tenants).

## Payment method management (facility channels)

Owners define the payment channels their facility accepts for reservations — cash, GCash, Maya, QRPh, bank transfers or anything else. Stored in `tenant_payment_methods`, separate from the player-level `payment_methods` table.

| Method | URI | Description |
| --- | --- | --- |
| `GET` | `/owners/payment-methods` | List the facility's channels |
| `POST` | `/owners/payment-methods` | Create (`name`, `type`, `account_name?`, `account_number?`, `instructions?`, `qr_image?`, `is_active`, `sort_order`) |
| `GET/PATCH/DELETE` | `/owners/payment-methods/{paymentMethod}` | Manage; `403` across tenants |

`type` ∈ `cash`, `gcash`, `maya`, `qrph`, `bank_transfer`, `other`.

### Player checkout integration

- `GET /api/v1/players/tenants/{tenant}/payment-methods` — active channels for reservation payment (name, type, account details, instructions, QR image URL).
- `POST /api/v1/players/payments/{payment}/proof` accepts `payment_method` — validated against the facility's **active** channels (`422` for anything unconfigured, e.g. PayPal when the facility never added it) and recorded on the payment.

## Court front (store front)

The public-facing profile of a facility — exactly **one** row per tenant (`court_fronts.tenant_id` is unique), auto-created with defaults on the owner's first access.

| Method | URI | Description |
| --- | --- | --- |
| `GET` | `/owners/court-front` | Show (auto-creates) |
| `PATCH` | `/owners/court-front` | Update `tagline`, `description`, `contact_email/phone/address`, `website`, `facebook`, `instagram`, `is_open` |
| `POST` | `/owners/court-front/logo` | Upload/replace the logo (old file deleted) |
| `POST` | `/owners/court-front/cover` | Upload/replace the cover image |
| `GET` | `/api/v1/public/tenants/{tenant}/court-front` | **Public** store front profile (`404` if the facility has none yet) |

Image uploads: jpg/jpeg/png/webp, ≤5 MB. Validation rejects malformed contact emails and URLs.

## New permissions

| Permission | Grants |
| --- | --- |
| `payment_methods.manage` | Owner CRUD for facility payment channels |
| `court_front.manage` | Owner management of the court front |
| `bookings.check_in` | Check-in capability (position-gated) |

## Audit trail

All facility-management mutations are written to `audit_logs`: `staff_created`, `staff_updated`, `staff_removed`, `court_images_added`, `court_image_deleted`, `court_images_reordered`, `payment_method_created`, `payment_method_updated`, `payment_method_deleted`, `court_front_updated`, `court_front_logo_updated`, `court_front_cover_updated` — each with the actor, tenant, entity and old/new value snapshots.

## Tenant context errors

Owner and staff endpoints resolve the facility from the `X-Tenant-ID` header. When the header names a tenant the account has no active membership in, the API answers `403` with a diagnosable payload:

```json
{
    "success": false,
    "message": "You do not have administrative or staff access to this facility tenant.",
    "errors": {
        "requested_tenant_id": 7,
        "requested_tenant_name": "Some Other Facility",
        "member_tenant_ids": [1]
    }
}
```

Use the `tenant_id` from the login response (`data.context.tenant_id`) — re-seeding the database reassigns tenant IDs, so Postman environment variables can go stale.

## Testing

`StaffManagementTest` (10), `CourtImagesTest` (9), `TenantPaymentMethodsTest` (8) and `CourtFrontTest` (7) cover credential creation, duplicate-email rejection, the position permission matrix, invitation retirement, gallery limits and reordering, channel validation and the public store front.
