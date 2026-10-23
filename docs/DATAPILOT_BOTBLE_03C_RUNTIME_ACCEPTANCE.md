# BOTBLE-03C v0.3.3 - Runtime QA and safe upgrade gate

Current live Botble sandbox screen reports Data Pilot Connector v0.3.2.
The tracked base for this source change is v0.3.1 (PR #19), so NEVER
overwrite the existing installed v0.3.2 plugin without identifying and
reconciling the differences.

## Read-only on the sandbox.daystart.pk SSH account

cd ~/www/sandbox.daystart.pk/public_html
php artisan --version
php -r '$a=json_decode(file_get_contents("platform/plugins/datapilot-connector/plugin.json"),true);echo "Botble connector: ".($a["version"]??"unknown").PHP_EOL;'
php artisan route:list --path=api/datapilot/v1/drafts
php artisan cms:plugin:list | grep -i ecommerce || true
php -r 'foreach(["platform/plugins/datapilot-connector/src/Http/Controllers/DraftInboxController.php","platform/plugins/datapilot-connector/src/Services/CatalogImportService.php","platform/plugins/datapilot-connector/src/Services/ConnectorSigning.php","platform/plugins/datapilot-connector/resources/views/incoming-products.blade.php"] as $f){echo $f," ",is_file($f)?hash_file("sha256",$f):"NOT FOUND",PHP_EOL;}'
php artisan tinker --execute='echo "V1 inbox: ".(config("datapilot-connector.inbound_enabled")?"ON":"OFF").PHP_EOL;echo "V2 inbox: ".(config("datapilot-connector.variant_drafts_enabled")?"ON":"OFF").PHP_EOL;echo "Native importer: ".(config("datapilot-connector.catalog_import_enabled")?"ON":"OFF").PHP_EOL;'

These commands must not dump secret keys, .env contents, DB credentials or
encrypted payloads. Keep the existing v1 inbox ON but v2/native importer OFF
until reviewed.

## v2 contract

- Separate DATAPILOT_VARIANT_DRAFTS_ENABLED flag default OFF.
- Inbound route preserves old v1 semantics, 64KiB maximum and nonce/HMAC.
- V2 accepts <=262144 bytes, exactly 1..100 complete variants.
- All variant IDs, SKUs, source option names must be unique and present.
- Currently only full label CJ Option is permitted. Never guess Color/Size.
- Merchant-confirmed uniform retail price must match every variant; no cost fallback.
- Safely store HTML, SEO, weights, images only in encrypted pending_review inbox.
- Do not download any remote media or write native ecommerce records in the v2 stage.
- No Botble Product/Variation change is allowed even if older importer switch is ON.
- HMAC/replay/idempotency tests and a 40-variant test must pass.

## Upgrade and rollback checklist

1. Full backup of platform/plugins/datapilot-connector and matching DB snapshot.
2. Inspect the v0.3.2 deployed source changes (not just plugin.json version).
3. Review CI/test logs and artifact for PR #31 exact commit.
4. Deploy plugin only on disposable/staging Botble, preserve pairing and draft records.
5. Verify pairing is still valid, receiving route exists and new v2 flag is OFF.
6. After explicit QA approval, turn ON only v2 staging, not the native importer.
7. After 40-variant transfer, confirm pending_review record and no ec_products writes.
8. Rollback with both v2 flags OFF, restore reviewed backup, preserve audit.
