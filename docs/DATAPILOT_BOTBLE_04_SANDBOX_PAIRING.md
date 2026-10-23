# BOTBLE-04 — First real DataPilot ↔ Botble sandbox pairing

**Goal:** Connect only the approved DataPilot customer workspace at
`https://openai.duasbyfk.pk` to its own Botble sandbox at
`https://sandbox.daystart.pk`. No CJ integration, product writes,
or Stripe subscription bypass for unrelated customers.

## Current staging baseline

DataPilot AUTH-05B uses password login and platform-admin approval
without requiring email OTP. BOTBLE-04 must be deployed on top of
that installed version, not on the older OTP-based BOTBLE-03 branch.

Repo: `Store-Team-company/cja-to-darvans`
Branch: `feature/datapilot-botble-04-password-pilot-pairing`
PR: #23 (draft until exact-head CI and live QA)

The Botble Connector plugin is already reported installed on
the sandbox in prior work (v0.3.0); check its actual active status,
database migrations and server configuration before using it. Version
0.3.1 is a separate native admin UI improvement and **not necessary to
authenticate a valid 0.3.0 pairing**.

## Staging deployment to DataPilot server

1. Back up the DataPilot staging application AND database using
   SiteGround tools. Do not reveal .env, secrets, pairing codes or
   existing customers' personal information.
2. Use a clean Git checkout of the BOTBLE-04 branch at the exact
   QA-approved 40-character commit; verify the sha after git fetch.
3. Preflight from the **source** checkout:

       cd ~/dev/data-pilot-src
       php scripts/deploy-siteground-botble04.php --check \
         --target="$HOME/www/openai.duasbyfk.pk/data-pilot-app"

4. Do **not** apply if any file has a mismatch. The updater expects
   the exact AUTH-05B code in the affected files and explicitly refuses
   to overwrite unexpected SiteGround local customizations.
5. After preflight PASS, backup verification and independent approval:

       php scripts/deploy-siteground-botble04.php --apply \
         --backup-confirmed --expected-head=QA_APPROVED_COMMIT \
         --target="$HOME/www/openai.duasbyfk.pk/data-pilot-app"

   This copies only the pairing routes/controller/view/service/config
   changes, and runs only migration
   `2026_10_22_000005_create_saas_botble_pairing_challenges.php`.
   It does not touch email login/admin auth, billing/Stripe, .env,
   vendor files, product catalogs, or public_html.
   Save the generated PRIVATE rollback path.
6. Verify on DataPilot staging:

       cd ~/www/openai.duasbyfk.pk/data-pilot-app
       php artisan migrate:status | grep create_saas_botble_pairing_challenges
       php artisan route:list --path=api/saas/botble/pair/complete
       php artisan route:list --path=app/workspaces

   The Botble pairing-completion POST route must exist.

## Restrict the private first-party pilot

Because this specific internal sandbox is not subscribed, authorize
only its exact workspace and host. Never set a global free-plan or
skip entitlement middleware.

1. Log in using the existing **password-based** customer account. In
   the browser's DataPilot Connections page URL, copy the current
   workspace UUID from `/app/workspaces/UUID/connections`.
2. On DataPilot **private server only**, back up its .env securely.
   Set these values for registration:

       SAAS_BOTBLE_PILOT_ENABLED=true
       SAAS_BOTBLE_PILOT_WORKSPACE_ID=EXACT_UUID_FROM_CONNECTIONS_URL
       SAAS_BOTBLE_PILOT_STORE_ID=
       SAAS_BOTBLE_PILOT_STORE_URL=https://sandbox.daystart.pk

   The actual customer workspace must be active. Keep
   `SAAS_REGISTRATION_ENABLED=false` and billing unchanged.
   Never paste private .env or tokens into a ticket/chat.
3. Run `php artisan config:clear` from DataPilot private app.
4. Refresh Connections in the customer browser; a registration form
   must appear only for `https://sandbox.daystart.pk`.
   Register store, then copy the generated *Store ID UUID*.
   A store record marked `pending_verification` is not yet paired.
5. Set `SAAS_BOTBLE_PILOT_STORE_ID=THE_EXACT_STORE_UUID` in private
   DataPilot .env and run `php artisan config:clear` again.
   The form **Generate one-time pairing code** should now appear.
6. Request a fresh pairing code. It expires after 10 minutes, is
   shown once, and must **never be copied into chat, logs or GitHub**.

## Botble sandbox site

1. Sign into the Botble CMS admin on `sandbox.daystart.pk`.
   Go to `/admin/data-pilot/connector` (the plugin admin prefix
   can differ if customized).
2. Verify Data Pilot Connector is **activated** and its migrations
   have run, including its pairing table. Do not reinstall unless
   it is genuinely missing.
3. Verify Botble's PRIVATE configuration uses:

       APP_URL=https://sandbox.daystart.pk
       DATAPILOT_SAAS_URL=https://openai.duasbyfk.pk

   Do not paste full .env or a password. If changed, clear Laravel
   config cache on the Botble server. Site URL must match exactly.
4. In the plugin admin form, enter the DataPilot workspace UUID,
   store UUID and the **one-time** pairing code from the DataPilot
   authenticated Connections page. Submit within ten minutes.
5. The Botble backend POSTs the code securely to
   `https://openai.duasbyfk.pk/api/saas/botble/pair/complete`.
   A successful response returns matching IDs and a generated 256-bit
   shared signing key; the plugin stores it encrypted.
6. Observe both sides: DataPilot store status **connected** and
   Botble connector status **paired**. A saved record or 200 HTTP
   response alone is insufficient without matching IDs/statuses.

## Independent QA

- Only the exact active workspace and sandbox URL can register
  without a paid plan. All other tenants remain subscription-gated.
- An unapproved user, another workspace member, viewer, or a signed-out
  browser cannot issue/revoke a code.
- Wrong site URL, malformed ID/code, expired code, replay and revoked
  store fail. Attempt limit and encrypted key confirmed.
- Password-based customer login, admin approval and logout still work.
- Incoming product drafts remain disabled until separately enabled
  and tested. No catalog products changed.
- Neither staging .env nor secret fields are printed or committed.
- Support two-sided rollback: DataPilot private backup and independently
  revoke any Botble plugin pairing.

**Status:** No real handshake completed solely by deploying GitHub code.
The actual SiteGround/ Botble admin steps above are required for final PASS.
