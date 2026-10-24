# DATAPILOT-BOTBLE-NATIVE-01A — Controlled Native Draft Import (Design / Runtime Gate)

**Status:** DESIGN / COMPATIBILITY GATE ONLY. NO NATIVE CATALOG WRITE APPROVED.

## Verified against the actual sandbox.daystart.pk installation (2026-10-23)

- Botble Ecommerce **3.11.9**, Laravel **13.13.0**, DataPilot Connector **0.3.3** receiving signed protocol-v2.
- Inbox draft **ID 1**, CJ product `2505090210081629800`, status `pending_review`, 40/40 variations and 20 gallery URLs, effective price USD **80.00**.
- Product was edited with regular selling price USD **120.00**, sale price USD **80.00**, but protocol-v2 currently sends only the **effective** price. **DO NOT import 80 as an undiscounted regular price.**
- The paired source product has no Botble native product mapping, as expected.
- Observed tables: `ec_products`, `ec_product_variations`, `ec_product_variation_items`, `ec_product_attribute_sets`, `ec_product_attributes`, `ec_product_with_attribute_set`, `meta_boxes`.
- Product columns include `price`, `sale_price`, `images`, `image`, `status`, `stock_status`, `with_storehouse_management`, `allow_checkout_when_out_of_stock`, `is_variation` and `weight`.
- Native `ProductVariation::getVariationByAttributesOrCreate` and `Product::productAttributeSets` methods are present; signatures, return shape, side effects, transaction semantics and installed-media integration have **NOT** been verified.
- Existing `CatalogImportService::approve()` hard-blocks protocol-v2 independent of the generic importer flag, limits its older variant path to 20, and blocks image-bearing drafts. **Do not remove these safeguards prematurely.**

## Release split — preserve approved signed-transfer milestone

1. **NATIVE-01A (this phase):** Exact-runtime read-only compatibility: inspect method signatures, local source implementations, required product/attribute columns, storage access and media APIs, slug model and SEO metadata contracts. Build deterministic 40-variation dry-run validation and negative tests. No product writes.
2. **NATIVE-01B (contract upgrade):** Introduce a separately gated versioned *signed* envelope that retains **regular price and optional sale price**, with explicit reviewed, validated per-variation pricing. Do not redefine existing v2 meaning; legacy inbox draft remains read-only. Preserve per-store HMAC, nonce, timestamp, pairing, max payload, strict idempotency, owner confirmation and no cost/stock leakage. If separate variation prices are not yet configured, require explicit confirmation of a uniform regular/sale pair.
3. **NATIVE-01C (media):** Secure CJ HTTPS media acquisition with allowlisted domains and IP/DNS pinning, no private/link-local addresses, no redirects to arbitrary hosts, TLS verification, strict content type, decoded-format / dimensions, file and aggregate limits. Use Botble's supported media storage mechanism, not ad-hoc public-file writes. Stage and validate required assets **before** native catalog DB transaction; ensure failed import cleans staged files. Never silently drop the main/gallery/variation images.
4. **NATIVE-01D (catalog draft creation):** Admin-triggered, role-authorized, CSRF-protected importer with separate feature switch. Validate product uniqueness, source mappings, all unique CJ IDs/SKUs/options, existing SKU collisions, valid Botble attribute sets and IDs, 40 native variation records with correct parent relationship and option values, SEO record ownership, safe HTML, retail/sale prices and all local media references. Use Botble-native model APIs only after installed-version compatibility verified. Transaction with lock-based idempotency and rollback, no partial catalog records. Keep parent and child `status=draft`. Preserve preexisting manual products and mappings.
5. **NATIVE-01E (acceptance):** Run exact-head CI + independent security QA. Use a disposable Botble instance / DB clone first, verify 1 parent + 40 children, gallery and variation media, prices (regular 120, sale 80), options, SEO, no missing images, no public storefront listing, no duplicates after retry, rollback on intentional failure. On live sandbox verify backups and deploy via hash-guarded overlay. Only then enable the precise separate native-v3 importer switch for the pinned store.

## Non-negotiable invariants

