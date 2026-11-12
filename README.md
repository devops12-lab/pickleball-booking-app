<div align="center">

<img src="public/images/courtflex_logo.png" alt="CourtFlex" width="560" />

**Multi-Tenant Pickleball Court Booking, Tournament & Sports Club Management Platform**

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![API](https://img.shields.io/badge/API-v1-2563EB?style=flat-square)](docs/API_DOCUMENTATION.md)
[![Auth](https://img.shields.io/badge/Auth-Laravel%20Sanctum-8A2BE2?style=flat-square)](https://laravel.com/docs/sanctum)
[![Tests](https://img.shields.io/badge/tests-244%20passing-22C55E?style=flat-square)](#testing)

</div>

---

## About

CourtFlex is a centralized, multi-tenant SaaS pickleball court booking, tournament, and sports club management platform built on **Laravel 13** and **PHP 8.5**.

Facility owners independently manage their venues, courts, bookings, staff, and tournaments, while players maintain a single global account across every facility in the CourtFlex network — one identity, unlimited venues.

## Key Highlights

| | |
| :--- | :--- |
| **Shared-Schema Multi-Tenancy** | Centralized database with strict tenant boundary isolation via `tenant_id` foreign keys, `TenantContextService`, and `BelongsToTenant` Eloquent scopes. |
| **Unified Global Player Identity** | Players register once to discover venues, book courts, join open-play sessions, and enter tournaments across all facility tenants — no separate credentials. |
| **Granular RBAC** | 5 primary roles plus staff positions (Manager, Front Desk, Court Attendant, Scorer) with fine-grained, permission-based capabilities. |
| **Secure API Authentication** | Laravel Sanctum bearer tokens with profile management, password resets, and Google OAuth integration readiness. |
| **Standardized API v1** | Uniform JSON response wrappers (`ApiResponse`), consistent HTTP status codes, and centralized exception rendering. |
| **Database Backup & Restore** | Native MySQL / PostgreSQL / SQLite dumps with guarded restore, scheduled backups, retention policies, and full audit logging. |
| **Announcements** | Platform-wide and tenant-scoped announcements with scheduled publishing, audience targeting, read tracking, and unread counts. |
| **Facility Operations** | Owner-created staff accounts, court image galleries, configurable payment channels (GCash, Maya, QRPh, banks), and a public court front. |

## Role-Based API Architecture

Every user role has a distinct API route prefix, separate controllers, dedicated authentication endpoints, and server-side middleware under `/api/v1/`:

| Role / Scope | Route Prefix | Auth Endpoints | Primary Middleware | Google OAuth | Purpose |
| :--- | :--- | :--- | :--- | :---: | :--- |
| **System Admin** | `/api/v1/admin/*` | `/api/v1/admin/auth/*` | `role:system-admin` | ❌ Prohibited | Platform KPIs, tenant onboarding/status, SaaS billing |
| **Facility Owner** | `/api/v1/owners/*` | `/api/v1/owners/auth/*` | `tenant.context`, `role:owner` | ✅ Allowed | Manage venue facilities, courts, staff, revenue |
| **Facility Staff** | `/api/v1/staff/*` | `/api/v1/staff/auth/*` | `tenant.context`, `role:staff` | ✅ Invited only | Daily shift reservations, check-ins, payment verifications |
| **Player** | `/api/v1/players/*` | `/api/v1/players/auth/*` | `role:player` | ✅ Allowed | Personal profile, book courts, join clubs, open-play |
| **Club Manager** | `/api/v1/organizers/*` | `/api/v1/organizers/auth/*` | `role:club_manager` | ✅ Assigned only | Manage club rosters, tournaments, submit match scores |
| **Public Discovery** | `/api/v1/public/*` | N/A | None (Public) | N/A | Browse facilities, courts, clubs, open-play, rankings |

## Core Services

| Service | Responsibility |
| :--- | :--- |
| `TenantContextService` | Request-lifecycle tenant resolution and thread safety |
| `PermissionService` | Contextual capability evaluation |
| `BookingAvailabilityService` | Conflict detection, operating hours, and blocked-schedule enforcement |
| `PaymentVerificationService` | Payment approval/rejection state machine with audit trails |
| `AuditLogService` | Structured logging of security and administrative actions |
| `FileUploadService` | File storage with MIME verification |

## Documentation

| Guide | Description |
| :--- | :--- |
| [Architecture Overview](docs/ARCHITECTURE.md) | System design and layer structure |
| [API Documentation (v1)](docs/API_DOCUMENTATION.md) | Endpoint reference and response contracts |
| [Postman Testing Guide](docs/POSTMAN_TESTING_GUIDE.md) | Collection and request walkthroughs |
| [React & CORS Integration](docs/REACT_API_INTEGRATION.md) | Frontend consumption and CORS setup |
| [Database Schema](docs/DATABASE_SCHEMA.md) | Tables, migrations, and relationships |
| [RBAC & Permissions Matrix](docs/RBAC_PERMISSION_MATRIX.md) | Role and permission assignments |
| [Multi-Tenancy Guide](docs/MULTI_TENANCY.md) | Tenant isolation and context resolution |
| [Database Backup & Restore](docs/DATABASE_BACKUP_RESTORE.md) | Backups, restore safety gates, scheduling, retention |
| [Announcements](docs/ANNOUNCEMENTS.md) | Platform & tenant announcements, lifecycle, read tracking |
| [Facility Management](docs/FACILITY_MANAGEMENT.md) | Staff credentials & positions, court images, payment methods, court front |
| [Development Setup & Testing](docs/DEVELOPMENT_SETUP.md) | Local environment and test suite |
| [Implementation Roadmap](docs/IMPLEMENTATION_ROADMAP.md) | Feature delivery plan |
| [System Architecture & Execution Plan](docs/PLAN.md) | End-to-end build plan |

## Quick Start

**Requirements:** PHP 8.5+, Composer, Node.js 20+

```bash
# 1. Clone and install dependencies
git clone https://github.com/edmarcrescencio/pickleball-booking-app.git
cd pickleball-booking-app
composer install
npm install

# 2. Set up the environment and application key
cp .env.example .env
php artisan key:generate

# 3. Run migrations and seeders (demo accounts & courts included)
php artisan migrate:fresh --seed

# 4. Start the development server
php artisan serve
```

> [!IMPORTANT]
> **Default System Admin**
>
> | | |
> | :--- | :--- |
> | **Email** | `admin@courtflex.test` |
> | **Password** | `Password123!` |

## Testing

```bash
php artisan test --compact
```

The suite currently covers **244 tests / 1187 assertions**, including feature tests for multi-tenancy isolation, RBAC authorization, staff management and positions, booking availability, payment verification, the database backup & restore subsystem, announcements, and facility management.

---

<div align="center">

**CourtFlex** · One platform. Every court. Every player.

</div>
