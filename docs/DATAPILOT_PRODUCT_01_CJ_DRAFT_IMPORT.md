# DATAPILOT-PRODUCT-01 — CJ catalog → private DataPilot drafts

**Release:** Product retrieval and storage only. No Botble API call, signed transfer,
native catalog import, product publication, orders, inventory sync, scheduler or
automatic retries. This change is stacked on reviewed CJ-01 and remains draft
until independent QA.

## CJ API contract

Official API documentation:
- https://developers.cjdropshipping.com/en/api/api2/api/product.html
- https://developers.cjdropshipping.com/en/api/api2/standard/points.html

Uses **GET** `https://developers.cjdropshipping.com/api2.0/v1/product/listV2`.
Authorized through server-only `CJ-Access-Token` header. Query uses `page`,
`size=10`, and a required `keyWord` entered by an authorized workspace user.
CJ currently charges **50 points per listV2 request**. The nested response is
`data.content[].productList[]`, with identifiers `id`, titles `nameEn`,
SKU `sku`/`spu`, price `sellPrice`, image `bigImage`.

The provider response MUST have code 200, result=true and valid nested rows
before database writes. Unknown fields, HTML description, response metadata,
provider debugging messages, credentials, and tokens are **not saved**.
Selected snapshot fields: source, currency, sell_price_usd,
discount_price_usd, image_url, category, page, fetched_at.

## Product ownership and limits

Records are stored in existing `saas_products`. A unique database
constraint on `(workspace_id,cj_product_id)` prevents duplicate CJ records
within one tenant while allowing different tenants to save the same CJ ID.

Imported products have `status=draft`; rerunning a search updates only
drafts. Non-draft products are never overwritten.

Limit: 10 products per manual request, page 1–20, 60-second durable cooldown
per CJ connection, no periodic background importing. The explicitly
authorized **internal CJ pilot** permits workspace owners to store at most
50 products, independently of unrelated workspaces. Paid workspaces must
satisfy existing plan import limits and role permissions. Global
`SAAS_CJ_DRAFT_IMPORT_ENABLED` is OFF by default.

`last_product_fetch_at` is added in one reversible, additive migration
`2026_10_23_000008_add_saas_cj_product_fetch_throttle.php`. The code checks
the live credential revision and access-token identity again before saving
the response. If the key was rotated/disconnected or workspace suspended,
the response is discarded. Database transactions are not held open across
provider network calls. Expired tokens require the user to return to
Connections and run **Test connection**, which already refreshes CJ tokens.
This phase does not add a second token-refresh process.

## Customer workflow

1. Log in to the DataPilot customer workspace and confirm CJ status
   `Connected`.
2. Open `/app/workspaces/{workspace}/products`.
3. When separately authorized and enabled, enter a narrow product name
   or SKU keyword and a page from 1 through 20.
4. Click **Fetch and save CJ drafts**. Review only data in `My Products`.
5. Repeat later with a different keyword or page after cooldown. Confirm
   same CJ product IDs are deduplicated within the workspace.
6. **No product moves to Botble.** The Botble inbox, signed drafts and
   native import remain disabled until the next separately audited phase.

## SiteGround release controls (after independent QA)

Prerequisite: CJ-01 is live with add-on migrations `000006` and `000007`
already applied; the current production source baseline is the reviewed
CJ-01 head `01279fc9a0876dedde1587981dd13677d4e93e2e`.
SiteGround's public `.env` must be syntactically valid. Back up the
existing installation and full database using SiteGround before applying.

Review PR source and exact-head CI, then use the clean source checkout:

    cd ~/dev/data-pilot-src
    git fetch origin
    git switch feature/datapilot-product-01-cj-draft-import
    git pull --ff-only origin feature/datapilot-product-01-cj-draft-import
    git rev-parse HEAD
    git status --short
    php scripts/deploy-siteground-product01.php --check \
      --target="$HOME/www/openai.duasbyfk.pk/data-pilot-app"

Read-only `--check` must PASS with no unexpected customization; **ABORT**
is a hard stop. Never replace the whole SaaS folder, remove working auth
controllers, run `migrate:fresh`, or use a general application ZIP.

After independent approval, full DB backup and confirmed exact HEAD:

    php scripts/deploy-siteground-product01.php --apply \
      --backup-confirmed --expected-head=INDEPENDENTLY_APPROVED_40_CHAR_SHA \
      --target="$HOME/www/openai.duasbyfk.pk/data-pilot-app"

The installer records a private rollback directory, replaces only approved
files, copies new importer/controller/migration, runs ONLY `000008` and
clears caches. It does not edit private `.env`, Stripe, customer credentials,
Botble settings, public_html, legacy admin routes or existing products.

Enable the global switch only on the private staging server after code
and migration are verified:

    SAAS_CJ_DRAFT_IMPORT_ENABLED=true

Preserve existing `SAAS_CJ_PILOT_ENABLED` and
`SAAS_CJ_PILOT_WORKSPACE_ID` settings; do not hardcode private IDs into code.
Run `php artisan config:clear` after updating private .env.

## Independent QA

- Exact-head Laravel CI and installer workflow PASS.
- User owner, manually approved account, active workspace, working CJ
  connection, and role/pilot/plan import entitlement correctly checked.
- Real CJ endpoint called only after authentication and authorization.
- Live CJ response nested content is parsed and imported; only one request
  per minute is accepted, and CJ points consumed are understood.
- Repeated import idempotent; corrupted response creates NO partial writes.
- Other workspace, viewer, revoked/suspended accounts denied.
- Non-draft products untouched; pilot cap enforced; credentials never leak.
- Change token during remote response, then verify no stale products are saved.
- Botble incoming drafts remains 0 and product catalog unchanged.
- No verified live import means staging acceptance remains BLOCKED.

## Rollback

Stop imports, set `SAAS_CJ_DRAFT_IMPORT_ENABLED=false` and clear config.
Use the private rollback directory printed by the selective installer:
restore only backed-up replaced source files, delete only files listed
in `ADDED_FILES.txt`, clear Laravel caches. Leave added DB throttle
column in place to avoid destructive migration rollback. Private products
already fetched are NOT deleted automatically; any deliberate removal
must be separately approved and scoped.
