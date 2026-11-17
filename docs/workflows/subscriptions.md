---
title: Subscriptions
category: Payment processing
status: Implemented
order: 30
summary: How facility owners subscribe to plans, change billing intervals, upgrade or downgrade, cancel, and how subscription entitlements enforce resource limits.
sources:
  - app/Http/Controllers/Api/V1/Owners/SubscriptionController.php
  - app/Services/SubscriptionEntitlementService.php
  - app/Models/SubscriptionPlan.php
  - app/Models/TenantSubscription.php
  - app/Exceptions/SubscriptionLimitExceededException.php
  - app/Http/Requests/Subscriptions/SubscribeRequest.php
  - app/Http/Requests/Subscriptions/ChangeIntervalRequest.php
  - app/Http/Requests/Subscriptions/UpgradePlanRequest.php
  - app/Http/Requests/Subscriptions/DowngradePlanRequest.php
  - app/Http/Requests/Subscriptions/CancelSubscriptionRequest.php
  - routes/api/v1/owners.php
---

# Subscriptions

CourtFlex uses a SaaS subscription model. Each tenant (facility) has one active subscription that determines resource limits and available features.

## Plans

Subscription plans are managed by system admins via `POST /api/v1/admin/subscription-plans`. Each plan defines:

| Field | Purpose |
| --- | --- |
| `price` | Monthly price |
| `yearly_price` | Annual price (discount) |
| `billing_interval` | Default: `monthly` or `yearly` |
| `max_courts` | Maximum courts the tenant can create |
| `max_staff` | Maximum active staff + owner members |
| `features` | JSON array of feature keys (e.g., `court_booking`) |

## Subscribing

Owners subscribe via `POST /api/v1/owners/subscription/subscribe` with a `plan_id` and `billing_interval`. This creates a `TenantSubscription` record linking the tenant to the plan.

## Subscription lifecycle

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `GET /subscription` | — | View current subscription details |
| `POST /subscription/subscribe` | Subscribe | Select a plan and billing interval |
| `PUT /subscription/billing-interval` | Change | Switch between monthly and yearly |
| `POST /subscription/upgrade` | Upgrade | Move to a higher-tier plan |
| `POST /subscription/downgrade` | Downgrade | Move to a lower-tier plan |
| `POST /subscription/cancel` | Cancel | Cancel subscription (with optional reason) |
| `GET /subscription/invoices` | — | View billing history |
| `GET /subscription/usage` | — | View current resource usage vs limits |

All endpoints are prefixed with `/api/v1/owners/` and require owner authentication with tenant context.

## Entitlement enforcement

The `SubscriptionEntitlementService` controls what a tenant can create. It is called before every resource-creation endpoint that is limit-gated.

### How limits are checked

1. `checkOrFail()` acquires a `SELECT ... FOR UPDATE` lock on the tenant row inside a database transaction.
2. It loads the active subscription and its plan.
3. It compares the current count of the resource type against the plan limit.
4. If the limit is reached, it throws `SubscriptionLimitExceededException` (rendered as `403 Forbidden`).

### Creation-allowed statuses

Resources can only be created when the subscription status is one of:

- `active`
- `trialing`
- `cancelled` (grace period — existing subscription still valid until end of term)

### Resource counting

| Resource | How counted |
| --- | --- |
| Courts | `tenant->courts()->count()` — all courts regardless of status |
| Staff | `tenant->tenantUsers()->where('status', 'active')->whereHas('role', ['staff', 'owner'])->count()` — only active members |

Deactivated staff members do not count toward the limit. This allows owners to deactivate seasonal staff without losing a slot.

### Usage endpoint

`GET /api/v1/owners/subscription/usage` returns:

```json
{
  "court": { "current": 3, "limit": 4, "remaining": 1 },
  "staff": { "current": 2, "limit": 3, "remaining": 1 }
}
```

### Feature checks

`hasFeature($tenant, $featureKey)` checks whether the plan's `features` JSON array contains the requested key. Used for feature-gating beyond simple resource counts.
