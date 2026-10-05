# Changelog

## 1.1.0 (2026-10-05)

- One-click connect: a "Connect with MetriXs" button on the plugin config
  page opens the MetriXs dashboard, where you create (or open) your account;
  the shop is added, connected, verified and tracking is enabled
  automatically. No API key copying. The manual API-key flow works unchanged.

## 1.0.1 (2026-10-05)

- Server-side `order_completed` commerce events: sent when a payment
  transaction enters "paid" (redirect payments like iDEAL captured), exactly
  once per order, with total, currency, order id, items and discount code.
  Revenue is normalized to EUR by the MetriXs API.
- Tracker cache-bust version follows TRACKER_VERSION (1.2.0).
- Body-shape fix for the /api/event ingest schema (n/u/d/p).

## 1.0.0 (2026-10-05)

- Initial release
- Opt-in tracker injection into the storefront head (off until connected AND
  enabled, double-gated)
- Automatic connect + verification handshake on settings save (5-minute
  throttle, one-time challenge token served from the shop's own domain)
- Challenge endpoint at `/mxcoan/challenge`
- Uninstall notification so ingestion stops on the MetriXs side
- Self-hosted Shopware 6.5 and 6.6 (Shopware Cloud not supported yet)
