# Data Pilot SaaS-02 — Portal, pricing and Stripe billing

**Development status:** Draft PR only. Customer signups, checkout, and public landing routes are OFF by default on existing installations. This is NOT approval to charge paying customers.

## Implemented

- Marketing landing at \`/platform\`, monthly pricing at \`/pricing\`. Existing \`/\` and legacy \`/login\` remain the private single-store admin.
- Improved mobile-friendly customer signup, login, verified email, password reset, onboarding dashboard.
- Verified paid workspace owners can store, rotate and revoke their own encrypted CJ API credentials, and register HTTPS Botble stores as pending verification. No real API calls or publishing are enabled yet.
- Customer pages at \`/app/workspaces/{public_id}\`: products, connections, team, billing and settings. All are verified-user, active-workspace-member routes; customer data is scoped through the workspace relation.
- Owner-controlled workspace name updates; team and connection management are deliberately read-only until secure pairing/invitation QA.
- Tenant-owned Stripe subscription schema \`saas_workspace_billing\` and unique webhook event ledger \`saas_stripe_events\`, additive migrations only.
- Workspace subscription Checkout via Stripe-hosted page and billing portal, both requiring workspace owner membership and subscription flags.
- Signed Stripe webhook at \`POST /api/saas/stripe/webhook\` with HMAC SHA256, timestamp tolerance, immutable raw payload, Stripe event deduplication and workspace/customer/subscription ownership validation.
- Checkout success redirect is NEVER treated as payment proof; only Stripe-verified webhook data updates subscription records.
- Explicit server-side \`SaasEntitlements\` for active status, plan price validation, expiry date, product/store/seat limits and future import/publish guards. No unfinished import/push routes exposed.
- SQL migrations preserve all existing \`bdp_*\` importer/product records and \`.env\`. No Stripe credentials or real prices committed.

## Required private configuration (STAGING ONLY, Stripe TEST mode)

\`\`\`dotenv
SAAS_ENABLED=true
SAAS_REGISTRATION_ENABLED=true
SAAS_BILLING_ENABLED=true
SAAS_PUBLIC_NAME="Data Pilot"
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_STARTER=price_...
STRIPE_PRICE_GROWTH=price_...
STRIPE_PRICE_AGENCY=price_...
\`\`\`

Create Stripe TEST recurring monthly prices for Starter/Growth/Agency. **Amounts shown in templates are currently proposed $19/$49/$99**, not live claims; configure actual Stripe recurring prices before checkout. Always verify amount/currency in Stripe Dashboard before enabling checkout and align public displayed amounts with Stripe prices.

Duplicate checkout prevention: Data Pilot records one pending Stripe-hosted Checkout URL per workspace and reuses it. Owners can expire an open Checkout via Stripe before selecting another plan. Stripe's `checkout.session.expired` webhook clears only the matching pending session.

Webhook endpoint: \`https://YOUR-STAGING-DOMAIN/api/saas/stripe/webhook\`.
Select events \`checkout.session.completed\`, \`customer.subscription.created\`, \`customer.subscription.updated\`, \`customer.subscription.deleted\`. Use Stripe TEST destination signing secret, never a live secret in staging.

Webhook signatures are validated against the raw HTTP payload and 300s tolerance. The Stripe webhook must not be routed through web/CSRF middleware. Test duplicate, out-of-order, and failed retries in Stripe dashboard and review server logs privately.

Customer account email reset/verification requires valid outgoing SMTP. Do not permit public signups until mail deliverability is verified.

## Independent production launch blockers

1. Major Laravel version upgrade from the existing Laravel 10 runtime, with regression QA and verified compatible PHP.
2. CJ official approval for commercial many-merchant credentials under documented per-IP account limits.
3. Functional **Data Pilot Botble Connector** authenticated pairing, revocation, draft product upserts, variants/media, rate limiting and idempotency.
4. CJ product import preview/pricing and background sync with full tenant scoping and signed job dispatch; tests prove unpaid/other-workspace access fails.
5. Invites and roles editing, support/admin panel, audit trails, privacy policy/terms, consent, email templates and hardening.
6. Independent Stripe test mode end-to-end QA, real webhook delivery including failed-payment/delinquency and event ordering, subscription renewal/cancellation, recovery and refunds.
7. Confirm exact website code baseline before applying incremental updates. Never run a fresh installation ZIP over a live site.

## Changes-only SSH deployment (isolated STAGING only)

From a clean GitHub checkout on SiteGround:

```sh
cd ~/dev/data-pilot-src
git fetch origin
git switch -c feature/datapilot-saas-02-portal-stripe-billing --track origin/feature/datapilot-saas-02-portal-stripe-billing
php scripts/deploy-siteground-saas-02.php --check --target="$HOME/www/YOUR-STAGING-DOMAIN/data-pilot-app"
```

After verifying independent staging site and MySQL backups, and only if preflight PASS:

```sh
php scripts/deploy-siteground-saas-02.php --apply --backup-confirmed --target="$HOME/www/YOUR-STAGING-DOMAIN/data-pilot-app"
```

The script checks 32 reviewed files against the previous approved release, rejects customizations, backs up replaced PHP and Blade files, and runs only the additive Stripe billing migration. It never overwrites .env, vendor, storage, public_html or legacy imported product data. Saas signup and billing flags remain OFF.

## Production status

Do not deploy this branch to the current live application or enable \`SAAS_BILLING_ENABLED\` until independent QA approves it. It is stacked on existing unmerged PRs; staged reconciliation is required. Keep all Stripe keys in private \`.env\` or a secret manager, never GitHub.

Official references:
- https://docs.stripe.com/api/checkout/sessions/create
- https://docs.stripe.com/api/customer_portal/sessions/create
- https://docs.stripe.com/webhooks
