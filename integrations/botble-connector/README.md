# Data Pilot Botble Connector v0.3.0 — Pairing + Signed Draft Inbox

Status: **DEVELOPMENT / not approved for live product publication**. This is the first installable connector milestone. It supports secure merchant-controlled pairing with the Data Pilot SaaS, encrypted store secrets, and a disabled-by-default draft inbox. **It does not write Botble eCommerce products yet.**

## Installation — test Botble CMS only

This plugin is stored at \`integrations/botble-connector\` in the Data Pilot repository. Use the GitHub Actions artifact \`datapilot-connector-0.3.0.zip\`, extract it into the Botble application's \`platform/plugins/\` directory so the final folder is \`platform/plugins/datapilot-connector/\`.

Use the Botble admin plugin manager to activate the plugin. Plugin-owned migrations run through Botble's plugin lifecycle. Before installing, verify the target Botble release's provider registration, middleware, PHP requirement and eCommerce plugin version in an isolated test site.

Set the following **on the Botble site**, not the Data Pilot SaaS:

\`\`\`ini
APP_URL=https://YOUR_ACTUAL_BOTBLE_STORE
DATAPILOT_SAAS_URL=https://openai.duasbyfk.pk
DATAPILOT_INBOUND_DRAFTS_ENABLED=false
\`\`\`

Update the \`DATAPILOT_SAAS_URL\` setting to the eventual product domain when branded. Run \`php artisan config:clear\`. Then open the Botble administrator panel → **Data Pilot Connector**.

## Pairing

1. The merchant logs in to their **paid**, email-verified Data Pilot workspace.
2. Under Connections, register the exact public HTTPS URL of their Botble store.
3. Click **Generate one-time pairing code**. Copy the Workspace ID, Store ID and secret code before leaving the page. The code is 256 bits, shown once, valid for 10 minutes with 5 guesses maximum.
4. The merchant's Botble admin opens the plugin and enters the three values.
5. The plugin POSTs over TLS to \`/api/saas/botble/pair/complete\` with the trusted SaaS server URL from Botble server configuration, not a user-submitted callback URL.
6. Data Pilot checks the code hash, exact registered Botble domain, paid workspace, and replays, then returns a store-scoped 256-bit signing secret. Both ends encrypt it at rest.
7. Botble admin can revoke locally; Data Pilot workspace owner should also click **Revoke pairing** in their workspace. Pairing again rotates the secret.

This protocol trusts the merchant to enter the code only in the real Botble admin. **Stronger live site possession verification and signed revocation acknowledgments remain required for general availability.**

## Processing stages

\`\`\`text
Merchant CJ API (future: approved multi-user integration)
 → Data Pilot SaaS (tenant-scoped source snapshot; price/variant review)
 → signed JSON draft command
 → Botble Connector v0.3.0 (/api/datapilot/v1/drafts)
 → HMAC + timestamp + nonce + strict schema + idempotency checks
 → encrypted pending_review inbox record
 → eCommerce product/variant/category/media upsert (NEXT PHASE; unavailable)
\`\`\`

The inbox endpoint is **OFF by default** via \`DATAPILOT_INBOUND_DRAFTS_ENABLED=false\`. The incoming payload must be <=64KB, contain only approved fields and be signed with:

\`\`\`text
X-DataPilot-Store: <store_uuid>
X-DataPilot-Timestamp: <unix_timestamp>
X-DataPilot-Nonce: <32_lowercase_hex>
X-DataPilot-Signature: sha256=<hmac_sha256>
HMAC_INPUT = "POST\\n/api/datapilot/v1/drafts\\n" + timestamp + "\\n" + nonce + "\\n" + raw_json_body
\`\`\`

Store replay prevention is persisted in \`datapilot_connector_nonces\`, not cache-only. On a valid request the plugin only stages an encrypted snapshot and reports \`202\`. It does **not** create, edit, delete or publish a Botble eCommerce product.

## Data Pilot SaaS deployment

This PR adds \`database/migrations/2026_10_22_000004_create_saas_botble_pairing_challenges.php\`, SaaS route/controller changes and a merchant-facing pairing form. Do not deploy to the existing SiteGround app until the exact-version incremental updater and full QA are approved. Payment config remains OFF on the existing app, so pairing will return 503 until Stripe is configured in TEST mode.

## Required next phase

- Botble CMS runtime integration QA against the actual customer's Botble/eCommerce version (not merely Laravel PHPUnit tests).
- Safe SaaS outbound sender, public DNS/IP verification + HTTPS host pinning, retries, rate limits, per-tenant idempotency, signed commands and real end-to-end staging tests.
- Botble product draft upsert through version-tested Botble ecommerce services, configurable products/variants/options/media and category mapping. Product prices need explicit merchant review before publishing.
- Formal connector revocation delivery, support logs, migration rollback, audit, and subscription cancellation behavior.
- CJ commercial multi-merchant authorization before processing many merchants from one IP.

Official Botble plugin guide: https://docs.botble.com/cms/plugin-development/structure.html


## 0.3.0 B1 — limited native catalog preview (NOT APPROVED FOR STAGING INSTALL YET)

This release adds an admin-only **Incoming Products** review list and an explicit
approval route. It is a development candidate, NOT a finished CJ→Botble
synchronizer. Existing v0.2 pairing and encrypted draft inbox tables remain
unchanged; an additive plugin migration creates product/variation mapping tables.

- `DATAPILOT_INBOUND_DRAFTS_ENABLED=false` stays disabled by default.
- `DATAPILOT_CATALOG_IMPORT_ENABLED=false` stays disabled by default.
- Native import uses Botble's eCommerce Product/Variation/Attribute models; product
  status is **draft** at creation, and the plug-in never sends publish commands.
- Only payloads with explicit reviewed retail prices can be approved; SKU
  collisions and missing/changed native mappings block the transaction.
- Variation attributes must be a nonempty object (example `{"Color":"Red","Size":"M"}`);
  SKU-only variations cannot be approved. Variation helpers and pivot structure
  must be verified on the *actual* Botble eCommerce installation.
- Image payloads are staged but **cannot yet be approved for catalog import**;
  no unsafe remote media downloads are performed. Category/SEO mapping, full
  image support, real SaaS outbound delivery and first-party entitlement remain
  pending B1/B2 work.
- Native errors rollback the transaction; no connection credentials are rendered
  in the inbox. The imported status means a native **draft**, not a published
  product. The inbox's HMAC 202 response means *staged only*.

### Prepare the actual Botble staging compatibility check

On the Botble staging server, record the output of these read-only commands:

```sh
php artisan --version
php -v
php artisan cms:plugin:list
php artisan cms:plugin:list | grep -i ecommerce
```

Check the actual installed `platform/plugins/ecommerce/src/Models/` signatures,
`ec_product_variations` / `ec_product_variation_items` migrations, and the
implementation of `ProductVariation::getVariationByAttributesOrCreate` before
enabling native imports. Use an isolated staging database backup and an authorized
plugin upgrade from v0.2; never install this draft over a production store.

Sample signed payload additions (the existing HMAC envelope and `idempotency_key`
remain mandatory):
```json
{
  "idempotency_key": "11111111-1111-4111-8111-111111111111",
  "source": "cj",
  "source_product_id": "CJ-SAMPLE-1",
  "title": "Sample for internal review only",
  "sku": "CJ-SAMPLE-1",
  "description": "Review before publication",
  "price": 59.99,
  "quantity": 0,
  "variants": [
    {"source_variant_id":"CJ-SAMPLE-1-RED-M","sku":"CJ-SAMPLE-1-RED-M","price":59.99,"quantity":0,"attributes":{"Color":"Red","Size":"M"}}
  ]
}
```

Never reuse the example idempotency key in a real integration. No real CJ
products, customers, or Botble data have been transferred by this draft.
