# Data Pilot ADMIN-04 — Manage Customers

Status: Draft PR. Existing BDP administrator session remains the only platform-admin authentication.

## Navigation
After an approved deployment, visit \`/admin/customers\` and use **Administration → Manage Customers**.

## Scope
- Search by workspace name, UUID, customer email/name, filter by workspace status; 20-per-page pagination.
- Overview of SaaS customers, owners, verification, workspace membership, connected Botble stores/CJ connection **status only**, product count and Stripe billing status.
- Review individual workspace members, connector statuses and admin audit history.
- Suspend workspace with required reason and restore the status recorded before suspension.
- All write operations POST-only, CSRF-protected, rate-limited, only the existing \`bdp_logged_in\` admin session is authorized. SaaS customers cannot access platform routes.
- Separate additive \`bdp_customer_admin_audit\` table. Customer product databases and identities are not changed by this feature.
- Never display CJ API keys, access/refresh tokens, Botble signing secrets, Stripe customer/subscription IDs, OTPs, passwords, checkout URLs, or card details.
- Suspension disables workspace access and SaaS entitlements but **DOES NOT cancel a Stripe subscription**. Administrators must handle billing separately within Stripe. Reinstatement does not override entitlement checks or create a subscription.
- No manual email verification or Stripe-plan activation; do not implement those as shortcuts for broken SMTP.

## Remaining
- Need separate staff identities/roles for multiple platform admins, immutable audit actor IDs, customer support ticket system, team invitation and account recovery workflows.
- Independent security QA and deploy preflight required before production.
