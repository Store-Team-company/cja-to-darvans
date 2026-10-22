# Incremental Data Pilot SaaS update (existing SiteGround app)

**Purpose:** Add ONLY the SaaS-01A customer signup/login/verified-email/workspace foundation to an existing *split-layout* Data Pilot installation. Keep your original CJ importer, products, settings, API credentials and Botble target untouched. This is NOT a new installation or production-ready paid SaaS.

**Compatibility:** Based on the existing SiteGround application from the earlier October 22, 2026 development packages. The update script compares SHA-256 of the three pre-existing PHP files before replacing them and refuses to overwrite any customized version. This cannot guarantee your live server is on the exact required baseline until its preflight succeeds.

**Do NOT use the "full installation" ZIP over an existing site.** This incremental ZIP is a development-stage update, and the SaaS feature stays disabled by default.

## GitHub and SSH method

1. In SiteGround **Site Tools > Security > Backups**, take a full site snapshot and an independent MySQL backup. Download copies of the existing \`data-pilot-app/.env\` and the three PHP files if SiteGround Backup isn't available.
2. Open SiteGround **Site Tools > Devs > SSH Keys Manager**, enable an SSH key, and connect to your server using that account. Do not give your SSH private key, database password, or API tokens to ChatGPT.
3. Upload **data-pilot-saas-changes-only.zip** to the **parent directory** containing \`data-pilot-app/\` and \`public_html/\`. Do not upload it inside \`public_html\`.
4. Open SSH and change to the same parent directory, e.g.:
   \`\`\`sh
   cd /home/customer/www/YOUR-DOMAIN
   unzip data-pilot-saas-changes-only.zip
   php APPLY-SaaS-UPDATE.php --check
   \`\`\`
5. **Only if preflight returns PASS and you have a database backup**, execute:
   \`\`\`sh
   php APPLY-SaaS-UPDATE.php --apply --db-backed-up
   \`\`\`
6. The CLI update creates a private backup of original PHP files, copies only the changed SaaS code, runs **additive** \`saas_*\` table migrations, and clears caches. It does NOT change \`.env\`, existing product tables, \`public_html\`, \`vendor\`, uploads or live connections.
7. Check that \`https://YOUR-DOMAIN/login\` (legacy Data Pilot) and \`/sync-queue\` still load correctly. New \`/app/login\` remains **404 by design** until you explicitly opt in on staging.
8. Delete the extracted \`payload/\`, \`APPLY-SaaS-UPDATE.php\`, and \`update-manifest.json\` from your server after a successful update. Keep private PHP backups outside \`public_html\`.

If \`--check\` reports mismatched SHA-256 of an existing file, STOP. Do not manually overwrite. Compare your live source to the GitHub base or deploy to a separate staging installation instead.

## Testing the private SaaS pages (STAGING ONLY)

Edit the staging site's *private* \`data-pilot-app/.env\` and add:

\`\`\`dotenv
SAAS_ENABLED=true
SAAS_REGISTRATION_ENABLED=true
SAAS_PUBLIC_NAME="Data Pilot"
\`\`\`

Configure working SMTP email credentials in that staging app before attempting signup. Then from \`data-pilot-app/\` run:

\`\`\`sh
php artisan optimize:clear
\`\`\`

Open \`https://STAGING-DOMAIN/app/register\`, create a test user, verify email, and view \`/app\`. If email verification fails, keep registration disabled until mail delivery is corrected.

**DO NOT enable SaaS registration on your public website yet.** There is no Stripe checkout, webhook-based entitlements or functional Botble connector pairing/publishing in this milestone. The separate Botble plugin scaffold is intentionally NOT deployed to your Laravel SaaS site by this update.

## Important

- Your old legacy \`bdp_*\` products are unchanged and NOT accessible to SaaS customer accounts; legacy users keep using \`/login\`.
- \`saas_products\` and \`saas_*\` tables are initially empty. No customer subscription has been purchased or charged.
- The private update backup covers application code, not MySQL. Retain a real MySQL backup before executing migrations.
- This changes-only ZIP has just 19 source paths and **no** .env, Stripe keys, Botble credentials, packages, existing product data or installation wizard.
- If something goes wrong, DO NOT automatically roll back database changes. Restore the original code from the private backup, then inspect migration status and data carefully.
