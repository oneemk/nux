# Ispluka Migration & Architecture Plan

## Goal

Evolve the current PHPNuxBill-based Nux codebase into the Ispluka ISP Billing & Management ERP while preserving proven ISP billing/network functionality where practical.

The target is a production-ready, multi-tenant ISP SaaS platform rather than a simple single-tenant billing application.

## Critical Compatibility Rule — Existing MySQL Must Keep Working

**The current production MySQL database connection must remain unchanged during the migration.**

This means:

- Do not change the existing MySQL database host, port, database name, username or password.
- Do not require the user to create a new MySQL user or change the current database password.
- Do not replace the existing MySQL configuration with PostgreSQL.
- Do not perform an automatic MySQL-to-PostgreSQL cutover.
- Do not rename/drop existing tables or columns that current Nux functions depend on without a backward-compatible migration and rollback plan.
- Existing login, customers, packages, invoices, payments, vouchers, PPPoE, Hotspot, FreeRADIUS, MikroTik, notifications, plugins and cron workflows must continue to work.
- New Ispluka functionality must be introduced additively and feature-by-feature.
- Database migrations must be optional, explicit, reversible where practical, and safe for an existing installation.
- Existing `config.php` credentials remain the source of truth for the legacy MySQL application until a later, separately tested migration path is approved.

### Database Strategy

The implementation will use a **MySQL-first compatibility architecture** initially:

1. Keep the existing Nux MySQL connection and ORM behavior intact.
2. Audit the current schema before adding any tenant/RBAC tables.
3. Add new Ispluka tables without modifying existing billing tables unless necessary.
4. Where existing records need tenant ownership, introduce compatibility-safe mapping rather than forcing immediate destructive schema changes.
5. Keep legacy pages and cron jobs operational throughout the transition.
6. Introduce service/repository boundaries around database access so a future PostgreSQL adapter can be added without breaking MySQL.
7. Only after the MySQL implementation is stable and fully tested should PostgreSQL become an optional migration target.

The PostgreSQL target remains an architectural direction for the future modern SaaS layer; it is **not a prerequisite for the current installation**.

## Guiding Strategy

1. Preserve existing working ISP domain logic first.
2. Preserve the existing MySQL credentials and connection path.
3. Add tenant isolation before exposing SaaS functionality.
4. Introduce a clean application/service boundary around existing modules.
5. Add role-based access control for Master Admin, Admin, Reseller, Employee and Customer.
6. Add network automation and reconciliation as first-class services.
7. Add payment/webhook abstractions so gateways are replaceable.
8. Add background jobs, observability and API contracts before high-scale features.
9. Migrate UI incrementally; do not break the existing billing workflow during the transition.

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
- Initial tenant mapping must not invalidate existing single-tenant installations.

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
- Existing session/login behavior must remain available until the replacement is proven stable.

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
- Existing MikroTik behavior must not be disabled while the new service layer is introduced.

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
- Existing payment gateway configuration must remain usable during migration.

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

The API layer will initially operate alongside the existing PHP application; it will not require replacing the current MySQL-backed pages.

### 10. Background Processing

Target infrastructure:

- Redis.
- BullMQ or equivalent queue worker layer.
- Scheduled billing jobs.
- Router synchronization jobs.
- Notification jobs.
- Payment reconciliation jobs.
- Retry/dead-letter handling.

Existing cron jobs remain operational until their replacements are verified.

### 11. Modern Frontend

Target frontend direction:

- Next.js App Router.
- React.
- Tailwind CSS.
- Responsive/mobile-first UI.
- PWA support.
- Separate dashboards for platform, ISP admin, reseller, employee and customer.

The modern frontend will consume new APIs and coexist with the current UI during migration.

## Data Architecture Direction

Current Nux storage is MySQL-oriented. The Ispluka long-term target is PostgreSQL for the modern SaaS architecture.

**Current implementation target: MySQL compatibility first.** Existing production billing data should not be discarded, rewritten blindly, or moved automatically.

Recommended sequence:

1. Inventory current tables and relationships.
2. Verify the live configuration and connection assumptions.
3. Mark tables as reusable, tenant-scoped, replaceable or legacy.
4. Define canonical Ispluka entities.
5. Add new compatibility-safe tables first.
6. Add tenant identifiers/mappings only where safe.
7. Build migration/import tooling before any cross-database move.
8. Validate billing totals and network credentials before any cutover.
9. Keep PostgreSQL as a separately tested future adapter/target.

## Reuse / Modify / New Rule

### Reuse where practical

- Customer billing concepts.
- Package concepts.
- Voucher logic.
- PPPoE/Hotspot domain behavior.
- MikroTik integration primitives.
- FreeRADIUS integration.
- Existing notification/payment plugin concepts.
- Existing MySQL configuration and connection.

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
- PostgreSQL adapter/target schema as a later phase.
- Live bandwidth dashboard.
- MikroTik reconciliation engine.
- BTRC reporting.
- Modern Next.js/PWA frontend.

## First Implementation Milestones

### Milestone 1 — Foundation

- Document current schema and application boundaries.
- Identify authentication, customer, package, router, payment and cron entry points.
- Verify the existing MySQL configuration path.
- Define tenant model and RBAC model.
- Add architecture documentation and migration rules.
- No production credential change.

### Milestone 2 — Tenant-safe Core

- Introduce tenant-aware data access without breaking legacy tables.
- Add role/permission model.
- Add audit logging.
- Add platform/admin separation.
- Preserve existing login and billing paths until the new path is verified.

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

### Milestone 6 — Optional PostgreSQL Migration

- Only after MySQL compatibility is proven.
- Export/transform data with validation.
- Run dual-read/validation where practical.
- Test rollback before cutover.
- Keep the original MySQL database intact until the new system is accepted.

## Non-Negotiable Engineering Rules

- **Never require changing the existing MySQL username or password.**
- **Never replace the current MySQL connection merely to introduce Ispluka features.**
- Never trust a tenant ID supplied by the browser without server-side authorization.
- Every tenant-owned query must be tenant-scoped.
- Payment callbacks must be authenticated/verified and idempotent.
- Router commands must be retry-safe and logged.
- Secrets must not be committed to Git.
- Destructive schema changes require migration and rollback planning.
- Existing working billing behavior must be covered by tests before major refactoring.
- Do not mix platform-level and tenant-level permissions.
- Do not claim a feature is complete until the complete workflow is tested end-to-end.
- No production cutover without backup, validation and rollback procedure.
