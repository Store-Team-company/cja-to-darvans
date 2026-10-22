# BOTBLE-03B2 — first-party Data Pilot to sandbox.daystart.pk pairing

Development/staging QA only. No production deployment, no general SaaS billing bypass.

## Why the installed plugin cannot pair immediately

The Botble plugin intentionally does not generate Workspace IDs, Store IDs or one-time
codes. Only the authorized merchant workspace in Data Pilot can issue those credentials.
Before this PR, pairing required SaaS billing to be enabled and an active subscription.

This change adds a server-configured, default-off first-party pilot exclusively for
one internal workspace and https://sandbox.daystart.pk. It does not provide any
new access to public SaaS customers, paid plans, CJ imports or product publishing.

## Release prerequisites

- Require independent QA and approval of stacked PRs #14, #17, #18, #19.
- Verify exact current code and full database/files backup on the Data Pilot staging
  installation; do not overwrite unrecognized server modifications.
- This PR does not include a SiteGround delta updater. Do not deploy by replacing the
  entire application ZIP.
- Have an existing verified internal /app owner in an ACTIVE internal workspace.
  Obtain the existing public workspace UUID from the portal. Do not invent UUIDs.
- Keep public registration and live billing disabled.
- Install Botble Connector v0.3.1 on the backed-up sandbox and confirm its version.

## Step 1 — server-only Data Pilot staging environment

Keep these values in Data Pilot's PRIVATE environment only, not in GitHub or Botble:

    SAAS_ENABLED=true
    SAAS_REGISTRATION_ENABLED=false
    SAAS_BILLING_ENABLED=false
    SAAS_BOTBLE_PILOT_ENABLED=true
    SAAS_BOTBLE_PILOT_WORKSPACE_ID=EXISTING_INTERNAL_WORKSPACE_UUID
    SAAS_BOTBLE_PILOT_STORE_URL=https://sandbox.daystart.pk
    SAAS_BOTBLE_PILOT_STORE_ID=

Clear Laravel config cache after changing environment values.

## Step 2 — register the exactly authorized store

Sign into Data Pilot /app as the configured verified owner.
Open the workspace Connections page and register exactly
https://sandbox.daystart.pk as a Botble store. Only the authorized single
first-party staging store can be registered without a paid plan.

Copy the newly registered Store ID UUID from the Connections page.
Set on Data Pilot PRIVATE server:

    SAAS_BOTBLE_PILOT_STORE_ID=REGISTERED_STORE_UUID

Clear config cache again. A Generate one-time pairing code button will be
available to the verified owner for this exact store. The code is displayed
once, is 64 hex characters, expires in 10 minutes and has strict attempt limits.

## Step 3 — connect inside Botble admin

Visit https://sandbox.daystart.pk/admin/data-pilot/connector while logged in
as an authorized Botble administrator. Enter the real Data Pilot Workspace ID,
Store ID and fresh pairing code. The plugin contacts the trusted HTTPS Data
Pilot endpoint and stores a per-store encrypted signing secret when verified.

Do not enter a MySQL hostname or username; sandbox.daystart.pk is a public
store URL. The legacy single-store Data Pilot /settings connection form is
not the merchant SaaS connection flow.

## Safety and rollback

All first-party decisions check SaaS enabled, a private opt-in, an ACTIVE
specific workspace ID, the fixed public HTTPS store URL and, for actual
pairing, the exact registered store UUID. Existing verified-email owner,
tenant authorization, one-time challenge expiry/replay and encryption remain.

To disable: revoke the pairing on both Data Pilot and Botble, turn
SAAS_BOTBLE_PILOT_ENABLED off, clear Laravel config cache. Retain old
products and mapping records. Neither Botble inbound drafts nor native
catalog writes should be enabled before real compatibility QA.

CJ outbound jobs, secure images, variations, catalog acknowledgments,
staging end-to-end tests and release authorization remain separate tasks.
