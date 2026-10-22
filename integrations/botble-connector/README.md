# Data Pilot Botble Connector — Development Foundation (0.1.0)

This folder contains the initial **separate Botble plugin** scaffold. It is not a functioning product-import connector yet. For now it registers only a permission-protected admin information page.

## Planned distribution

When independently tested against supported Botble eCommerce installations, package the **contents of this folder** into the installation path `platform/plugins/datapilot-connector/` of a Botble site. Activate using the Botble admin plugin manager or documented artisan command.

**Do not install version 0.1.0 in production.** The real pairing API and CRUD services do not exist yet.

## Security contract for a later release

1. Merchant subscribes to Data Pilot via Stripe (webhook-verified workspace billing).
2. Merchant creates a dedicated Botble store connection within their SaaS workspace.
3. Botble admin proves control of the site by approving a single-use, short-lived pairing challenge in this plugin.
4. Pairing returns an authenticated, revocable **per-store** credential. Never share Botble's database password or write directly to the remote database.
5. All product commands use HTTPS + signed message/nonce/timestamp, strict JSON schema, rate limits, idempotency keys and a verified tenant/store mapping.
6. Product writes go through supported Botble Ecommerce models/services. Imported items are drafts until authorized publication. Store revocation denies further writes.
7. Reject requests targeting arbitrary URLs or internal IP addresses; validate remote image hosts/content; redact tokens and product-sensitive data in logs.
8. Every write requires Botble role permission, active paid SaaS entitlement and match between the pairing's store and workspace. Define a safe, audited revoke flow.

Official plugin structure: https://docs.botble.com/cms/plugin-development/structure.html

Implementation and integration tests will live in later issue(s). The plugin currently has **no remote write routes** by design.
