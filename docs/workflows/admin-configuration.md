---
title: Admin configuration
category: Platform administration
status: Implemented
order: 45
summary: How system admins manage application settings, platform social links, and how the public app configuration endpoint exposes non-sensitive settings.
sources:
  - app/Http/Controllers/Api/V1/Admin/AdminSettingsController.php
  - app/Http/Controllers/Api/V1/Public/AppConfigController.php
  - app/Http/Requests/Admin/UpdateAppSettingsRequest.php
  - app/Http/Requests/Admin/ManageSocialsRequest.php
  - app/Models/PlatformSetting.php
  - routes/api/v1/admin.php
  - routes/api/v1/public.php
---

# Admin configuration

System admins configure platform-wide settings through the admin API. A subset of these settings is exposed publicly for the frontend.

## Platform settings

Settings are stored in the `platform_settings` table as key-value pairs. Each setting has a `key`, `value`, `type` (string, boolean, json, integer, etc.), and an `is_public` flag that controls visibility through the public endpoint.

### Managed settings

| Key | Type | Public | Description |
| --- | --- | --- | --- |
| `app_name` | string | Yes | Application display name |
| `app_description` | string | Yes | Platform description |
| `app_logo` | string | Yes | Logo image URL |
| `app_favicon` | string | Yes | Favicon URL |
| `official_website` | string | Yes | Official website URL |
| `business_address` | string | Yes | Business address |
| `platform_socials` | json | Yes | Array of social link objects |
| `ad_inquiry_email` | string | Yes | Contact email for ad inquiries |
| `ad_contact_phone` | string | Yes | Contact phone for ad inquiries |
| `ad_instructions` | string | Yes | Instructions for advertisers |
| `default_timezone` | string | Yes | Platform default timezone |
| `default_currency` | string | Yes | Platform default currency (3-letter code) |
| `maintenance_mode` | boolean | No | Enable/disable maintenance mode |
| `public_booking_enabled` | boolean | Yes | Allow public guest bookings |
| `registration_enabled` | boolean | Yes | Allow new user registrations |

### Admin endpoints

| Endpoint | Purpose |
| --- | --- |
| `GET /api/v1/admin/settings/app` | Retrieve all app settings |
| `PUT /api/v1/admin/settings/app` | Update one or more settings |
| `GET /api/v1/admin/settings/socials` | List platform social links |
| `POST /api/v1/admin/settings/socials` | Add a social link (max 10) |
| `PUT /api/v1/admin/settings/socials/{index}` | Update a social link |
| `DELETE /api/v1/admin/settings/socials/{index}` | Remove a social link |

File uploads (`app_logo`, `app_favicon`) are handled via `FileUploadService` and stored as URL paths in the setting value.

### Social links

Platform social links are stored as a JSON array in the `platform_socials` setting. Each entry has:

- `platform` — one of: facebook, instagram, tiktok, youtube, linkedin, twitter, website
- `url` — full URL
- `display_name` — optional label

Maximum 10 social links per platform.

## Public app configuration

`GET /api/v1/public/app-config`

This unauthenticated endpoint returns all `PlatformSetting` records where `is_public = true`. It is intended for the frontend to load branding, contact info, and feature flags without authentication.

The response does not include internal settings like `maintenance_mode` (which has `is_public = false`).

## Audit logging

All setting changes are logged via `AuditLogService` with the action, old values, and new values. This provides a trail for compliance and troubleshooting.

## Caching

`PlatformSetting::get($key)` uses a static cache with type casting. Settings are read frequently (on nearly every request for feature flags), so the model avoids repeated database queries within a single request lifecycle.
