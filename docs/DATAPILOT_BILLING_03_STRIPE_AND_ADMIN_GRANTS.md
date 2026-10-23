# DATAPILOT-BILLING-03 — Real Stripe checkout and audited complimentary workspace access

**Draft review only.** Real Stripe checkout already exists in the reviewed
DataPilot SaaS code, but the private SiteGround billing switch and keys
must be configured. Do not mark billing LIVE without a verified webhook.

## Distinct states

1. **Stripe paid subscription:** Checkout at Stripe's hosted HTTPS page.
   Only a signature-verified webhook plus Stripe subscription status and
   recurring Price ID can mark a paid workspace active. A browser returning
   from Stripe's success URL is not payment proof.
2. **Admin complimentary grant:** BDP platform admin creates a separate
   dated, auditable grant with one of the existing plan tier limits and a
   1–90-day expiry. It does NOT generate a Stripe customer, subscription,
   charge or invoice, or mark a Stripe billing row paid. A customer may
   purchase a real Stripe subscription during an active complimentary grant.
3. **CJ and Botble technical releases:** Product retrieval remains subject
   to its separate `SAAS_CJ_DRAFT_IMPORT_ENABLED` switch and existing
   connection authorizations. A grant alone cannot deploy absent API code
   or enable automatic publishing.

## Existing plan definitions (verify your intended price before billing)
- Starter: displayed **$19 per month**; 250 products, 1 Botble store, 2 users
- Growth: displayed **$49 per month**; 2,000 products, 2 Botble stores, 5 users
- Agency: displayed **$99 per month**; 5,000 products, 5 Botble stores, 15 users

These values are source-code defaults, not Stripe-confirmed prices.
Before collecting payment, create matching recurring USD monthly Stripe
Prices and manually verify amount, currency, interval, trial settings,
tax handling and business account in the Stripe dashboard.

## Private SiteGround setup (test mode first)

Laravel uses these PRIVATE configuration keys:

    SAAS_ENABLED=true
    SAAS_REGISTRATION_ENABLED=false
    SAAS_BILLING_ENABLED=true
    STRIPE_SECRET_KEY=sk_test_...
    STRIPE_WEBHOOK_SECRET=whsec_...
    STRIPE_PRICE_STARTER=price_...
    STRIPE_PRICE_GROWTH=price_...
    STRIPE_PRICE_AGENCY=price_...

Do not paste keys, whsec secrets or full .env into chats, GitHub issues,
CI, browser console or a public log. Do not run automated Stripe API calls
using live keys before billing QA. All three prices and secret keys must
belong to the SAME test-mode Stripe account.

Create the Stripe webhook endpoint:

    https://openai.duasbyfk.pk/api/saas/stripe/webhook

Subscribe to `checkout.session.completed`, `checkout.session.expired`,
`customer.subscription.created`, `customer.subscription.updated` and
`customer.subscription.deleted`. Copy the webhook signing secret to
the private .env file. Validate that Stripe can reach the public endpoint,
signatures verify, and replay/duplicate events never duplicate access.

Run `php artisan config:clear`, then check readiness WITHOUT printing secrets:

    php artisan tinker --execute='
    $p = app(\App\Services\Saas\SaasPlanCatalog::class);
    echo "SaaS: ".(config("saas.enabled") ? "YES" : "NO").PHP_EOL;
    echo "Billing: ".(config("saas.billing_enabled") ? "YES" : "NO").PHP_EOL;
    foreach (["starter","growth","agency"] as $code) {
        echo $code." checkout configured: ".($p->checkoutReady($code) ? "YES" : "NO").PHP_EOL;
    }
    '

For a real paid purchase, open **Customer → Billing & Plans** and choose
**Subscribe with Stripe**. Only a valid Stripe webhook should grant paid
entitlements. Verify that cancellation, expiration and duplicates behave
correctly. Start with Stripe TEST mode only; enable LIVE mode separately
after independently confirmed production purchase/refund/cancellation QA.

## Admin complimentary grants (this PR, default disabled)

Once BILLING-03 staging release is reviewed and installed, admin grants
are independently controlled by:

    SAAS_ADMIN_GRANTS_ENABLED=false

Enable only when you want staff to authorize complimentary access:

    SAAS_ADMIN_GRANTS_ENABLED=true

The existing legacy BDP administrator must log in using its existing
admin session. The new admin screen `/saas-workspaces` is protected by
the same administrator middleware. The link is displayed on
`/saas-customers` only when explicitly enabled. Each action requires
CSRF, valid plan, 1–90 days, reason, exact confirmation, and an audit event.
Suspended workspaces and already-paid Stripe subscriptions cannot receive
a new manual grant. Expired/revoked grants immediately lose access unless
a separately paid subscription is valid.

Customer UI must say **Complimentary access** and indicate an expiry;
it must not display **Stripe paid** for this state.

## Release safety / SiteGround deploy

This PR is stacked on reviewed PRODUCT-01 source HEAD
`5e327a5f587d7e205cb6f1a76ccc16322307f62a`. It must not silently
merge unrelated PRs. Follow independent security QA, then backup the full
SiteGround DB and application, preserve private .env outside webroot.

Run a read-only preflight from a CLEAN source checkout:

    cd ~/dev/data-pilot-src
    git fetch origin
    git switch feature/datapilot-billing-03-admin-grants
    git pull --ff-only origin feature/datapilot-billing-03-admin-grants
    git rev-parse HEAD
    git status --short
    php scripts/deploy-siteground-billing03.php --check \
      --target="$HOME/www/openai.duasbyfk.pk/data-pilot-app"

A `BILLING-03 ABORT` on customized files is a safety guard, not an
instruction to delete files or bypass checks. Only after an independently
approved exact HEAD, private backups and successful preflight:

    php scripts/deploy-siteground-billing03.php --apply \
      --backup-confirmed --expected-head=APPROVED_40_CHARACTER_COMMIT_SHA \
      --target="$HOME/www/openai.duasbyfk.pk/data-pilot-app"

This selectively updates existing admin/SaaS controllers, entitlements,
billing views, config, and protected routes, adds the grant model, admin
controller/view and ONE additive grant+audit migration
`2026_10_23_000009_create_saas_admin_plan_grants.php`.

**Rollback:** Disable `SAAS_ADMIN_GRANTS_ENABLED`, clear Laravel config,
restore only the exact files from the installer's private rollback directory,
remove only new files listed by `ADDED_FILES.txt`, clear caches. Leave
additive grant/audit tables intact so audit history is preserved.

## QA gate

- Both exact-head CI workflows PASS, including grant expiration, tenant
  isolation, administrator-only actions, paid Stripe precedence, no customer
  checkout access to another workspace and no false paid status.
- No Stripe paid records created by administrator grants.
- Two-sided SiteGround proof: Billing & Plans shows Subscribe with Stripe
  only after valid private configuration; real test-mode Checkout opens.
- Only correctly signed Stripe events change Stripe-paid status.
- Admin trial grant and revoke confirmed in restricted platform admin UI.
- Customer overview and billing label free access distinctly.
- CJ pilot, Botble pairing, private product drafts and legacy admin preserved.
- No user data or Stripe credentials included in GitHub/CI.
