# DATAPILOT-CJ-01 — CJ API v2 authentication, P0 diagnosis and staged release

**Status:** DRAFT / INDEPENDENT QA REQUIRED. No live source deployment or CJ
API verification performed by GitHub development. No merge allowed yet.

## Scope

- Laravel 10 password-based DataPilot customer portal.
- One customer-owned CJ API key per workspace, encrypted at rest.
- Explicit **Save encrypted key**, **Test connection**, **Disconnect CJ**.
- Only a successful official CJ `getAccessToken` / `refreshAccessToken`
  response marks the connection `connected`; saves remain
  `pending_verification`.
- Last verified time and non-sensitive error state are visible.
- Fixed CJ API host; no remote URL supplied by customers, no redirects.
- No new products, inventory sync, orders or Botble catalog changes.
- Separate, default-OFF CJ staging pilot pinned to ONE internal workspace.
  This does not grant subscriptions or enable other workspaces/CJ rights.

Official CJ API:
https://developers.cjdropshipping.com/en/api/api2/api/auth.html

GET TOKEN: POST /api2.0/v1/authentication/getAccessToken with JSON
`apiKey`. REFRESH: POST
/api2.0/v1/authentication/refreshAccessToken with `refreshToken`.
Official docs currently describe 180-day tokens, per-IP quotas and
1-QPS authentication limits. Actual API responses must be validated;
never infer success from HTTP 200 alone.

## P0 production HTTP 500 — NOT YET CONFIRMED

The user supplied older SMTP exception lines, NOT a matching Connections
error from October 23. Therefore the root cause of the reported
authenticated Connections HTTP 500 cannot be established remotely.

Do not assert this PR fixes the live 500 without the real exception and
a successful request as the existing customer. The GitHub regression suite
includes manually approved accounts with active/pending workspaces and
Connections page GET. It proves only the test database path.

After reproducing the 500, inspect the private SiteGround Laravel/PHP
logs to establish the precise exception class, file and line, and inspect
the currently deployed `artisan --version`, customer auth middleware and
migration status. `scripts/diagnose-siteground-cj01.php` is available in
the **Git source**, but is not installed by this selective updater;
do not assume it already exists in the private installed app. Run it only
from a trusted checkout that has its own valid dependencies/configuration.
Do not copy source `.env` or production credentials into Git.

This reports *only* exception timestamps/classes and essential schema
flags, not exception messages or secrets. If the latest class is still
unclear, privately review the specific Laravel/PHP error trace without
pasting credentials, tokens, session cookies or unredacted messages.
Never use APP_DEBUG=true on a public endpoint.

## SiteGround release (only after independent approval)

Before any deployment, privately back up the SiteGround application and
database. Keep a separate secured copy of current .env outside webroot.

Prerequisites: approved AUTH-05B + BOTBLE-04 are already installed, including
`database/migrations/2026_10_22_000005_create_saas_botble_pairing_challenges.php`.
The prior server has custom legacy admin routes; this CJ patch MUST NOT
overwrite them.

On the DataPilot server, in the **clean Git source** checkout:

    cd ~/dev/data-pilot-src
    git fetch origin
    git switch feature/datapilot-cj-01-secure-connection
    git pull --ff-only origin feature/datapilot-cj-01-secure-connection
    git rev-parse HEAD
    git status --short

Compare HEAD to independently reviewed PR exact commit. If mismatched,
stop. Run read-only preflight:

    php scripts/deploy-siteground-cj01.php --check \
      --target="$HOME/www/openai.duasbyfk.pk/data-pilot-app"

No files or database should change during --check. Existing customized
files MUST cause ABORT, never be overwritten or force copied.

Only after DBA backup and approved dry run:

    php scripts/deploy-siteground-cj01.php --apply --backup-confirmed \
      --expected-head=EXACT_QA_APPROVED_HEAD \
      --target="$HOME/www/openai.duasbyfk.pk/data-pilot-app"

This backs up the replaced files outside public_html, copies CJ-only
services/model/controller/view/routes/config, runs TWO additive migrations
`2026_10_23_000006_add_saas_cj_auth_state.php` and
`2026_10_23_000007_guard_saas_cj_credential_revisions.php`, and clears
caches. The second migration provides monotonic credential revisions and
one-time verification-operation identity.
It does NOT edit private .env, change Stripe plans, bypass subscriptions,
change password auth, or install anything in Botble.

## Restricted internal pilot configuration

On DataPilot's PRIVATE .env only, after backups:

    SAAS_CJ_PILOT_ENABLED=true
    SAAS_CJ_PILOT_WORKSPACE_ID=YOUR_PRIVATE_WORKSPACE_UUID

Keep `SAAS_REGISTRATION_ENABLED=false`, and preserve all Botble pilot
settings independently. Pilot access requires workspace.status=`active`
and owner role. Do not enable a CJ pilot for other tenants.

Run `php artisan config:clear` from the private Laravel installation.
Customer login and **Connections** must render HTTP 200.

Log in to `https://openai.duasbyfk.pk/app` as the approved internal
customer. Paste the API key into **Save encrypted key**; do not paste the key
into support or GitHub. Click **Test connection**. Only a verified
`Connected` and updated Last verified timestamp confirm CJ
authentication. A failed response must not be called connected.

## Independent functional/security QA

- Live P0 stack class/file/line verified and fixed (or separate blocker).
- CI exact-head test and PHP formatting pass, selective release smoke passes.
- Valid CJ API key: successful authentication, encrypted credentials and
  tokens, connected status. Verify CJ account allows this SiteGround IP.
- Invalid key/expired refresh/revoked authorization/timeouts/429: safe
  error and never Connected.
- Repeated verify within 60 seconds cannot resend CJ authentication;
  no API key or access/refresh token in logs, HTML, JSON or session.
- Password login, customer approval, tenant membership, suspended access,
  other workspace denial, viewer/importer denial, and admin legacy pages.
- Key rotation (even saving an identical key) increments
  `credential_revision` and clears `verification_attempt_id`, previously
  saved tokens and last successful verification. An old HTTPS response
  cannot mark the newer unverified revision connected.
- Each outbound authentication reservation has a unique operation ID. A
  superseded response cannot overwrite a newer successful verification or
  downgrade it to Failed.
- Disconnection invalidates the in-flight operation. A workspace suspended
  or an owner revoked during the HTTP request cannot receive an old
  successful response.
- Disconnection removes encrypted credentials/tokens (local only); if
  full CJ token revocation is desired, it is a separate explicitly
  authorized operation.
- Existing BOTBLE-04 pairing remains unchanged, CJ import and catalog
  publishing remain disabled.

## Rollback

If staging fails, use the exact private backup directory printed by the
installer. Restore only replaced original files; delete added files listed
under `ADDED_FILES.txt`, then run `php artisan optimize:clear`. Leave the
additive auth-state DB columns in place (the older code ignores them).
**Do not** use migrate:fresh, force overwrite, broad package replacement or
production database rollback.

## Phase 2 gate

Only after independent QA confirms real CJ `Connected` should BOTBLE-04
pairing to `https://sandbox.daystart.pk` proceed. That pairing also needs
a separate successful two-sided live handshake. No product transfer work is
included in DATAPILOT-CJ-01.
