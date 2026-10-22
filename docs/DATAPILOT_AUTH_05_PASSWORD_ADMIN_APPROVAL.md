# DataPilot AUTH-05 — Password login and manual account activation

**Status:** Draft feature; deploy to SiteGround staging only after independent QA.

## Purpose

DataPilot SaaS customers sign in using email + password. Passwordless
six-digit OTP login endpoints are removed and produce 404. A platform
administrator may manually activate a customer **without pretending their
email address was verified**. The legacy single-store BDP admin panel
continues to use its original admin login.

The new fields `users.saas_manually_approved_at` and
`users.saas_password_change_required` are separate from Laravel's existing
`email_verified_at`. Historical OTP challenges are deliberately retained
for audit and rollback, but cannot authenticate through any SaaS route.

## Admin workflow

1. Sign into DataPilot's **legacy platform administrator panel** at
   `https://openai.duasbyfk.pk/login` using existing administrator credentials.
2. Go to **Administration → Customer Accounts**, or
   `https://openai.duasbyfk.pk/saas-customers`.
3. Locate an existing SaaS customer. Only users who belong to at least one
   accessible workspace can be activated.
4. To approve an account whose owner already knows the password, leave
   temporary password fields empty and click **Approve / update password**.
5. For an existing OTP-only customer (whose original password was randomly
   generated), provide a strong 12+ character temporary password and
   confirmation. The value is stored as a hash, never shown again.
6. Deliver any temporary password **privately** to the intended account holder
   by an independently verified channel. Never include passwords in
   screenshots, PRs, system logs or public chat.
7. The customer signs into `/app/login` and, when required, must choose a
   new password before any workspace page is available.

Approve does **not** set `email_verified_at`, assign a plan or change
workspace membership. Suspended workspaces and inactive memberships are still
denied. Normal verified-email customers retain password access.

## Registration and SMTP

`SAAS_REGISTRATION_ENABLED` remains OFF unless deliberately enabled.
When enabled, registration requires a strong password; a new account is
pending until activated. Signup does not attempt OTP or verification email.
SaaS password sign-in does not require working SMTP.

The existing optional email password-reset functionality is retained for
compatibility, but requires a working mail provider. It is not needed
when an administrator assigns a temporary password.

## SiteGround release process — changes-only

Prerequisite: AUTH-04 is already installed at its exact reviewed base
`1dba1784083c8e4a9bb6c765c3e7ff12fd8d38d7`.

1. Privately back up the DataPilot staging DB and application files.
   Preserve `.env`, existing users, workspaces, Stripe secrets,
   CJ data, Botble integration, and the previous AUTH-04 rollback directory.
2. Use a **clean source checkout** on
   `feature/datapilot-auth-05-password-admin-approval`.
3. Identify the approved exact commit from PR #21 and check both CI jobs.
4. Run dry-run preflight from the Git source worktree:

       cd ~/dev/data-pilot-src
       php scripts/deploy-siteground-auth05.php --check \
         --target="$HOME/www/openai.duasbyfk.pk/data-pilot-app"

5. Do **NOT** apply if preflight reports a changed live file. It protects
   local SiteGround edits by comparing every replaced file to the exact
   reviewed AUTH-04 baseline.
6. After independent approval of the output and full backup:

       php scripts/deploy-siteground-auth05.php --apply --backup-confirmed \
         --expected-head=APPROVED_40_CHARACTER_COMMIT \
         --target="$HOME/www/openai.duasbyfk.pk/data-pilot-app"

The updater installs only the listed auth/admin files, backs up replaced
files outside the web root, runs the **one additive migration**
`2026_10_22_000004_add_manual_saas_customer_approval.php` and clears caches.
It never modifies `.env`, product data, Stripe billing, CJ/Botble code or
the existing OTP database table.

## Acceptance tests

- Legacy administrator login and dashboard continue to function.
- Only that administrator can view /saas-customers, approve accounts
  or change temporary passwords; SaaS customer sessions cannot do so.
- Manual approval leaves `email_verified_at` untouched.
- Existing OTP-only user can be assigned password and enter /app/login
  without receiving mail.
- A temporary password must be rotated before accessing workspace pages.
- Wrong password, unknown account, suspended workspace, revoked memberships
  and unapproved accounts are denied.
- OTP URLs 404 and do not create challenges or email.
- Password reset remains available separately but SMTP failure is
  handled without HTTP 500.
- No unrelated feature or data regressions.

## Rollback

The updater prints the private AUTH-05 backup directory.
Stop new logins, restore **only backed-up PHP/Blade/route files** from that
directory, delete files listed in `ADDED_FILES.txt` if created by AUTH-05,
then run `php artisan optimize:clear`. Do not run `migrate:rollback`
or drop the additive user columns/audit table; the prior version ignores them.
Restore normal access after verifying previous AUTH-04 behavior.
