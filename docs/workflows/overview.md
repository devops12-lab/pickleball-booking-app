---
title: How this knowledge base works
category: Getting started
status: Implemented
order: 10
summary: What the workflow documentation covers, how it was verified against the code, and what each status means.
sources:
  - routes/api.php
  - routes/web.php
  - config/workflow-docs.php
---

# How this knowledge base works

This portal explains how the CourtFlex Laravel backend handles requests, runs business processes and stores data. It combines two sources:

- **Verified workflow guides** in `docs/workflows/`. Each one was written by reading the controllers, services, middleware, models and migrations it describes. Every guide lists its source files at the top, and each file is checked to exist.
- **Existing project documents** in `docs/`, such as the architecture overview and the database dictionary. They were compared with the code. Where they disagree with it, a notice at the top of the page lists the conflicts.

When the two disagree, the code is treated as the truth. The full list of conflicts is in [Documentation discrepancies](documentation-discrepancies.md).

## Implementation status

Every document carries one status, based only on evidence in the code:

| Status | Meaning |
| --- | --- |
| Implemented | The behavior described exists and is reachable through a route. |
| Partially Implemented | Some steps exist, but parts of the flow are missing or not reachable. The guide says which. |
| Planned | Described in planning documents, but not present in the code. |
| Needs Verification | A hand-written document that has not been fully checked, or that contains known conflicts. |
| Deprecated | No longer accurate and kept for history. |

## Reading source references

Inline file paths and class names are checked against the codebase:

- `app/Services/BookingAvailabilityService.php` shows as a verified reference when the file exists.
- A reference to a file or class that cannot be found is marked as unresolved instead of being described.

Source code itself is never shown here. Use the paths to open the files in your editor.

## Platform at a glance

- **Framework:** Laravel 13 on PHP 8.5, with Laravel Sanctum for API tokens.
- **API:** one versioned REST API under `/api/v1`, split into six role portals: `public`, `admin`, `owners`, `staff`, `players` and `organizers`. Routes live in `routes/api/v1/`.
- **Tenancy:** one shared database. Facility businesses are tenants, selected per request with the `X-Tenant-ID` header.
- **Web pages:** only the two documentation portals. `GET /` returns 404.

For endpoint-level details (parameters, validation rules, examples), use the [API reference](/docs-api-endpoints), which is generated from the live route table.
