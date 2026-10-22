# DATAPILOT-AUTH-04 — OTP SMTP incident runbook (P0)

Status: **NOT PASSED — live SiteGround inspection and real inbox acceptance required.**

## Confirmed by GitHub

AUTH-03 is PR #13, still a draft based on the SaaS-02 branch and NOT
present on GitHub main. The existing AUTH-03 service uses synchronous
Laravel Mail::send, not a queued Mailable. Generic `/app/otp/send`
responses are intentionally the same for eligible and unknown emails
and cannot establish email delivery. Failed SMTP sends were caught
and logged with only an exception class.

The user has now **confirmed** the SiteGround staging .env originally
contained `MAIL_PORT=587465`, which is outside the TCP range 1–65535.
After changing it to `465` with `MAIL_ENCRYPTION=ssl`, a PHP SSL
connection to `mail.duasbyfk.pk:465` still failed. OpenSSL confirmed
DNS, TCP and TLS 1.3 network connectivity, but explicit hostname
verification returned **error 62: hostname mismatch**. The remote
certificate identifies `duasbyfk.pk`, not the configured SMTP hostname
`mail.duasbyfk.pk`.

These are **two confirmed mail configuration/hostname validation faults**.
A matching TLS hostname is required but does not by itself prove SMTP AUTH,
provider sender authorization, Gmail acceptance or mailbox delivery.

The actual correct outgoing server must be read from SiteGround → Site Tools
→ Email → Accounts → Mail Configuration → Manual Settings. Do not replace
`MAIL_HOST` with the certificate CN automatically or turn certificate
verification off. GitHub access cannot edit the private server's .env,
read the user's inbox or complete a real login.

## Staging verification (read-only first)

SSH to the account that owns the **private** Data Pilot application:

    cd ~/www/openai.duasbyfk.pk/data-pilot-app
    pwd
    test -f .env && test -f artisan && test -f vendor/autoload.php && echo "Private installation confirmed"

Do not paste .env, secrets, OAuth/SMTP tokens, application keys or OTP codes
into GitHub or support messages. Verify source and OTP migration separately.
If reviewed AUTH-04 CLI diagnostic is installed, from this directory run:

    php scripts/diagnose-siteground-auth04.php
    php scripts/diagnose-siteground-auth04.php --probe-network
    php scripts/diagnose-siteground-auth04.php --probe-network --probe-host=PROVIDER_APPROVED_SMTP_HOST
    php scripts/diagnose-siteground-auth04.php --account

`--probe-network` now checks verified implicit SSL or STARTTLS, not
just a TCP socket. `--probe-host` tests a provider-approved candidate
without changing private .env or authenticating. SSL peer and hostname
verification must stay ON. The correct host is the provider-supplied
Outgoing Server; testing the apex domain is not permission to use it
as the SMTP server.

The final command privately prompts for the affected account email
without writing it to logs. It reports only a non-secret account
reference, eligible membership, and challenge metadata.

Check the **private** .env locally, without disclosing MAIL_PASSWORD.
For the installed Laravel 10 config/mail.php, the supported encryption
setting is MAIL_ENCRYPTION, not MAIL_SCHEME. Effective config may differ
from .env if cached or if MAIL_URL overrides individual SMTP settings.

    MAIL_MAILER=smtp
    MAIL_HOST=PROVIDER_SUPPLIED_HOST
    MAIL_PORT=PROVIDER_SUPPLIED_VALID_TCP_PORT
    MAIL_ENCRYPTION=PROVIDER_SUPPLIED_TLS_MODE
    MAIL_USERNAME=PROVIDER_SUPPLIED_USERNAME
    MAIL_PASSWORD=PRIVATE_PROVIDER_SECRET
    MAIL_FROM_ADDRESS=PROVIDER_AUTHORIZED_SENDER
    MAIL_FROM_NAME="Data Pilot"

