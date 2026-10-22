# Data Pilot: Sync Queue sidebar + Category Mapping safe repair

**Existing website:** openai.duasbyfk.pk (SiteGround split-layout installation).

**This is NOT a full application reinstall.** This phase delivers exactly 7 reviewed files to the existing \`data-pilot-app\` and makes NO database schema changes. The existing SaaS workspace tables/credentials, product data, legacy admin login, composer dependencies, uploads and \`public_html\` remain unchanged.

## What changes

- Brings the earlier reviewed dashboard/Sync Queue sidebar UI from PR #3 into the live application.
- Displays useful import and queue empty states, correct live sync stats and search.
- Category Mapping GET reads local CJ category data and saved Botble category snapshots rather than forcing a remote database connection on each page load.
- Explicit **Refresh Botble Categories** action uses the configured Botble connection, does not trust a request-selected connection and does not fall back to Data Pilot's own MySQL connection.
- If Botble database settings are missing or placeholder values such as \`YOUR_SHOFY_DB_USER\`, the refresh shows safe setup guidance and preserves existing mapping data. No raw SQL error is returned to the browser.
- Login redirects for unauthenticated users are **expected** and remain intact. The SaaS customer portal uses \`/app/login\` and remains disabled until the public release process.

## Before deployment

1. **Take a real SiteGround backup** of the site and its database.
2. Confirm the server already has the separate checkout at \`~/dev/data-pilot-src\` and the private Laravel install at \`~/www/openai.duasbyfk.pk/data-pilot-app\`. Never turn the live application folder into a Git checkout.
3. **Do not add database credentials or .env to GitHub.** Keep \`SAAS_ENABLED=false\` and \`SAAS_REGISTRATION_ENABLED=false\` on production.
4. Connect to SiteGround SSH and run:

\`\`\`sh
cd ~/dev/data-pilot-src
git status --short --branch
git fetch origin
git switch -c feature/datapilot-ui-category-repair-20261022 --track origin/feature/datapilot-ui-category-repair-20261022
git status --short --branch
\`\`\`

If the branch already exists locally, use \`git switch feature/datapilot-ui-category-repair-20261022\`, followed by \`git pull --ff-only\`.

## Preflight ONLY (no changes)

\`\`\`sh
php scripts/deploy-siteground-ui-category-repair.php --check \
  --target="$HOME/www/openai.duasbyfk.pk/data-pilot-app"
\`\`\`

Expected: \`Preflight PASS: 7 files checked\` with the list of files to change.

The updater accepts only source files that exactly match the original reviewed SiteGround installation commit or the earlier UX fix commit, or are already at the reviewed target. If any file is customized or another version, it **stops** rather than overwriting. Ask for a file-by-file review.

## Apply after preflight + backup

\`\`\`sh
php scripts/deploy-siteground-ui-category-repair.php --apply --backup-confirmed \
  --target="$HOME/www/openai.duasbyfk.pk/data-pilot-app"
\`\`\`

The script saves the prior seven PHP/Blade files outside public_html under \`datapilot-private-backups/\`, installs only changed files, and clears compiled Blade views using \`php artisan view:clear\`. **No artisan migrate command or Composer install is executed.** Do not run \`git pull\` in \`data-pilot-app\`.

## Post-deployment verification

1. Sign in with the **existing admin credentials** at \`https://openai.duasbyfk.pk/login\`. The homepage and /sync-queue redirect to /login when you are not signed in; that is intentional.
2. Confirm Sidebar **Sync Queue** is visible and highlighted at \`https://openai.duasbyfk.pk/sync-queue\`.
3. Confirm \`/category-mapping\` opens without returning to Dashboard. If the remote store isn't connected, the page shows cached categories and a connection hint.
4. Press **Refresh Botble Categories** only after you have configured the correct existing Botble store connection in the private app environment. **Do not paste DB passwords into screenshots or chats.**
5. Check Products, CJ API Import, Dashboard, and mobile sidebar. Inspect site logs privately if there is an error.

## Rollback

The updater prints the full path of the private source backup. Restore only the backed-up files from the matching path into \`data-pilot-app\`, then run \`php artisan view:clear\` there. Do not delete or roll back the database.

## Not delivered in this repair

- No Stripe subscription checkout or plan enforcement.
- No customer onboarding enabled publicly.
- No working Data Pilot Botble Connector handshake/product write endpoints yet.
- This is a **legacy single-store admin UI repair**, not a way to connect arbitrary SaaS tenants to their own Botble databases.
