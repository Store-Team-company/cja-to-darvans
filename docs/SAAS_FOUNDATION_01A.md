# Data Pilot SaaS — Phase 01A (development only)

**Status:** Feature branch implementation. No public SaaS launch. No Stripe charges. No CJ or Botble pushes.

## Confirmed product model

1. Merchant registers with verified email and creates a **workspace**.
2. Merchant selects a monthly **Stripe** subscription for the workspace (one billing owner).
3. Merchant authorizes **their own CJ Dropshipping API** (subject to CJ partner/IP/account limits).
4. Merchant installs the separate **Data Pilot Botble Connector** on their own store and pairs it to that workspace.
5. Merchant imports products -> reviews details/variants/cost/pricing -> publishes approved drafts to their own connected Botble store.
6. Staff can access only the capabilities their workspace owner grants; the SaaS super admin manages plans/accounts globally in a separate panel.

## Work implemented in 01A

- Isolated saas_workspaces, saas_workspace_memberships, saas_products, saas_cj_connections and saas_botble_stores tables.
- Workspace permission service and read-only dashboard; other workspace identifiers return 404.
- Encrypted database casts for CJ API and Botble connector credentials, with secret attributes excluded from serialization.
- Dedicated customer login, registration, email verification and sessions under /app, NOT shared with legacy BDP admin.
- Safe release flags SAAS_ENABLED=false and SAAS_REGISTRATION_ENABLED=false (default).
- CI tests for registration gating, membership isolation, roles, and credential-at-rest encryption.
- Draft Botble plugin structure in integrations/botble-connector/ — not production-ready.

## Critical scope boundaries

- Legacy single-store routes, migrations, dashboard, sync queue and CJ credentials remain unchanged.
- **Do not enable public registration** until Stripe Checkout, webhook-verified subscription entitlements, privacy/terms/email delivery and all write paths are workspace-scoped.
- Products in legacy `bdp_*` tables are NOT accessible to SaaS tenants, nor migrated in this phase.
- No unverified or unpaid customer can import or push from the new workspace; no import/push routes are exposed yet.
- Stripe Customer ID, price IDs and webhook secret are not configured. No hard-coded prices or payment secrets.
- Connector plugin currently has an authenticated admin status page only; no public pairing, secret exchange or product write endpoint yet.
- Planned billing must use Laravel Cashier for **Workspace as Billable model**, not per-user payments.
- Public product brand is configurable; Data Pilot is temporary working branding.

## Next phases

- 01B: upgrade Laravel 10 to Laravel 13 / PHP 8.3+ and add hard tenant-scoped product service and import policy.
- 02A: installable Botble connector handshake, revocable signed pairing, domain verification, store registration.
- 02B: safe and idempotent product draft upsert in Botble with image/variant compatibility tests.
- 03: CJ approved multi-account access, token refresh, API quotas, catalog sync and product review.
- 04: Stripe Cashier subscription checkout, webhook authentication, plan entitlement gates, billing portal.
- 05: separate Filament superadmin, usage controls, support and production monitoring.

## Blocking risk

CJ limits **3 CJ user accounts per originating IP** per its current docs. Obtain official CJ agreement for multi-merchant commercial use before enabling paid registrations.

References:
- https://developers.cjdropshipping.com/en/api/api2/standard/limit.html
- https://laravel.com/docs/13.x/billing
- https://docs.botble.com/cms/plugin-development/
