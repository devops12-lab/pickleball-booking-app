# CourtFlex — Implementation Roadmap & Next Phases

This roadmap tracks the development progress from the completed backend foundation and API hardening to the forthcoming feature modules.

---

## Phase Status Summary

| Phase | Description | Status |
| :--- | :--- | :--- |
| **Phase 1** | Project Assessment & Plan | ✅ Completed |
| **Phase 2** | Architecture & Technology Stack | ✅ Completed |
| **Phase 3** | Multi-Tenant SaaS Foundation & Isolation Scopes | ✅ Completed |
| **Phase 4** | User Roles & Granular Capability System (RBAC) | ✅ Completed |
| **Phase 5** | Database Foundation (10 Core Domains & Migrations) | ✅ Completed |
| **Phase 6** | Dedicated Role-Based Authentication & Services | ✅ Completed |
| **Phase 7** | API v1 Namespacing (`admin`, `owners`, `staff`, `players`, `organizers`, `public`) | ✅ Completed |
| **Phase 8** | Core Security, Throttling & Named Rate Limiters | ✅ Completed |
| **Phase 9** | Form Requests & Validation Hardening (19 Form Requests) | ✅ Completed |
| **Phase 10** | Policy-Based Authorization Layer (7 Resource Policies) | ✅ Completed |
| **Phase 11** | Standardized API Resources & Strict Model Scopes | ✅ Completed |
| **Phase 12** | Centralized Exception Rendering (`401`, `403`, `404`, `422`, `429`) | ✅ Completed |
| **Phase 13** | File Upload Hardening (MIME, SHA-256 naming, Cleanup) | ✅ Completed |
| **Phase 14** | Automated Test Suite (Pest PHP — 100% Passing) | ✅ Completed (87/87 Passing, 349 Assertions) |
| **Phase 15** | Architecture & Developer Documentation (`/docs/*`) | ✅ Completed |

---

## Feature Development Milestones

### Milestone 1: Facility Onboarding & Court Scheduling
- [ ] Court photo galleries and facility amenities via `FileUploadService`.
- [ ] Interactive operating hours management interface.
- [ ] Recurring blocked maintenance schedule tools.
- [ ] Court custom pricing (peak vs off-peak hours).

### Milestone 2: Advanced Booking Engine & Hold System
- [ ] Temporary reservation hold timer (10-minute lock during payment checkout).
- [ ] QR code generation for player check-in.
- [ ] Participant invitation accept/decline workflow.
- [ ] Cancellation and refund policy engine.

### Milestone 3: Real-Time Payment Gateways
- [ ] GCash, Maya, and Stripe webhooks and automated verification.
- [ ] E-receipt PDF generation and automated email dispatch.
- [ ] Tenant financial settlement and payout reports.

### Milestone 4: Club Management
- [ ] Player membership application and approval workflows.
- [ ] Club communication boards and activity schedules.
- [ ] Member-only court booking discounts.

### Milestone 5: Open Play Engine
- [ ] Dynamic waitlisting and automatic spot promotion.
- [ ] Skill rating eligibility gating (e.g., 3.0 to 4.0 only).
- [ ] Staff check-in terminal mode.

### Milestone 6: Tournament Engine
- [ ] Single/Double elimination and Round Robin bracket generators.
- [ ] Seed assignment algorithms.
- [ ] Match scheduling and live court score updates.
- [ ] Dynamic player rating calculation (Elo / DUPR-compatible system).

### Milestone 7: SaaS Subscriptions & Billing
- [ ] Automated recurring subscription invoices for facility owners.
- [ ] Usage metering (max courts, max staff limits enforced per plan).
- [ ] Automated tenant suspension on past-due billing.