- Both currently live servers must retain signed delivery, HMAC verification, pairing and read-only incoming inbox behavior without regression.
- NEVER flip `DATAPILOT_CATALOG_IMPORT_ENABLED` merely because the tables and method names exist.
- Legacy protocol-v2 product draft **ID 1** remains review-only; native catalog import must require a new verified signed draft with explicit regular/sale semantics.
- Only admins with `datapilot-connector.settings` permission may approve. No HTTP API route is allowed to write directly to Ecommerce.
- Do not infer stock from CJ variation listing; until quantities are verified, keep drafts unpublishable and checkout disabled for any future publication.
- Unknown Color/Size parsing is not allowed. Preserve original supplier attribute as `CJ Option` until human-reviewed mapping supports typed attributes.
- Fail closed on unsupported native models/media/SEO APIs, collision, partial media, inconsistent pricing, >100 variations, modified signing key, or incomplete source data.
- Do not claim production success from database schema existence or mocked tests.

## Next exact-runtime information needed (read-only)

Collect native helper reflection parameters and declaring path, complete schema column lists for attribute and variation tables, product image casts, and whether Botble Media facades / SEO metadata classes are loaded. Do not print secret values, full file paths containing credentials or existing customer data. Inspect method source **locally**, not a guess from a newer version's public documentation. Keep DB and catalog untouched.

## Release controls

All work behind feature branches, draft PRs, exact-HEAD CI, independent QA and only then guarded sandbox deployment. Initial branch is documentation and runtime readiness only; **no implementation/deployment was performed by this branch**.

## Exact-runtime findings: 2026-10-24

- Installed Ecommerce 3.11.9 ec_products includes price, sale_price, image, images, stock flags, and is_variation. Price and sale_price cast to float; status casts to Botble BaseStatusEnum; image/images are not explicitly cast. Verify Product accessor/mutator before assigning image arrays.
- ec_product_attribute_sets: title, slug, display_layout, status, order. ec_product_attributes: attribute_set_id, title, slug, is_default, order.
- ec_product_variation_items: attribute_id, variation_id. ec_product_with_attribute_set: attribute_set_id, product_id, order.
- meta_boxes: meta_key, meta_value, reference_id, reference_type.
- ProductVariation::getVariationByAttributesOrCreate is STATIC with configurableProductId and attributes parameters. Need installed method body/return contract before calling it.
- RvMedia, SeoHelper and MetaBox facades are all available.
- Official API docs https://docs.botble.com/cms/api.html describe REST/Sanctum and optional API-key protection, not a verified native admin-write API for 40 variation products. Do not enable a second general-purpose REST API for this proven signed connector.
- Official media docs https://docs.botble.com/cms/media.html describe RvMedia::handleUpload with validated UploadedFile from a local path. Only use after SSRF-safe download and content validation.
- Official metadata docs https://docs.botble.com/cms/meta-box.html show MetaBox::saveMetaBoxData($product, 'seo_meta', [...]). Verify supported model configuration before writing.

## Read-only contract prototype

NativeDraftPreflight::inspect() is pure: no database, network, file or media write. It explicitly blocks existing schema-v2 inbox item from native import and validates a FUTURE v3 signed contract with reviewed regular and optional sale prices, 1..100 complete CJ IDs/SKUs/option labels, HTTPS image references and no guessed stock or supplier costs. Passing this preflight does NOT authorize native import and does NOT verify remote URL safety. Tests: tests/Feature/BotbleNativeDraftPreflightTest.php.

## Remaining exact installed-runtime inspection

Inspect the local ProductVariation helper implementation/return structure, Product image accessors/mutators, sale price handling, status and metadata model support. No native catalog writes until that adapter is verified with atomic rollback and image intake.


## Installed native helper implementation: confirmed 2026-10-24

The Botble 3.11.9 server source was inspected using ReflectionMethod for ProductVariation::getVariationByAttributesOrCreate(int|string $configurableProductId, array $attributes): array.

