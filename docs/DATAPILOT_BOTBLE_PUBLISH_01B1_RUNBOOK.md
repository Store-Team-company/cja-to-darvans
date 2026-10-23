# DATAPILOT-BOTBLE-PUBLISH-01B1 - Complete CJ variant staging

Status: DRAFT / NO MERGE / NO DEPLOY without independent QA and compatible receiver.

## Acceptance product

DataPilot CJ product 2505090210081629800 has 40 source variations. The CJ API returns each stable ID, SKU and a combined option label; independently verified Color, Size and Style fields are not provided. The integration therefore preserves the full label in the single attribute named "CJ Option". It must never guess or silently truncate option combinations.

Requirements:
- Preserve all 40 IDs, SKUs, full labels and reviewed URLs; fail closed on duplicates, missing data or >100 variations.
- Require explicit merchant confirmation that the same stored retail price applies to ALL variants. Never use supplier cost as retail price.
- Never invent inventory quantities. No implicit "in stock" assumption.
- Carry safe HTML, SEO text, images and weight metadata as reference data in the encrypted pending_review inbox.
- Preserve HMAC signature, rotating nonce, exact raw-body integrity and a durable idempotency key.
- Never claim that staging an inbox draft creates a native Botble eCommerce product or publishes a listing.

## Compatibility, switches and protocol

Existing signed v1 <=64KiB requests remain unchanged. V2 adds schema_version = 2, variant_count, complete variant array (1..100), optional weights, safe descriptions and full reviewed image URLs. Maximum v2 raw body is 262144 bytes. The v2 receiver must verify exact variant count, uniqueness, data types and flat retail pricing. No publish or native import action is in the signed JSON.

SaaS new flag: SAAS_BOTBLE_VARIANT_DRAFTS_ENABLED=false, separate from the already deployed SAAS_BOTBLE_DRAFT_DELIVERY_ENABLED flag.

Botble new flag: DATAPILOT_VARIANT_DRAFTS_ENABLED=false, separate from DATAPILOT_INBOUND_DRAFTS_ENABLED.

Botble native import gate DATAPILOT_CATALOG_IMPORT_ENABLED must remain false. Even if someone changes that flag, v2 native import is rejected by a separate hard guard.

## Safe release order

1. Independently review PR #31 receiver v0.3.3 and compare it with the actual installed Botble v0.3.2. The branch underlying #31 starts from v0.3.1, therefore no blind ZIP overwrite. Check deployed plugin file hashes, routes and customizations.
2. Make private Botble plugin, database and env backups, then install an approved release on the sandbox only.
3. Verify the receiving v2 contract and ON inbound v1 switch. Enable only the separate Botble variant inbox flag.
4. Independently review sender PR #32 and check the hash-pinned source checkout, backup and destination file hashes. Run scripts/deploy-siteground-publish01b1.php --check first.
5. Install the approved sender without changing its .env. The installer only patches the sender service, controller, customer editor, SaaS config and CJ variant-enrichment parser, adding the variant builder; no schema or Botble changes.
6. After validating receiver compatibility, enable the DataPilot v2 sender flag and clear config cache.
7. Use an owner/manager customer session. Check the original 40 variation rows, retail price, imagery, and confirm a single uniform retail price explicitly in the staging form.
8. Stage once. Verify exactly 40 variants in Botble Incoming Products under pending_review; no ecommerce product and no public listing.
9. Verify a retry leaves the existing inbox record unchanged. Bad HMAC, replay, missing/duplicate IDs, mismatched counts, URLs or excessive sizes must be rejected.
10. Rollback by switching OFF v2 flags on both sites before restoring private file backups; preserve pairing and audit records.

## Further milestone: native Botble catalog import

NOT INCLUDED IN THESE PRS. Native imports require the *actual* installed Botble eCommerce model/schema signatures, manual or verified category mapping, safe media registration and storage, SEO mapping, approved local image fetch with no SSRF or open redirects, atomic mapped variation upserts, SKU collision checks and reliable rollback. Native writes remain disabled until independent runtime QA.
