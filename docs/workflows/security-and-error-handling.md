---
title: Security, middleware and error handling
category: Security & error handling
status: Partially Implemented
order: 10
summary: Rate limits, CORS, validation, uploads, tenant isolation, exception rendering and logging — what each protects and the gaps found.
sources:
  - app/Providers/AppServiceProvider.php
  - bootstrap/app.php
  - config/cors.php
  - config/sanctum.php
  - app/Services/FileUploadService.php
  - app/Services/AuditLogService.php
  - app/Http/Requests/Uploads/UploadAvatarRequest.php
  - app/Http/Requests/Uploads/UploadPaymentProofRequest.php
---

# Security, middleware and error handling

## Rate limiting

Named limiters are defined in `AppServiceProvider::configureRateLimiting()` and applied with `throttle:*` on the routes.

| Limiter | Limit | Keyed by | Used on |
| --- | --- | --- | --- |
| `api` | 120/min signed in, 60/min guest | user ID or IP | All authenticated business routes |
| `public-api` | 60/min | IP | `/public/*` |
| `login` | 5/min | email + IP | Login and Google endpoints |
| `register` | 5/min | IP | Player and owner registration |
| `forgot-password` | 3/min | email + IP | Forgot password |
| `password-reset` | 5/min | email + IP | Reset password |
| `email-verification` | 5/min | user ID or IP | Verify email and resend |
| `resource-creation` | 30/min signed in, 10/min guest | user ID or IP | Creating bookings, tenants, plans, facilities, courts, clubs, tournaments; joining clubs and open play; registering for tournaments |
| `sensitive` | 10/min signed in, 5/min guest | user ID or IP | Change password |

`POST /staff/auth/accept-invitation` has **no rate limit**.

## CORS

`config/cors.php` covers `api/*`, `sanctum/csrf-cookie` and `up`. Allowed origins come from `FRONTEND_ALLOWED_ORIGINS` (default: local Vite and React ports) and optional patterns. Credentials are allowed by default. `X-Tenant-ID` is an allowed and exposed header.

## Input validation

- 29 Form Requests in `app/Http/Requests` validate most writes; four endpoints validate inline (owner and staff payment verification notes, public availability, organizer accept assignment).
- Laravel's default global middleware trims strings and converts empty strings to `null`.
- Eloquent query bindings are used throughout; no raw user input is concatenated into SQL. `TenantController::index` uses a `like` filter with a bound value.

## File uploads

| Upload | Rules | Stored |
| --- | --- | --- |
| Avatar | image, `jpeg`, `jpg`, `png` or `webp`, up to 2 MB | public disk |
| Payment proof | `jpeg`, `jpg`, `png` or `pdf`, up to 5 MB | public disk, `receipts/` |

`FileUploadService` stores files under a random 40-character name with the extension guessed from the file's content. Payment proofs are on the **public** disk, so anyone with the URL can view them.

## Tenant isolation

See [Authorization and tenancy](authorization-and-tenancy.md). In short: tenant routes require the `X-Tenant-ID` header and an active membership; controllers compare each record's `tenant_id` with the current tenant and return 403 on mismatch; queries filter by `tenant_id` explicitly because there is no global scope.

## Exception rendering

`bootstrap/app.php` makes every `api/*` request (or any request expecting JSON) render errors as JSON in the shared envelope:

| Exception | Status | Message |
| --- | --- | --- |
| `ValidationException` | 422 | The given data was invalid. (with `errors` per field) |
| `AuthenticationException` | 401 | The exception message, or "Unauthenticated." |
| `AuthorizationException`, `AccessDeniedHttpException` | 403 | The exception message |
| `ModelNotFoundException` | 404 | The requested resource was not found. |
| `NotFoundHttpException` | 404 | The requested resource or endpoint was not found. |
| `TooManyRequestsHttpException` | 429 | The exception message, with rate limit headers |

Anything else, including the `InvalidArgumentException` thrown for bad Google or invitation tokens, falls through to Laravel's default 500 response.

## Logging and auditing

- `AuditLogService` writes `audit_logs` rows with the user, tenant, action, old and new values, IP and user agent. It is called for login success, failure and role denial, and payment verification and rejection only.
- Application errors go to Laravel's default log channel.

## Known security gaps

| Gap | Where | Risk |
| --- | --- | --- |
| Google ID token signature not verified | `GoogleAuthenticationService::verifyIdToken()` | Account takeover by forged token |
| Admin MFA code not checked | `AdminAuthController::login()` | MFA can be bypassed with any value |
| Policies and permission middleware unused | `app/Policies`, `RequirePermission` | Fine-grained permissions in the matrix are not enforced |
| Public detail endpoints ignore visibility | Public club, facility and court `show` | Private clubs and inactive records readable by ID |
| Cross-tenant records return 403 | Owner and staff controllers | Confirms that an ID exists in another tenant |
| Tournament tenant not validated | Organizers `TournamentController::store` | Tournaments can be created under any tenant |
| Payment proofs on public disk | `Players\PaymentController::uploadProof` | Receipts readable by URL |