The above are **placeholders**, not suggested values. Do not copy
them verbatim. Use only the provider's authenticated, authorized
settings and SiteGround's applicable outbound SMTP rules. Check DNS,
TCP reachability, TLS type, authentication, sender authorization and
provider delivery/suppression/bounce logs. DNS and TCP success alone
do NOT prove authenticated SMTP or inbox delivery.

Once the correct SMTP hostname is confirmed by SiteGround and SSL
certificate validation passes, back up the private .env and edit only
`MAIL_HOST` (retain the verified port/encryption pairing). Keep the
SMTP password and sender domain authorization unchanged unless the
provider explicitly requires an update.

After updating the private environment:

    php artisan config:clear
    php artisan view:clear

The new CLI diagnostic does not send test mail or disclose credentials.
The six-digit OTP must be requested through the actual app login flow.

## Safe AUTH-04 code update (only if AUTH-03 is already installed)

1. Obtain staging database/files backup and record the private
   installation directory. Never replace the whole app to fix SMTP.
2. Verify AUTH-03 files, OTP migration and deployed hashes against the
   reviewed AUTH-03 branch. If divergent, **STOP**.
3. Check out the reviewed AUTH-04 exact HEAD on a private separate
   source clone / clean branch. Before running, replace
   EXACT_REVIEWED_SHA below with the exact QA-approved 40-char SHA.
4. Dry run using the dedicated delta updater:

       cd ~/dev/data-pilot-src
       git status --short
       php scripts/deploy-siteground-auth04.php --check --target=$HOME/www/openai.duasbyfk.pk/data-pilot-app

5. After independent review of the preflight and backup, apply **only
   the two PHP auth files plus a CLI-only diagnostic script**:

       php scripts/deploy-siteground-auth04.php --apply --backup-confirmed --expected-head=EXACT_REVIEWED_SHA --target=$HOME/www/openai.duasbyfk.pk/data-pilot-app

This updater aborts if its source branch is dirty, if it is not an
AUTH-03 descendant, if the deployed files do not hash-match the
approved AUTH-03 baseline, or if the expected reviewed SHA changed.
It creates a private backup for changed files and clears caches.
It does NOT change .env, database tables, credentials, customer
accounts, Botble, Stripe, CJ, public_html or vendor dependencies.

## Acceptance checks

A. Confirm affected account exists and has at least one active
membership to a non-suspended workspace. Do not disclose it publicly.
B. Request OTP through /app/otp/send and check internal logs for
challenge_created and transport_accepted_unconfirmed, or
mail_configuration_invalid / transport_failed.
C. **Observe actual email arrival** at a monitored inbox. Confirm
six-digit code, received timestamp and relevant SMTP provider message
or bounce logs when available. No logged OTP values.
D. Submit the code; verify sign-in, session regeneration and proper
workspace redirect. Do not send the OTP back in a support report.
E. Verify invalid, expired, consumed/replayed codes are rejected.
F. After 60 seconds, request a new code and verify it arrives. Ensure
an SMTP-failed resend did not destroy an already-valid code.
G. Simulate unavailable SMTP in a SAFE test environment; verify generic
public response, redacted internal diagnostics, no 500 or login session.
H. Confirm unknown/legacy/suspended accounts cannot enter SaaS portal.
I. Confirm existing password login and legacy admin login still work.

No code-side Mail::fake() test, SMTP socket connection or
transport_accepted_unconfirmed event is evidence of inbox delivery.
AUTH-04 remains BLOCKED until the real end-to-end result is witnessed.

## Rollback

The delta updater prints the unique private backup directory.
After disabling public registrations and stopping OTP attempts,
restore ONLY the two backed-up application PHP files from that
directory, remove the newly added CLI-only diagnostic file if recorded
in ADDED_FILES.txt, then from the private application root run:

    php artisan optimize:clear

For a server-only SMTP correction, restore only the documented prior
non-secret transport configuration using the private secure backup.
Never roll back users, OAuth tokens, OTP data or product tables.
If AUTH-03 was never installed, do NOT apply AUTH-04 delta; follow
the separate reviewed AUTH-03 staging deployment procedure first.