**Exact observed behavior:** it first obtains any existing variation via getVariationByAttributes($configurableProductId, $attributes). Otherwise it creates an ec_product_variations model with configurable_product_id, constructs CreatedContentEvent(PRODUCT_VARIATIONS_MODULE_SCREEN_NAME, request(), $variation), and creates ec_product_variation_items rows for each attribute ID. Returns ['variation' => $variation, 'created' => true] for a new relation, and ['variation' => $variation, 'created' => false] for an existing one.

**Critical limitation:** this method does NOT explicitly create a native child ec_products row or assign product_id in its body. A model lifecycle hook or event listener could conceivably do so, but that has not been verified. Existing CatalogImportService::upsertVariation currently rejects missing $relation->product_id, preventing mislinked catalog writes; retain this fail-closed guard. Also the method constructs an event object, which is not proof of event dispatch or child creation. Inspect getVariationByAttributes and model lifecycle/observers before implementing a native adapter.

**Native import still BLOCKED:** No new native product or variation may be created using this helper until a disposable-clone integration test proves parent/child linkage, lifecycle side effects, transaction rollback, existing variation reuse and uniqueness, and no customer product mutations. All live catalog-import switches remain OFF.

### Next operator read-only source check

Use PHP Reflection on the installed ProductVariation class to print getVariationByAttributes plus candidate product/configurableProduct relationship methods, and verify ProductVariation::getObservableEvents. Do not invoke the create helper or its events during inspection. Review read-only model event listener registration and any Product creation service.

### Native acceptance edge cases

- After a dry run on a disposable store, require native relation.product_id to reference an existing Product with is_variation=true and the correct configurable_product_id; if not, atomic rollback.
- Do not create parent twice on retries or reuse an existing nonmapped native product just because its SKU looks similar.
- Do not assume new CreatedContentEvent(...) dispatches or invokes event listeners. Verify actual lifecycle behavior first.
- Require atomic rollback for intentionally failing variation #40, and prove all created products, relations, media staging references and mappings are clean.
- Protocol v2 inbox ID 1 retains price USD 80 but no separate USD 120 regular; native write must continue to reject this envelope.


## Exact native child-product pathway discovered from installed source grep (2026-10-24)

Operator's read-only grep identified the *installed* Botble Ecommerce source at platform/plugins/ecommerce/src, including these verified paths and steps:

- Importers/ProductImporter.php lines 762+: getVariationByAttributesOrCreate($product->getKey(), $addedAttributes) creates/finds the relation, then $version merges relation values with an import request.
- Importers/ProductImporter.php lines 779-820: reuse/update an existing mapped child Product when appropriate; otherwise instantiate new Product() and fill($version). Lines 823-826 set name, status, brand_id, is_variation=1. **Next method lines (after 826) not provided yet**; cannot assume save/link ID assignments.
- Http/Controllers/ProductController.php lines 179-201: after variation creation and attributes, sends variation data through postSaveAllVersions([...], $product->getKey(), $this->httpResponse()).
- Traits/ProductActionsTrait.php lines 197-205 and 346-360: transaction-wrapped variation creation and postSaveAllVersions(...) for admin edits.
- Services/Products/CreateProductVariationsService.php line 34 creates/fetches variation records but alone does not prove native child Product persistence.

These findings refute any assumption that the variation helper alone creates the child Product; native Ecommerce uses a second save/link step. The existing DataPilot CatalogImportService rejects any missing relation.product_id; keep this guard and implement the **installed** follow-up logic in a dedicated transactional adapter only after inspecting the remaining lines.

### Exact remaining read-only source excerpts

On the daystart.pk Botble SSH account in ~/www/sandbox.daystart.pk/public_html:

  sed -n '815,875p' platform/plugins/ecommerce/src/Importers/ProductImporter.php
  grep -n 'function postSaveAllVersions' platform/plugins/ecommerce/src/Traits/ProductActionsTrait.php

The second grep returns the method start line. Inspect that method with sed using its exact start/end, not an arbitrary global replace. In particular confirm how $variation->product_id is linked to saved child Product ID and whether SKU/price/image normalization or events happen before commit.

Do not call the creation method or enable DATAPILOT_CATALOG_IMPORT_ENABLED; signed inbox ID 1 remains protocol-v2 / pending_review and must stay untouched.
