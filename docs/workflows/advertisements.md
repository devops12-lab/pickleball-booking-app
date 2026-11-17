---
title: Advertisements
category: Platform administration
status: Implemented
order: 40
summary: How system admins create and manage advertisements, how owners and organizers submit ad requests, and how ads are distributed and tracked across portals.
sources:
  - app/Http/Controllers/Api/V1/Admin/AdvertisementController.php
  - app/Http/Controllers/Api/V1/Owners/AdvertisementController.php
  - app/Http/Controllers/Api/V1/Owners/AdvertisementRequestController.php
  - app/Http/Controllers/Api/V1/AdvertisementTrackingController.php
  - app/Http/Requests/Admin/StoreAdvertisementRequest.php
  - app/Http/Requests/Admin/UpdateAdvertisementRequest.php
  - app/Models/Advertisement.php
  - app/Models/AdvertisementRequest.php
  - routes/api/v1/admin.php
  - routes/api/v1/owners.php
  - routes/api/v1/organizers.php
---

# Advertisements

CourtFlex has a platform-managed advertising system. System admins create ads centrally; facility owners and organizers can submit ad requests for review.

## Admin advertisement management

System admins manage the full ad lifecycle:

| Endpoint | Purpose |
| --- | --- |
| `GET /api/v1/admin/advertisements` | List all advertisements with filters |
| `POST /api/v1/admin/advertisements` | Create a new advertisement |
| `GET /api/v1/admin/advertisements/{ad}` | View ad details and performance metrics |
| `PUT /api/v1/admin/advertisements/{ad}` | Update ad content, targeting, or schedule |
| `PATCH /api/v1/admin/advertisements/{ad}/status` | Change ad status (draft, active, paused, etc.) |
| `DELETE /api/v1/admin/advertisements/{ad}` | Remove an advertisement |

### Ad properties

| Field | Description |
| --- | --- |
| `type` | Ad format (defined by `AdvertisementType` enum) |
| `placement` | Where the ad appears: `dashboard`, `discovery`, `booking`, `global` |
| `target_audience` | Who sees it (defined by `AdvertisementTargetAudience` enum) |
| `media` | Image or video file (jpg, png, webp, mp4, webm — max 20MB) |
| `start_date` / `end_date` | Campaign schedule |
| `priority` | Display priority (higher = more likely to show) |

## Ad requests from owners and organizers

Owners and organizers can submit advertising requests:

`POST /api/v1/owners/advertisement-requests` (or `/api/v1/organizers/advertisement-requests`)

The request includes a title, description, objective, preferred placement and dates, contact info, and an optional attachment. Requests are stored as `AdvertisementRequest` records for admin review.

### Admin review

`POST /api/v1/admin/advertisement-requests/{request}/review`

Admins can approve or reject requests with an optional response message. Status values are defined by the `AdvertisementRequestStatus` enum.

## Ad distribution

All authenticated portals can fetch ads:

`GET /api/v1/{portal}/advertisements?placement=dashboard&limit=5`

The endpoint exists on `players`, `owners`, `staff`, and `organizers` controllers. It returns ads matching the requested placement, filtered by:
- Active status
- Current date within start/end range
- Target audience matching the portal
- Sorted by priority

## Impression and click tracking

`POST /api/v1/advertisements/{advertisement}/impression`

Records that the ad was shown to the user. Requires the `placement` field. Tracking data feeds into the admin analytics dashboard and per-ad performance metrics visible in the ad detail view.

## Ad inquiry contact

Platform settings store ad inquiry contact details (`ad_inquiry_email`, `ad_contact_phone`, `ad_instructions`) managed via the admin settings API. These are exposed through the public app config endpoint for display on the frontend.
