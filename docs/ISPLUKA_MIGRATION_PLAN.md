# Ispluka Migration & Architecture Plan

## Goal

Evolve the current PHPNuxBill-based Nux codebase into the Ispluka ISP Billing & Management ERP while preserving proven ISP billing/network functionality where practical.

The target is a production-ready, multi-tenant ISP SaaS platform rather than a simple single-tenant billing application.

## Guiding Strategy

1. Preserve existing working ISP domain logic first.
2. Add tenant isolation before exposing SaaS functionality.
3. Introduce a clean application/service boundary around existing modules.
4. Add role-based access control for Master Admin, Admin, Reseller, Employee and Customer.
5. Add network automation and reconciliation as first-class services.
6. Add payment/webhook abstractions so gateways are replaceable.
7. Add background jobs, observability and API contracts before high-scale features.
8. Migrate UI incrementally; do not break the existing billing workflow during the transition.

## Target Roles

- Master Admin: platform owner; manages tenants, subscriptions, global settings and platform operations.
- Admin: ISP tenant owner; manages customers, packages, routers, billing, employees and reports.
- Reseller: sells packages/customers under an ISP tenant with wallet/commission controls.
- Employee: scoped operational access assigned by the tenant admin.
- Customer: self-service portal for invoices, payments, package/status and account information.

## Major Target Modules

### 1. Multi-Tenancy

- Tenant entity and lifecycle.
- Strict tenant-scoped queries and writes.
- Tenant-aware authentication and authorization.
- Tenant status, limits and subscription state.
- Platform-level audit trail.

### 2. SaaS Subscription

- Admin subscription plan: 250 BDT/month.
- Reseller subscription plan: 100 BDT/month.
- Subscription status and expiry handling.
- Tenant feature/usage limits.
- Platform billing and payment records.

### 3. Identity & RBAC

- Master Admin, Admin, Reseller, Employee and Customer roles.
- Permission-based authorization rather than role-name checks scattered through pages.
- Session/JWT boundary designed for future API clients.
- Refresh-token rotation when the modern API layer is introduced.

### 4. ISP Billing

Retain and improve existing capabilities where available:

- Customers.
- Packages/plans.
- Invoices and payments.
- Customer balance.
- Auto renewal.
- Voucher generation.
- Hotspot and PPPoE.
- FreeRADIUS integration.

### 5. MikroTik & Network Automation

- Multiple router support.
- Router credentials stored securely.
- API and SSH connection methods.
- Router health checks.
- PPPoE user provisioning.
- PPPoE suspension/unsuspension.
- Overdue automation.
- Maximum 10-day suspension workflow where configured.
- Automatic PPPoE profile changes.
- Reconciliation between billing state and router state.
- Live traffic/bandwidth monitoring.
- Operation logs and retry-safe commands.

### 6. Payments

Gateway abstraction with webhook-first reconciliation:

- bKash.
- Nagad.
- SSLCommerz.
- Stripe.
- Payment initiation.
- Callback/webhook validation.
- Idempotent payment processing.
- Automatic invoice/payment reconciliation.

### 7. Notifications

- SMS.
- WhatsApp.
- Telegram.
- Invoice reminders.
- Payment confirmation.
- Expiry/overdue warnings.
- Suspension/restore notifications.

### 8. ERP

- Inventory.
- Customer assets/CPE where applicable.
- Employee management.
- Reseller wallet and commission.
- Expenses and operational records as the ERP scope expands.
- BTRC reporting module.

### 9. API & Real-Time

Target architecture:

- REST API.
- WebSocket events for live dashboard/network state.
- OpenAPI/Swagger documentation.
- Versioned API endpoints.
- Idempotency for external callbacks and critical mutations.

### 10. Background Processing

Target infrastructure:

- Redis.
- BullMQ or equivalent queue worker layer.
- Scheduled billing jobs.
- Router synchronization jobs.
- Notification jobs.
- Payment reconciliation jobs.
- Retry/dead-letter handling.

### 11. Modern Frontend

Target frontend direction:

- Next.js App Router.
- React.
- Tailwind CSS.
- Responsive/mobile-first UI.
- PWA support.
- Separate dashboards for platform, ISP admin, reseller, employee and customer.

## Data Architecture Direction

Current Nux storage is MySQL-oriented. The Ispluka target is PostgreSQL for the modern SaaS architecture.

The migration must therefore be incremental. Existing production billing data should not be discarded or rewritten blindly.

Recommended sequence:

1. Inventory current tables and relationships.
2. Mark tables as reusable, tenant-scoped, replaceable or legacy.
3. Define canonical Ispluka entities.
4. Add tenant identifiers and constraints where safe.
5. Build migration/import tooling.
6. Validate billing totals and network credentials before cutover.

## Reuse / Modify / New Rule

### Reuse where practical

- Customer billing concepts.
- Package concepts.
- Voucher logic.
- PPPoE/Hotspot domain behavior.
- MikroTik integration primitives.
- FreeRADIUS integration.
- Existing notification/payment plugin concepts.

### Modify/refactor

- Authentication and authorization.
- Router/network services.
- Billing automation.
- Payment processing.
- Notification dispatch.
- Database access boundaries.
- UI navigation and dashboards.
- Cron/background tasks.

### Build new

- Multi-tenant core.
- Tenant isolation enforcement.
- SaaS subscription system.
- Master Admin platform dashboard.
- Reseller wallet/commission system.
- Employee permission model.
- API gateway/service layer.
- WebSocket event layer.
- Redis queue architecture.
- PostgreSQL target schema.
- Live bandwidth dashboard.
- MikroTik reconciliation engine.
- BTRC reporting.
- Modern Next.js/PWA frontend.

## First Implementation Milestones

### Milestone 1 — Foundation

- Document current schema and application boundaries.
- Identify authentication, customer, package, router, payment and cron entry points.
- Define tenant model and RBAC model.
- Add architecture documentation and migration rules.

### Milestone 2 — Tenant-safe Core

- Introduce tenant-aware data access.
- Add role/permission model.
- Add audit logging.
- Add platform/admin separation.

### Milestone 3 — Network Reliability

- Normalize MikroTik connection configuration.
- Add connection health and operation logging.
- Implement idempotent PPPoE provisioning/suspension.
- Implement billing-to-router reconciliation.

### Milestone 4 — Payment Reliability

- Normalize payment records.
- Add webhook/idempotency layer.
- Implement bKash/Nagad/SSLCommerz/Stripe adapters as supported integrations.

### Milestone 5 — SaaS & Modern UI

- Subscription/plan management.
- Reseller system.
- Next.js dashboard.
- WebSocket live status.
- PWA.

## Non-Negotiable Engineering Rules

- Never trust a tenant ID supplied by the browser without server-side authorization.
- Every tenant-owned query must be tenant-scoped.
- Payment callbacks must be authenticated/verified and idempotent.
- Router commands must be retry-safe and logged.
- Secrets must not be committed to Git.
- Destructive schema changes require migration and rollback planning.
- Existing working billing behavior must be covered by tests before major refactoring.
- Do not mix platform-level and tenant-level permissions.
- Do not claim a feature is complete until the complete workflow is tested end-to-end.
